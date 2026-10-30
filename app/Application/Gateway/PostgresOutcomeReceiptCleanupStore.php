<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class PostgresOutcomeReceiptCleanupStore implements OutcomeReceiptCleanupStore
{
    public function claimExpired(string $now, string $terminalGraceBefore, string $staleBefore, string $owner, int $limit): array
    {
        return Db::connect('pgsql')->transaction(function () use ($now, $terminalGraceBefore, $staleBefore, $owner, $limit): array {
            $rows = Db::connect('pgsql')->query(
                "SELECT receipt.id, receipt.environment_id, receipt.invocation_id
                 FROM sand_ai_invocation_outcome_receipt receipt
                 INNER JOIN sand_ai_invocation invocation ON invocation.id = receipt.invocation_id
                 WHERE ((receipt.state = 'available' AND receipt.expire_time <= ?)
                     OR (receipt.state = 'expired' AND receipt.cleanup_claim_time <= ?))
                   AND receipt.consumer_ack_time IS NOT NULL
                   AND receipt.retain_until IS NOT NULL
                   AND receipt.retain_until <= ?
                   AND " . self::sealedConsumerPredicate() . "
                   AND invocation.state = 'succeeded'
                   AND invocation.completed_at IS NOT NULL
                   AND invocation.completed_at <= ?
                 ORDER BY receipt.expire_time ASC, receipt.cleanup_claim_time ASC NULLS FIRST, receipt.id ASC
                 LIMIT ? FOR UPDATE OF receipt SKIP LOCKED",
                [$now, $staleBefore, $now, $now, $terminalGraceBefore, $limit],
            );
            $claimed = [];
            foreach ($rows as $row) {
                $claimedRows = Db::connect('pgsql')->query(
                    "UPDATE sand_ai_invocation_outcome_receipt
                     SET state = 'expired', cleanup_owner = ?, cleanup_claim_time = ?, cleanup_fence_version = cleanup_fence_version + 1, update_time = CURRENT_TIMESTAMP
                     WHERE id = ? AND state IN ('available', 'expired')
                     RETURNING id, environment_id, invocation_id, cleanup_fence_version",
                    [$owner, $now, (int) $row['id']],
                );
                if ($claimedRows !== []) {
                    $claimed[] = [
                        'id' => (int) $claimedRows[0]['id'],
                        'environment_id' => (int) $claimedRows[0]['environment_id'],
                        'invocation_id' => (int) $claimedRows[0]['invocation_id'],
                        'fence_version' => (int) $claimedRows[0]['cleanup_fence_version'],
                    ];
                }
            }
            return $claimed;
        });
    }

    public function destroyClaimedWithAudit(int $receiptId, string $owner, int $fenceVersion, string $destroyedAt): bool
    {
        return Db::connect('pgsql')->transaction(function () use ($receiptId, $owner, $fenceVersion, $destroyedAt): bool {
            $rows = Db::connect('pgsql')->query(
                "UPDATE sand_ai_invocation_outcome_receipt AS receipt
                 SET state = 'destroyed', key_id = NULL, plaintext_bytes = NULL, ciphertext_bytes = NULL,
                     content_hmac = NULL, nonce = NULL, tag = NULL, ciphertext = NULL,
                     cleanup_owner = NULL, destroy_time = ?, update_time = CURRENT_TIMESTAMP
                 WHERE receipt.id = ? AND receipt.state = 'expired' AND receipt.cleanup_owner = ? AND receipt.cleanup_fence_version = ?
                   AND receipt.consumer_ack_time IS NOT NULL AND receipt.consumer_ref IS NOT NULL
                   AND receipt.retain_until IS NOT NULL AND receipt.retain_until <= ?
                   AND EXISTS (
                       SELECT 1 FROM sand_ai_invocation invocation
                       WHERE invocation.id = receipt.invocation_id AND invocation.state = 'succeeded'
                         AND " . self::sealedConsumerPredicate() . "
                   )
                 RETURNING environment_id, invocation_id",
                [$destroyedAt, $receiptId, $owner, $fenceVersion, $destroyedAt, $destroyedAt],
            );
            if ($rows === []) {
                return false;
            }
            AuditLog::create([
                'actor_type' => 'system',
                'actor_ref' => 'sand_ai_outcome_receipt_cleanup',
                'action' => 'outcome_receipt.destroyed',
                'resource_type' => 'invocation',
                'resource_id' => (int) $rows[0]['invocation_id'],
                'summary' => 'Outcome receipt ciphertext destroyed',
                'context' => ['state' => 'destroyed', 'retention' => 'expired'],
            ]);
            return true;
        });
    }

    private static function sealedConsumerPredicate(): string
    {
        return "(EXISTS (
            SELECT 1 FROM sand_ai_workflow_run workflow
            INNER JOIN sand_ai_artifact artifact ON artifact.workflow_run_id = workflow.id
            WHERE workflow.last_invocation_id = invocation.id
              AND receipt.consumer_ref = 'artifact:' || artifact.id::text
              AND workflow.consumer_ack_ref = receipt.consumer_ref
              AND workflow.consumer_ack_time IS NOT NULL
              AND workflow.retain_until IS NOT NULL
              AND workflow.retain_until <= ?
              AND workflow.status = 1 AND workflow.delete_time IS NULL
              AND artifact.state = 'succeeded' AND artifact.status = 1 AND artifact.delete_time IS NULL
        ) OR EXISTS (
            SELECT 1 FROM sand_ai_workflow_file_result file_result
            INNER JOIN sand_ai_workflow_run_file run_file ON run_file.id = file_result.workflow_run_file_id
            INNER JOIN sand_ai_workflow_run workflow ON workflow.id = run_file.workflow_run_id
            WHERE file_result.invocation_id = invocation.id
              AND receipt.consumer_ref = 'file_result:' || file_result.id::text
              AND run_file.last_invocation_id = invocation.id
              AND run_file.analysis_state = 'succeeded'
              AND run_file.attempt_count = file_result.attempt_no
              AND run_file.status = 1 AND run_file.delete_time IS NULL
              AND file_result.execution_snapshot_sha256 = workflow.execution_snapshot_sha256
              AND workflow.status = 1 AND workflow.delete_time IS NULL
        ) OR EXISTS (
            SELECT 1 FROM sand_ai_workflow_cross_file_review_result review_result
            INNER JOIN sand_ai_workflow_cross_file_review review
                ON review.workflow_run_id = review_result.workflow_run_id
            INNER JOIN sand_ai_workflow_run workflow ON workflow.id = review.workflow_run_id
            WHERE review_result.invocation_id = invocation.id
              AND receipt.consumer_ref = 'cross_file_review:' || review_result.id::text
              AND review.last_invocation_id = invocation.id
              AND review.state = 'succeeded'
              AND review.attempt_count = review_result.attempt_no
              AND review_result.execution_snapshot_sha256 = workflow.execution_snapshot_sha256
              AND workflow.status = 1 AND workflow.delete_time IS NULL
        ) OR EXISTS (
            SELECT 1 FROM sand_ai_skill_source_suggestion suggestion
            WHERE receipt.capability_code = 'skill_source_suggestion'
              AND receipt.consumer_ref = 'skill_source_suggestion:' || suggestion.id::text
              AND suggestion.invocation_id = invocation.id
              AND suggestion.environment_id = invocation.environment_id
              AND suggestion.sealed_at IS NOT NULL
              AND suggestion.state IN ('pending_review', 'adopted', 'failed')
        ))";
    }
}
