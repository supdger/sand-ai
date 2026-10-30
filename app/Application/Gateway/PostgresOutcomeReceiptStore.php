<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class PostgresOutcomeReceiptStore implements OutcomeReceiptStore
{
    public function createOrGet(array $record): array
    {
        return Db::connect('pgsql')->transaction(function () use ($record): array {
            $db = Db::connect('pgsql');
            $invocation = $db->query(
                'SELECT invocation.parent_workflow_run_id,dispatch.parent_task_run_id
                 FROM sand_ai_invocation invocation
                 LEFT JOIN sand_ai_invocation_dispatch dispatch
                   ON dispatch.invocation_id=invocation.id
                  AND dispatch.execution_scope=? AND dispatch.environment_id=invocation.environment_id
                 WHERE invocation.id=? AND invocation.environment_id=?',
                ['ai_task', $record['invocation_id'], $record['environment_id']],
            );
            if (count($invocation) !== 1) {
                throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Outcome receipt invocation is unavailable');
            }
            if ($record['capability_code'] === 'knowledge_answer') {
                // Serialize receipt writes with source deletion, including late
                // recovery attempts after conversation rows have been removed.
                $source = $db->query(
                    'SELECT base.id FROM sand_ai_invocation invocation
                     JOIN sand_ai_knowledge_publication publication
                       ON publication.id=invocation.knowledge_publication_id
                      AND publication.consumer_environment_id=invocation.environment_id
                     JOIN sand_ai_knowledge_base_version version
                       ON version.id=invocation.knowledge_base_version_id
                      AND version.knowledge_base_id=publication.knowledge_base_id
                      AND version.source_scope_sha256=invocation.knowledge_source_scope_sha256
                     JOIN sand_ai_knowledge_base base ON base.id=version.knowledge_base_id
                     WHERE invocation.id=? AND invocation.environment_id=?
                     FOR SHARE OF base',
                    [$record['invocation_id'], $record['environment_id']],
                );
                // Re-read after the base lock: a purge may have committed while
                // this writer was waiting. No copied plaintext may be restored.
                $remaining = $source === [] ? [] : $db->query(
                    'SELECT 1 FROM sand_ai_knowledge_base_source source
                     JOIN sand_ai_invocation invocation
                       ON invocation.knowledge_base_version_id=source.knowledge_base_version_id
                     WHERE invocation.id=? AND invocation.environment_id=? LIMIT 1',
                    [$record['invocation_id'], $record['environment_id']],
                );
                if (count($source) !== 1 || $remaining === []) {
                    throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Knowledge answer source content is unavailable');
                }
            }
            if ($invocation[0]['parent_workflow_run_id'] !== null) {
                $run = $db->query(
                    'SELECT content_purged_time FROM sand_ai_workflow_run
                     WHERE id=? AND environment_id=? FOR SHARE',
                    [(int) $invocation[0]['parent_workflow_run_id'], $record['environment_id']],
                );
                if (count($run) !== 1 || $run[0]['content_purged_time'] !== null) {
                    throw new ApiProblem('SAND_AI_WORKFLOW_CONTENT_PURGED', 'Workflow content has been purged');
                }
            }
            if ($invocation[0]['parent_task_run_id'] !== null) {
                $task = $db->query(
                    'SELECT content_purged_time FROM sand_ai_task_run
                     WHERE id=? AND environment_id=? FOR SHARE',
                    [(int) $invocation[0]['parent_task_run_id'], $record['environment_id']],
                );
                if (count($task) !== 1 || $task[0]['content_purged_time'] !== null) {
                    throw new ApiProblem('SAND_AI_AUDIO_CONTENT_PURGED', 'Audio content has been purged');
                }
            }
            if ($record['capability_code'] === 'skill_source_suggestion') {
                $owner = $db->query(
                    "SELECT id,state,sealed_at,content_purged_time FROM sand_ai_skill_source_suggestion
                     WHERE invocation_id=? AND environment_id=? FOR SHARE",
                    [$record['invocation_id'], $record['environment_id']],
                );
                if (count($owner) !== 1) {
                    throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Skill source suggestion receipt owner is unavailable');
                }
                if ($owner[0]['content_purged_time'] !== null) {
                    throw new ApiProblem('SAND_AI_SKILL_SOURCE_CONTENT_PURGED', 'Skill source suggestion content has been purged');
                }
                if ($owner[0]['state'] !== 'generating' || $owner[0]['sealed_at'] !== null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Skill source suggestion receipt owner is already sealed');
                }
            }
            $rows = $db->query(
                "INSERT INTO sand_ai_invocation_outcome_receipt (invocation_id, environment_id, capability_code, model_id, deployment_id, request_fingerprint, receipt_version, cipher_version, key_id, plaintext_bytes, ciphertext_bytes, content_hmac, nonce, tag, ciphertext, state, expire_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), 'available', ?) ON CONFLICT (invocation_id) DO NOTHING RETURNING id",
                [$record['invocation_id'], $record['environment_id'], $record['capability_code'], $record['model_id'], $record['deployment_id'], $record['request_fingerprint'], $record['receipt_version'], $record['cipher_version'], $record['key_id'], $record['plaintext_bytes'], $record['ciphertext_bytes'], bin2hex($record['content_hmac']), bin2hex($record['nonce']), bin2hex($record['tag']), bin2hex($record['ciphertext']), $record['expire_time']],
            );
            if ($rows !== []) {
                return ['created' => true, 'record' => $record];
            }
            $existing = $this->findByInvocationId((int) $record['invocation_id']);
            if ($existing === null || !$this->sameIdentity($existing, $record)) {
                throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Outcome receipt cannot be replaced');
            }
            return ['created' => false, 'record' => $existing];
        });
    }

    public function findByInvocationId(int $invocationId): ?array
    {
        $row = Db::connect('pgsql')->query('SELECT invocation_id, environment_id, capability_code, model_id, deployment_id, request_fingerprint, receipt_version, cipher_version, key_id, plaintext_bytes, ciphertext_bytes, content_hmac, nonce, tag, ciphertext, state, expire_time, consumer_ack_time, consumer_ref, retain_until FROM sand_ai_invocation_outcome_receipt WHERE invocation_id = ?', [$invocationId]);
        return $row === [] ? null : $this->normalizeBinaryColumns($row[0]);
    }

    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): bool
    {
        return $this->acknowledgeConsumer($invocationId, 'artifact:' . $artifactId, $acknowledgedAt, $retainUntil);
    }

    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool
    {
        return $this->acknowledgeConsumer($invocationId, 'file_result:' . $resultId, $acknowledgedAt, $retainUntil);
    }

    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool
    {
        return $this->acknowledgeConsumer($invocationId, 'cross_file_review:' . $resultId, $acknowledgedAt, $retainUntil);
    }

    public function acknowledgeSkillSourceSuggestion(int $invocationId, int $suggestionId, string $acknowledgedAt, string $retainUntil): bool
    {
        return Db::connect('pgsql')->transaction(function () use ($invocationId, $suggestionId, $acknowledgedAt, $retainUntil): bool {
            $db = Db::connect('pgsql');
            $owner = $db->query(
                "SELECT suggestion.environment_id FROM sand_ai_skill_source_suggestion suggestion
                 INNER JOIN sand_ai_invocation invocation ON invocation.id=suggestion.invocation_id
                   AND invocation.environment_id=suggestion.environment_id
                 WHERE suggestion.id=? AND suggestion.invocation_id=? AND suggestion.sealed_at IS NOT NULL
                   AND suggestion.state IN ('pending_review','adopted','failed') FOR SHARE OF suggestion",
                [$suggestionId, $invocationId],
            );
            if (count($owner) !== 1) {
                return false;
            }
            $consumerRef = 'skill_source_suggestion:' . $suggestionId;
            $rows = $db->query(
                "UPDATE sand_ai_invocation_outcome_receipt SET consumer_ack_time=COALESCE(consumer_ack_time,?),
                   consumer_ref=COALESCE(consumer_ref,?),
                   retain_until=CASE WHEN retain_until IS NULL OR retain_until<? THEN ? ELSE retain_until END,
                   update_time=CURRENT_TIMESTAMP
                 WHERE invocation_id=? AND environment_id=? AND capability_code='skill_source_suggestion'
                   AND state='available' AND (consumer_ref IS NULL OR consumer_ref=?)
                 RETURNING invocation_id",
                [$acknowledgedAt,$consumerRef,$retainUntil,$retainUntil,$invocationId,$owner[0]['environment_id'],$consumerRef],
            );
            return count($rows) === 1;
        });
    }

    public function renewPendingSkillSourceSuggestion(int $invocationId, int $suggestionId, string $expiresAt): bool
    {
        return Db::connect('pgsql')->transaction(function () use ($invocationId, $suggestionId, $expiresAt): bool {
            $db = Db::connect('pgsql');
            $owner = $db->query(
                "SELECT suggestion.environment_id FROM sand_ai_skill_source_suggestion suggestion
                 INNER JOIN sand_ai_invocation invocation ON invocation.id=suggestion.invocation_id
                   AND invocation.environment_id=suggestion.environment_id
                 WHERE suggestion.id=? AND suggestion.invocation_id=? AND suggestion.state='generating'
                   AND suggestion.sealed_at IS NULL AND suggestion.content_purged_time IS NULL FOR SHARE OF suggestion",
                [$suggestionId,$invocationId],
            );
            if (count($owner) !== 1) {
                return false;
            }
            $rows = $db->query(
                "UPDATE sand_ai_invocation_outcome_receipt SET expire_time=GREATEST(expire_time,?),update_time=CURRENT_TIMESTAMP
                 WHERE invocation_id=? AND environment_id=? AND capability_code='skill_source_suggestion'
                   AND state='available' AND consumer_ack_time IS NULL AND consumer_ref IS NULL
                 RETURNING invocation_id",
                [$expiresAt,$invocationId,$owner[0]['environment_id']],
            );
            return count($rows) === 1;
        });
    }

    public function findSkillSourceSuggestionForPurge(int $invocationId, int $suggestionId): ?array
    {
        $this->assertPurgeTransaction();
        $rows = Db::connect('pgsql')->query(
            "SELECT receipt.* FROM sand_ai_skill_source_suggestion suggestion
             INNER JOIN sand_ai_invocation invocation ON invocation.id=suggestion.invocation_id
               AND invocation.environment_id=suggestion.environment_id
             INNER JOIN sand_ai_invocation_outcome_receipt receipt ON receipt.invocation_id=invocation.id
             WHERE suggestion.id=? AND suggestion.invocation_id=? FOR UPDATE OF suggestion",
            [$suggestionId,$invocationId],
        );
        if ($rows === []) {
            return null;
        }
        $record = $rows[0];
        $invocation = $this->receiptInvocationIdentity($invocationId, $suggestionId);
        if ((string)$record['capability_code'] !== 'skill_source_suggestion'
            || (int)$record['environment_id'] !== (int)$invocation['environment_id']
            || (int)$record['model_id'] !== (int)$invocation['model_id']
            || (int)$record['deployment_id'] !== (int)$invocation['deployment_id']
            || !hash_equals((string)$record['request_fingerprint'], (string)$invocation['request_fingerprint'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt differs from its bound invocation');
        }
        return $this->normalizeBinaryColumns($record);
    }

    public function destroySkillSourceSuggestion(int $invocationId, int $suggestionId, string $purgedAt): bool
    {
        $this->assertPurgeTransaction();
        $invocation = $this->receiptInvocationIdentity($invocationId, $suggestionId);
        if ($invocation['content_purged_time'] === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion content is not purged');
        }
        $record = $this->findSkillSourceSuggestionForPurge($invocationId, $suggestionId);
        if ($record === null || (string)$record['state'] === 'destroyed') {
            return false;
        }
        $db = Db::connect('pgsql');
        // Preserve the established available -> expired -> destroyed transition
        // and advance its fence so any prior cleanup claimant becomes stale.
        $claimed = $db->query(
            "UPDATE sand_ai_invocation_outcome_receipt SET state='expired',cleanup_owner=?,cleanup_claim_time=?,
               cleanup_fence_version=cleanup_fence_version+1,update_time=CURRENT_TIMESTAMP
             WHERE invocation_id=? AND capability_code='skill_source_suggestion' AND state IN ('available','expired')
             RETURNING cleanup_fence_version",
            ['skill_source_suggestion:' . $suggestionId,$purgedAt,$invocationId],
        );
        if (count($claimed) !== 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt changed during purge');
        }
        $destroyed = $db->query(
            "UPDATE sand_ai_invocation_outcome_receipt SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,
               ciphertext_bytes=NULL,content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
               cleanup_owner=NULL,destroy_time=?,update_time=CURRENT_TIMESTAMP
             WHERE invocation_id=? AND capability_code='skill_source_suggestion' AND state='expired'
               AND cleanup_owner=? AND cleanup_fence_version=? RETURNING invocation_id",
            [$purgedAt,$invocationId,'skill_source_suggestion:' . $suggestionId,(int)$claimed[0]['cleanup_fence_version']],
        );
        if (count($destroyed) !== 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt could not be destroyed');
        }
        AuditLog::create(['actor_type'=>'system','actor_ref'=>'sand_ai_skill_source_content_purge',
            'action'=>'outcome_receipt.destroyed','resource_type'=>'invocation','resource_id'=>$invocationId,
            'summary'=>'Skill source suggestion receipt ciphertext destroyed',
            'context'=>['suggestion_id'=>$suggestionId,'state'=>'destroyed','reason'=>'content_purged']]);
        return true;
    }

    private function assertPurgeTransaction(): void
    {
        $connection = Db::connect('pgsql');
        $pdo = $connection instanceof \think\db\PDOConnection ? $connection->getPdo() : false;
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', 'Skill source suggestion receipt purge requires its owner transaction');
        }
    }

    /** @return array<string,mixed> */
    private function receiptInvocationIdentity(int $invocationId, int $suggestionId): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT invocation.environment_id,invocation.model_id,invocation.deployment_id,invocation.request_fingerprint,
               suggestion.content_purged_time FROM sand_ai_skill_source_suggestion suggestion
             INNER JOIN sand_ai_invocation invocation ON invocation.id=suggestion.invocation_id
               AND invocation.environment_id=suggestion.environment_id
             WHERE suggestion.id=? AND suggestion.invocation_id=? FOR UPDATE OF suggestion',
            [$suggestionId,$invocationId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt owner is unavailable');
        }
        return $rows[0];
    }

    private function acknowledgeConsumer(int $invocationId, string $consumerRef, string $acknowledgedAt, string $retainUntil): bool
    {
        $rows = Db::connect('pgsql')->query(
            "UPDATE sand_ai_invocation_outcome_receipt
             SET consumer_ack_time = COALESCE(consumer_ack_time, ?), consumer_ref = COALESCE(consumer_ref, ?),
                 retain_until = CASE WHEN retain_until IS NULL OR retain_until < ? THEN ? ELSE retain_until END,
                 update_time = CURRENT_TIMESTAMP
             WHERE invocation_id = ? AND state = 'available'
               AND expire_time > ?
               AND (consumer_ref IS NULL OR consumer_ref = ?)
             RETURNING invocation_id, consumer_ref",
            [$acknowledgedAt, $consumerRef, $retainUntil, $retainUntil, $invocationId, $acknowledgedAt, $consumerRef],
        );

        return $rows !== [] && hash_equals($consumerRef, (string) $rows[0]['consumer_ref']);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function normalizeBinaryColumns(array $record): array
    {
        foreach (['content_hmac', 'nonce', 'tag', 'ciphertext'] as $field) {
            if (is_resource($record[$field] ?? null)) {
                $bytes = stream_get_contents($record[$field]);
                if (!is_string($bytes)) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt binary material is unavailable');
                }
                $record[$field] = $bytes;
            }
        }

        return $record;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameIdentity(array $left, array $right): bool
    {
        foreach (['environment_id', 'capability_code', 'model_id', 'deployment_id', 'request_fingerprint', 'receipt_version', 'cipher_version', 'key_id', 'plaintext_bytes', 'ciphertext_bytes'] as $field) {
            if ((string) ($left[$field] ?? '') !== (string) ($right[$field] ?? '')) {
                return false;
            }
        }
        foreach (['content_hmac'] as $field) {
            if (!is_string($left[$field] ?? null) || !is_string($right[$field] ?? null) || !hash_equals($left[$field], $right[$field])) {
                return false;
            }
        }
        return true;
    }
}
