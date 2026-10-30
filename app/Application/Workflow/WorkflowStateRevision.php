<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Call at the end of an existing workflow business transaction, after every visible child write. */
final class WorkflowStateRevision
{
    public function changed(int $runId): int
    {
        if ($runId < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow revision requires a run');
        }
        $db = Db::connect('pgsql');
        $rows = $db->query(
            'UPDATE sand_ai_workflow_run SET state_version = state_version + 1, '
            . 'state_revision_txid = txid_current() WHERE id = ? '
            . 'AND state_revision_txid IS DISTINCT FROM txid_current() RETURNING state_version',
            [$runId],
        );
        if (isset($rows[0]['state_version'])) {
            return (int) $rows[0]['state_version'];
        }
        $current = $db->query('SELECT state_version, state_revision_txid, txid_current() AS current_txid '
            . 'FROM sand_ai_workflow_run WHERE id = ?', [$runId]);
        if (!isset($current[0]) || (string) $current[0]['state_revision_txid'] !== (string) $current[0]['current_txid']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow revision is unavailable');
        }
        return (int) $current[0]['state_version'];
    }

    /** Caller must have made the task terminal in this transaction. */
    public function terminalChanged(int $runId, string $state): int
    {
        if (!in_array($state, ['succeeded', 'failed', 'canceled'], true)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow terminal state is invalid');
        }
        $version = $this->changed($runId);
        $db = Db::connect('pgsql');
        $rows = $db->query(
            'SELECT task.state '
            . 'FROM sand_ai_workflow_run AS run JOIN sand_ai_task_run AS task ON task.id = run.task_id '
            . 'WHERE run.id = ? FOR UPDATE OF run', [$runId],
        );
        if (!isset($rows[0]) || $rows[0]['state'] !== $state) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow terminal task does not match revision');
        }
        // AI state changes remain observable through the existing read/polling API.
        // Business workflow handoff is an explicit consumer operation.
        return $version;
    }
}
