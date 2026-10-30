<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** A receipt opt-in available only to a leased meeting-summary task node. */
final class MeetingSummaryReceiptScope
{
    public static function assertCurrent(
        DispatchExecutionScope $scope,
        int $environmentId,
        string $requestId,
        bool $existing,
    ): void {
        if ($scope->scope !== 'ai_task' || $scope->taskRunId === null
            || $scope->taskStepId === null || $scope->logicalNode === null
            || preg_match('/\A(?:chunk_[0-9]{5}|merge_[0-9]{2}_[0-9]{5})\z/D', $scope->logicalNode) !== 1
            || $requestId !== sprintf('task_%d_%s_attempt_%d', $scope->taskRunId, $scope->logicalNode, $scope->attemptNo)) {
            throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Meeting receipt requires a task node');
        }
        $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $environmentId)->find();
        $step = TaskStep::where('id', $scope->taskStepId)->where('task_id', $scope->taskRunId)->find();
        $input = $task?->input;
        $meeting = is_array($input) ? ($input['_meeting_summary'] ?? null) : null;
        if ($task === null || (string) $task->task_type !== 'ai_completion'
            || !is_array($meeting) || !is_int($meeting['audio_task_id'] ?? null)
            || (int) $meeting['audio_task_id'] < 1
            || $task->content_purged_time !== null
            || $step === null || (string) $step->step_code !== $scope->logicalNode
            || (int) $step->attempt_count !== $scope->attemptNo
            || (string) $task->lease_owner !== $scope->leaseOwner
            || (string) $task->lease_token !== $scope->leaseToken
            || (int) $task->lease_fence !== $scope->leaseFence
            || !in_array((string) $task->state, $existing
                ? [TaskState::RUNNING, TaskState::RECOVERY_PENDING]
                : [TaskState::RUNNING], true)) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting receipt task lease or node is unavailable');
        }
        $audio = TaskRun::where('id', (int) $meeting['audio_task_id'])
            ->where('environment_id', $environmentId)->find();
        if ($audio === null || (string) $audio->task_type !== 'audio_transcription'
            || $audio->content_purged_time !== null) {
            throw new ApiProblem('SAND_AI_AUDIO_CONTENT_PURGED', 'Meeting source is unavailable');
        }
        if (!$existing && (string) $step->state !== TaskState::RUNNING) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting node is not running');
        }
        if ($existing && !in_array((string) $step->state, [TaskState::RUNNING, TaskState::FAILED, TaskState::SUCCEEDED], true)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting node cannot recover this receipt');
        }
        $node = Db::connect('pgsql')->query(
            'SELECT task_id,task_step_id,node_code,dispatch_scope,result_snapshot
               FROM sand_ai_meeting_summary_node WHERE task_id=? AND task_step_id=? AND node_code=?',
            [$scope->taskRunId, $scope->taskStepId, $scope->logicalNode],
        )[0] ?? null;
        if (!is_array($node) || (string) $node['dispatch_scope'] !== 'ai_task'
            || ((!$existing || (string) $task->state === TaskState::RECOVERY_PENDING)
                && $node['result_snapshot'] !== null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting receipt node is unavailable');
        }
    }

    public static function assertExisting(
        Invocation $invocation,
        InvocationDispatch $dispatch,
        DispatchExecutionScope $scope,
        string $requestId,
        string $requestFingerprint,
    ): void {
        if ((int) $dispatch->invocation_id !== (int) $invocation->id
            || (string) $dispatch->execution_scope !== 'ai_task'
            || (string) $dispatch->capability_code !== 'chat'
            || (int) $dispatch->parent_task_run_id !== $scope->taskRunId
            || (int) $dispatch->parent_task_step_id !== $scope->taskStepId
            || (string) $dispatch->workflow_node_code !== $scope->logicalNode
            || (int) $dispatch->attempt_no !== $scope->attemptNo
            || (string) $dispatch->logical_execution_id !== $scope->logicalExecutionId
            || (string) $dispatch->execution_snapshot_sha256 !== $scope->executionSnapshotSha256
            || (string) $dispatch->logical_request_id !== hash('sha256', $requestId)
            || !is_string($invocation->request_fingerprint)
            || !hash_equals($invocation->request_fingerprint, $requestFingerprint)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting receipt differs from its task dispatch');
        }
    }
}
