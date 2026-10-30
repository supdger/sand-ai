<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use think\facade\Db;

/** Clears terminal recording results while preserving task, usage and audit identities. */
final class AudioContentPurgeGateway
{
    /** @return array{transcriptions:int,summaries:int,reviews:int,external_dispatches:int,external_receipts:int,model_receipts:int} */
    public function purgeForFile(int $environmentId, int $fileId): array
    {
        $db = Db::connect('pgsql');
        $tasks = $db->query(
            "SELECT id,task_type FROM sand_ai_task_run
             WHERE environment_id=?
               AND ((task_type='audio_transcription' AND input->>'file_id'=?)
                 OR (task_type='ai_completion' AND input #>> '{_meeting_summary,file_id}'=?))
             ORDER BY id",
            [$environmentId, (string) $fileId, (string) $fileId],
        );
        $counts = [
            'transcriptions' => 0, 'summaries' => 0, 'reviews' => 0,
            'external_dispatches' => 0, 'external_receipts' => 0, 'model_receipts' => 0,
        ];
        foreach ($tasks as $row) {
            $taskId = (int) $row['id'];
            try {
                // Deletion holds the file first; retry and worker paths lock
                // task first. Reject contention instead of reversing that order.
                $locked = $db->query(
                    'SELECT task_type,state FROM sand_ai_task_run WHERE id=? AND environment_id=? FOR UPDATE NOWAIT',
                    [$taskId, $environmentId],
                );
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') throw $failure;
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Audio task is changing during file deletion');
            }
            if (count($locked) !== 1 || (string) $locked[0]['task_type'] !== (string) $row['task_type']
                || !TaskState::terminal((string) $locked[0]['state'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or cancel audio tasks before deleting the file');
            }
            $audio = (string) $row['task_type'] === 'audio_transcription';
            if ($audio) {
                $unsettled = $db->query(
                    "SELECT 1 FROM sand_ai_external_audio_dispatch
                     WHERE environment_id=? AND task_run_id=?
                       AND state NOT IN ('succeeded','failed','not_dispatched')
                     LIMIT 1",
                    [$environmentId, $taskId],
                );
            } else {
                $unsettled = $db->query(
                    "SELECT 1 FROM sand_ai_invocation invocation
                     LEFT JOIN sand_ai_invocation_dispatch dispatch ON dispatch.invocation_id=invocation.id
                     WHERE invocation.environment_id=? AND invocation.parent_task_run_id=?
                       AND (invocation.state NOT IN ('succeeded','failed')
                         OR dispatch.state IN ('prepared','dispatching','ambiguous'))
                     LIMIT 1",
                    [$environmentId, $taskId],
                );
            }
            if ($unsettled !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Reconcile audio invocations before deleting their source file');
            }
            $changed = $db->execute(
                "UPDATE sand_ai_task_run
                 SET result=NULL,
                     input=CASE WHEN task_type='audio_transcription'
                         THEN jsonb_set(input,'{request,glossary}','[]'::jsonb,false)
                         ELSE input END,
                     content_purged_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND environment_id=? AND content_purged_time IS NULL",
                [$taskId, $environmentId],
            );
            if ($audio) {
                $counts['transcriptions'] += $changed;
                $counts['reviews'] += $db->execute(
                    "UPDATE sand_ai_audio_segment_review
                     SET review_text='',content_purged_time=CURRENT_TIMESTAMP(0)
                     WHERE audio_task_id=? AND content_purged_time IS NULL",
                    [$taskId],
                );
                $counts['external_dispatches'] += $db->execute(
                    "UPDATE sand_ai_external_audio_dispatch
                     SET content_purged_time=CURRENT_TIMESTAMP(0),
                         frozen_request=jsonb_set(
                             frozen_request,'{parameters,glossary}','[]'::jsonb,false),
                         terminal_snapshot=CASE WHEN terminal_snapshot IS NULL THEN NULL
                             ELSE '{\"content_purged\":true}'::jsonb END,
                         update_time=CURRENT_TIMESTAMP(0)
                     WHERE task_run_id=? AND environment_id=? AND content_purged_time IS NULL",
                    [$taskId, $environmentId],
                );
                $counts['external_receipts'] += $db->execute(
                    'UPDATE sand_ai_external_audio_receipt
                     SET key_id=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                         plaintext_bytes=NULL,content_hmac=NULL,
                         destroy_time=CURRENT_TIMESTAMP(0)
                     WHERE destroy_time IS NULL AND external_audio_dispatch_id IN (
                         SELECT id FROM sand_ai_external_audio_dispatch
                         WHERE task_run_id=? AND environment_id=? AND content_purged_time IS NOT NULL
                     )',
                    [$taskId, $environmentId],
                );
                continue;
            }
            $counts['summaries'] += $changed;
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='expired',cleanup_owner='sand-ai-audio-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,
                     update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='available' AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='ai_task'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
            $counts['model_receipts'] += $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='expired' AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='ai_task'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
        }
        return $counts;
    }
}
