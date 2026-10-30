<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AiTaskSourceFacts;
use app\Identity\Infrastructure\AgentSourceFacts;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Freezes files used by an agent prompt and clears its retained output on file deletion. */
final class AgentFileUsageGateway
{
    /** @param list<array<string,mixed>> $sources */
    public function record(TaskRun $task, UsageAttribution $attribution, array $sources): void
    {
        if ($sources === []) return;
        $references = [];
        foreach ($sources as $source) {
            $blockId = $source['source_block_id'] ?? null;
            $fileId = $source['file_id'] ?? null;
            if (!is_int($blockId) || $blockId < 1 || !is_int($fileId) || $fileId < 1
                || (isset($references[$blockId]) && $references[$blockId]['file_id'] !== $fileId)) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent source facts are unavailable');
            }
            $references[$blockId] = ['source_block_id' => $blockId, 'file_id' => $fileId];
        }
        $fileIds = array_values(array_unique(array_column($references, 'file_id')));
        sort($fileIds, SORT_NUMERIC);
        Db::connect('pgsql')->transaction(function () use ($task, $attribution, $references, $fileIds): void {
            foreach ($fileIds as $fileId) {
                $file = AiFile::where('id', $fileId)->where('environment_id', (int) $task->environment_id)->lock(true)->find();
                if ($file === null || (string) $file->state !== 'parsed'
                    || (int) $file->status !== 1 || $file->delete_time !== null) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent source file changed');
                }
            }
            $facts = (new AgentSourceFacts())->resolveFiles($task, array_keys($references), $attribution);
            AiTaskSourceFacts::assertMatches(array_values($references), $facts);
            foreach ($fileIds as $fileId) {
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_agent_run_file (task_id,environment_id,file_id)
                     VALUES (?,?,?) ON CONFLICT (task_id,file_id) DO NOTHING',
                    [(int) $task->id, (int) $task->environment_id, $fileId],
                );
            }
        });
    }

    public function assertRetryableSources(int $environmentId, int $taskId): void
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT file_id FROM sand_ai_agent_run_file
             WHERE task_id=? AND environment_id=? ORDER BY file_id',
            [$taskId, $environmentId],
        );
        foreach ($rows as $row) {
            $file = AiFile::where('id', (int) $row['file_id'])
                ->where('environment_id', $environmentId)->lock(true)->find();
            if ($file === null || (string) $file->state !== 'parsed'
                || (int) $file->status !== 1 || $file->delete_time !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent source file is unavailable for retry');
            }
        }
    }

    /** @return array{tasks:int,receipts:int} */
    public function purgeForFile(int $environmentId, int $fileId): array
    {
        $db = Db::connect('pgsql');
        $rows = $db->query(
            "SELECT DISTINCT t.id FROM sand_ai_task_run t
             LEFT JOIN sand_ai_agent_run_file usage ON usage.task_id=t.id AND usage.file_id=?
             WHERE t.environment_id=? AND t.task_type='agent_run'
               AND (usage.task_id IS NOT NULL
                    OR COALESCE(t.result->'source_references','[]'::jsonb) @> ?::jsonb)
             ORDER BY t.id",
            [$fileId, $environmentId, json_encode([['file_id' => $fileId]], JSON_THROW_ON_ERROR)],
        );
        $counts = ['tasks' => 0, 'receipts' => 0];
        foreach ($rows as $row) {
            $taskId = (int) $row['id'];
            try {
                // Retry locks the task before its source files. Deletion already
                // holds the file lock, so reject contention instead of reversing
                // that order and waiting for a deadlock.
                $locked = $db->query(
                    "SELECT state FROM sand_ai_task_run
                     WHERE id=? AND environment_id=? AND task_type='agent_run' FOR UPDATE NOWAIT",
                    [$taskId, $environmentId],
                );
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') throw $failure;
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent task is changing during file deletion');
            }
            if (count($locked) !== 1 || !TaskState::terminal((string) $locked[0]['state'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish the agent task before deleting its file');
            }
            $unsettled = $db->query(
                "SELECT 1 FROM sand_ai_invocation_dispatch dispatch
                 JOIN sand_ai_invocation invocation ON invocation.id=dispatch.invocation_id
                 WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                   AND dispatch.execution_scope='agent'
                   AND (invocation.state NOT IN ('succeeded','failed')
                     OR dispatch.state IN ('prepared','dispatching','ambiguous'))
                 LIMIT 1",
                [$environmentId, $taskId],
            );
            if ($unsettled !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Reconcile agent invocations before deleting their source file');
            }
            foreach (['sand_ai_feishu_delivery', 'sand_ai_wecom_delivery'] as $deliveryTable) {
                $delivery = $db->query(
                    "SELECT state FROM {$deliveryTable} WHERE task_id=? FOR UPDATE",
                    [$taskId],
                );
                if ($delivery !== [] && (string) $delivery[0]['state'] === 'sending') {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish channel delivery before deleting its source file');
                }
                $db->execute(
                    "UPDATE {$deliveryTable} SET state='failed',error_code='SAND_AI_SOURCE_UNVERIFIED',
                     lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0)
                     WHERE task_id=? AND state='pending'",
                    [$taskId],
                );
            }
            $db->execute('DELETE FROM sand_ai_agent_stream_event WHERE task_id=?', [$taskId]);
            $counts['tasks'] += $db->execute(
                "UPDATE sand_ai_task_run SET result=NULL,content_purged_time=CURRENT_TIMESTAMP(0),
                 update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND environment_id=? AND content_purged_time IS NULL",
                [$taskId, $environmentId],
            );
            // Agent rounds and history summaries retain private model outputs
            // independently of the task DTO and encrypted streaming events.
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='expired',cleanup_owner='sand-ai-agent-file-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state IN ('available','expired') AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='agent'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
            $counts['receipts'] += $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='expired' AND receipt.environment_id=?
                   AND receipt.cleanup_owner='sand-ai-agent-file-purge'
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='agent'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
        }
        return $counts;
    }
}
