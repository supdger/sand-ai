<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use think\facade\Db;

/** Clears content for terminal workflow runs that used a deleted private file. */
final class WorkflowContentPurgeGateway
{
    /** @return array{runs:int,artifacts:int,file_results:int,cross_file_results:int,receipts:int} */
    public function purgeForFile(int $environmentId, int $fileId): array
    {
        $db = Db::connect('pgsql');
        $rows = $db->query(
            'SELECT r.id,r.task_id
             FROM sand_ai_workflow_run r
             JOIN sand_ai_workflow_run_file rf ON rf.workflow_run_id=r.id
             WHERE rf.file_id=? AND r.environment_id=?
             ORDER BY r.task_id,r.id',
            [$fileId, $environmentId],
        );
        $counts = ['runs' => 0, 'artifacts' => 0, 'file_results' => 0, 'cross_file_results' => 0, 'receipts' => 0];
        $taskIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['task_id'], $rows)));
        sort($taskIds, SORT_NUMERIC);
        foreach ($taskIds as $taskId) {
            try {
                $task = $db->query(
                    'SELECT state FROM sand_ai_task_run
                     WHERE id=? AND environment_id=? AND task_type=? FOR UPDATE NOWAIT',
                    [$taskId, $environmentId, 'structured_workflow'],
                );
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') {
                    throw $failure;
                }
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow is changing during file deletion');
            }
            if (count($task) !== 1 || !TaskState::terminal((string) $task[0]['state'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or cancel the workflow before deleting its file');
            }
        }
        foreach ($rows as $row) {
            $runId = (int) $row['id'];
            $run = $db->query(
                'SELECT task_id FROM sand_ai_workflow_run
                 WHERE id=? AND environment_id=? FOR UPDATE',
                [$runId, $environmentId],
            );
            if (count($run) !== 1 || (int) $run[0]['task_id'] !== (int) $row['task_id']) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow run changed during file deletion');
            }
            // A terminal task can still retain an ambiguous provider dispatch.
            // Preserve its private receipt until normal reconciliation settles
            // the invocation and its financial audit.
            $unsettled = $db->query(
                "SELECT 1 FROM sand_ai_invocation invocation
                 LEFT JOIN sand_ai_invocation_dispatch dispatch
                   ON dispatch.invocation_id=invocation.id
                 WHERE invocation.environment_id=? AND invocation.parent_workflow_run_id=?
                   AND (invocation.state NOT IN ('succeeded','failed')
                     OR dispatch.state IN ('prepared','dispatching','ambiguous'))
                 LIMIT 1",
                [$environmentId, $runId],
            );
            if ($unsettled !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Reconcile workflow invocations before deleting their source file');
            }
            $changed = $db->execute(
                "UPDATE sand_ai_workflow_run
                 SET content_purged_time=CURRENT_TIMESTAMP(0),execution_snapshot='{}'::jsonb,
                     update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND environment_id=? AND content_purged_time IS NULL",
                [$runId, $environmentId],
            );
            if ($changed === 1) {
                ++$counts['runs'];
                $step = \app\Infrastructure\Persistence\Model\TaskStep::where('task_id', (int) $row['task_id'])->where('sequence_no', 1)->lock(true)->find();
                if ($step !== null) {
                    $context = is_object($step->context) ? (array) $step->context : $step->context;
                    if (is_array($context) && is_array($context['material_cross_file_chunks'] ?? null)) {
                        foreach ($context['material_cross_file_chunks']['plan'] as &$batch) unset($batch['messages']);
                        unset($batch);
                        foreach ($context['material_cross_file_chunks']['nodes'] as &$node) {
                            unset($node['output_json']);
                            foreach ($node['attempts'] ?? [] as $attempt => $value) {
                                unset($value['output_json']);
                                $node['attempts'][$attempt] = $value;
                            }
                        }
                        unset($node);
                        $step->context = $context;
                        $step->save();
                    }
                    if (is_array($context) && is_array($context['material_chunks'] ?? null)) {
                        foreach ($context['material_chunks'] as &$journal) {
                            foreach ($journal['nodes'] as &$node) {
                                unset($node['output_json']);
                                foreach ($node['attempts'] ?? [] as $attempt => $value) {
                                    unset($value['output_json']);
                                    $node['attempts'][$attempt] = $value;
                                }
                            }
                            unset($node);
                        }
                        unset($journal);
                        $step->context = $context;
                        $step->save();
                    }
                }
                $counts['artifacts'] += $db->execute(
                    "UPDATE sand_ai_artifact
                     SET payload='{}'::jsonb,content_purged_time=CURRENT_TIMESTAMP(0),
                         update_time=CURRENT_TIMESTAMP(0)
                     WHERE workflow_run_id=? AND content_purged_time IS NULL",
                    [$runId],
                );
                $counts['file_results'] += $db->execute(
                    "UPDATE sand_ai_workflow_file_result result
                     SET payload='{}'::jsonb,content_purged_time=CURRENT_TIMESTAMP(0)
                     FROM sand_ai_workflow_run_file rf
                     WHERE result.workflow_run_file_id=rf.id AND rf.workflow_run_id=?
                       AND result.content_purged_time IS NULL",
                    [$runId],
                );
                $counts['cross_file_results'] += $db->execute(
                    "UPDATE sand_ai_workflow_cross_file_review_result
                     SET payload='{}'::jsonb,content_purged_time=CURRENT_TIMESTAMP(0)
                     WHERE workflow_run_id=? AND content_purged_time IS NULL",
                    [$runId],
                );
                $db->execute(
                    "UPDATE sand_ai_task_run SET result=NULL,update_time=CURRENT_TIMESTAMP(0)
                     WHERE id=(SELECT task_id FROM sand_ai_workflow_run WHERE id=?)
                       AND environment_id=? AND task_type='structured_workflow'",
                    [$runId, $environmentId],
                );
            }
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='expired',cleanup_owner='sand-ai-file-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,
                     update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='available' AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT id FROM sand_ai_invocation
                       WHERE parent_workflow_run_id=? AND environment_id=?
                   )",
                [$environmentId, $runId, $environmentId],
            );
            $counts['receipts'] += $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='expired' AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT id FROM sand_ai_invocation
                       WHERE parent_workflow_run_id=? AND environment_id=?
                   )",
                [$environmentId, $runId, $environmentId],
            );
            if ($changed === 1) {
                (new WorkflowStateRevision())->changed($runId);
            }
        }
        return $counts;
    }
}
