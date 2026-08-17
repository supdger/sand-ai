<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\task;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\domain\task\AiTaskGateway;
use plugin\SandAi\app\domain\agent\AgentGateway;
use plugin\SandAi\app\domain\file\FileParseGateway;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\TaskRun;
use plugin\SandAi\app\model\TaskStep;
use Throwable;

/** SandAI execution state gateway; it is not a business approval engine. */
final class TaskGateway
{
    public function runOne(string $workerId): bool
    {
        $this->recoverStaleRuns();
        $task = $this->claimNext($workerId);
        if ($task === null) {
            return false;
        }

        try {
            $result = match ((string) $task->task_type) {
                'file_parse' => (new FileParseGateway())->executeTask($task),
                'ai_completion' => (new AiTaskGateway())->executeTask($task),
                'agent_run' => (new AgentGateway())->executeTask($task),
                default => throw new ApiProblem('SAND_AI_TASK_UNSUPPORTED', 'The queued task type is not supported'),
            };
            $this->succeed($task, $result);
        } catch (ApiProblem $problem) {
            $this->fail($task, $problem->errorCode, $problem->getMessage(), $problem->retryable);
        } catch (Throwable) {
            $this->fail($task, 'SAND_AI_TASK_FAILED', 'Task execution failed', true);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function read(int $environmentId, int $taskId): array
    {
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }

        return $this->summary($task, true);
    }

    /** @return array<string, mixed> */
    public function cancel(int $environmentId, int $taskId): array
    {
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if (TaskState::terminal((string) $task->state)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A completed task cannot be canceled');
        }

        $now = date('Y-m-d H:i:s');
        TaskRun::where('id', (int) $task->id)->whereIn('state', [TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING])->update([
            'state' => TaskState::CANCELED,
            'locked_at' => null,
            'locked_by' => null,
            'canceled_at' => $now,
            'completed_at' => $now,
        ]);
        TaskStep::where('task_id', (int) $task->id)->whereIn('state', [TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING])->update([
            'state' => TaskState::CANCELED,
            'completed_at' => $now,
        ]);

        $task = TaskRun::where('id', (int) $task->id)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        $this->audit($environmentId, 'task.cancel', (int) $task->id, 'Canceled SandAI task', ['task_type' => (string) $task->task_type]);

        return $this->summary($task, true);
    }

    /** @return array<string, mixed> */
    public function retry(int $environmentId, int $taskId): array
    {
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if ((string) $task->state !== TaskState::FAILED) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only a failed task can be retried');
        }
        $now = date('Y-m-d H:i:s');
        $updated = TaskRun::where('id', $taskId)->where('state', TaskState::FAILED)->update([
            'state' => TaskState::QUEUED,
            'available_at' => $now,
            'locked_at' => null,
            'locked_by' => null,
            'completed_at' => null,
            'error_code' => null,
            'error_summary' => null,
        ]);
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Task state changed before it could be retried');
        }
        TaskStep::where('task_id', $taskId)->where('state', TaskState::FAILED)->update([
            'state' => TaskState::QUEUED,
            'completed_at' => null,
            'error_code' => null,
            'error_summary' => null,
        ]);
        $task = TaskRun::where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        $this->audit($environmentId, 'task.retry_queued', $taskId, 'Requeued SandAI task', ['task_type' => (string) $task->task_type]);

        return $this->summary($task, true);
    }

    /** @return array<string, mixed> */
    public function summary(TaskRun $task, bool $withSteps = false): array
    {
        $result = [
            'id' => (int) $task->id,
            'task_type' => (string) $task->task_type,
            'resource_type' => (string) $task->resource_type,
            'resource_id' => (int) $task->resource_id,
            'file_parse_id' => $task->file_parse_id === null ? null : (int) $task->file_parse_id,
            'state' => (string) $task->state,
            'attempt_count' => (int) $task->attempt_count,
            'max_attempts' => (int) $task->max_attempts,
            'available_at' => $task->available_at,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'canceled_at' => $task->canceled_at,
            'error_code' => $task->error_code,
            'error_summary' => $task->error_summary,
        ];
        if ($withSteps) {
            $result['steps'] = TaskStep::where('task_id', (int) $task->id)->order('sequence_no')->select()->map(static fn (TaskStep $step): array => [
                'code' => (string) $step->step_code,
                'state' => (string) $step->state,
                'attempt_count' => (int) $step->attempt_count,
                'started_at' => $step->started_at,
                'completed_at' => $step->completed_at,
                'error_code' => $step->error_code,
                'error_summary' => $step->error_summary,
            ])->all();
            $result['result'] = $this->safeResult($task->result);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function safeResult(mixed $value): ?array
    {
        $value = is_object($value) ? (array) $value : $value;
        if (!is_array($value)) {
            return null;
        }
        $result = [];
        if (is_array($value['output'] ?? null) && is_string($value['output']['content'] ?? null)) {
            $result['output'] = ['content' => $value['output']['content']];
        }
        if (is_array($value['invocation'] ?? null)) {
            $result['invocation'] = array_intersect_key($value['invocation'], array_flip(['request_id', 'state', 'latency_ms', 'error_code']));
        }
        if (is_array($value['source_references'] ?? null)) {
            $result['source_references'] = array_values(array_filter(array_map(static fn (mixed $reference): ?array => is_array($reference)
                ? array_intersect_key($reference, array_flip(['source_block_id', 'file_id', 'locator_type', 'locator']))
                : null, $value['source_references'])));
        }
        if (is_array($value['tool_calls'] ?? null)) {
            $result['tool_calls'] = array_values(array_filter(array_map(static fn (mixed $toolCall): ?array => is_array($toolCall)
                ? array_intersect_key($toolCall, array_flip(['tool', 'source_block_ids']))
                : null, $value['tool_calls'])));
        }
        if (is_bool($value['review_required'] ?? null)) {
            $result['review_required'] = $value['review_required'];
        }

        return $result;
    }

    private function claimNext(string $workerId): ?TaskRun
    {
        $now = date('Y-m-d H:i:s');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = TaskRun::whereIn('state', TaskState::claimable())->where('available_at', '<=', $now)->where('status', 1)->order('available_at')->order('id')->find();
            if ($candidate === null) {
                return null;
            }
            $claimed = TaskRun::where('id', (int) $candidate->id)->whereIn('state', TaskState::claimable())->update([
                'state' => TaskState::RUNNING,
                'attempt_count' => (int) $candidate->attempt_count + 1,
                'locked_at' => $now,
                'locked_by' => $workerId,
                'started_at' => $candidate->started_at ?: $now,
                'error_code' => null,
                'error_summary' => null,
            ]);
            if ($claimed !== 1) {
                continue;
            }
            TaskStep::where('task_id', (int) $candidate->id)->where('sequence_no', 1)->whereIn('state', TaskState::claimable())->update([
                'state' => TaskState::RUNNING,
                'attempt_count' => (int) $candidate->attempt_count + 1,
                'started_at' => $now,
                'error_code' => null,
                'error_summary' => null,
            ]);

            return TaskRun::where('id', (int) $candidate->id)->find();
        }

        return null;
    }

    private function recoverStaleRuns(): void
    {
        $seconds = max(30, (int) config('plugin.sand-ai.task.running_stale_seconds', 300));
        $staleAt = date('Y-m-d H:i:s', time() - $seconds);
        $now = date('Y-m-d H:i:s');
        $staleTaskIds = TaskRun::where('state', TaskState::RUNNING)->where('locked_at', '<=', $staleAt)->column('id');
        if ($staleTaskIds === []) {
            return;
        }
        TaskRun::whereIn('id', $staleTaskIds)->where('locked_at', '<=', $staleAt)->update([
            'state' => TaskState::RETRYING,
            'available_at' => $now,
            'locked_at' => null,
            'locked_by' => null,
            'error_code' => 'SAND_AI_TASK_WORKER_STALE',
            'error_summary' => 'The worker did not finish the task before its lease expired',
        ]);
        TaskStep::whereIn('task_id', $staleTaskIds)->where('state', TaskState::RUNNING)->update([
            'state' => TaskState::RETRYING,
            'completed_at' => null,
            'error_code' => 'SAND_AI_TASK_WORKER_STALE',
            'error_summary' => 'The worker did not finish the task before its lease expired',
        ]);
    }

    /** @param array<string, mixed> $result */
    private function succeed(TaskRun $task, array $result): void
    {
        $now = date('Y-m-d H:i:s');
        $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)->update([
            'state' => TaskState::SUCCEEDED,
            'result' => $result,
            'locked_at' => null,
            'locked_by' => null,
            'completed_at' => $now,
        ]);
        if ($updated !== 1) {
            return;
        }
        TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
            'state' => TaskState::SUCCEEDED,
            'completed_at' => $now,
        ]);
        $this->audit((int) $task->environment_id, 'task.succeeded', (int) $task->id, 'SandAI task succeeded', ['task_type' => (string) $task->task_type]);
    }

    private function fail(TaskRun $task, string $errorCode, string $message, bool $retryable): void
    {
        $now = date('Y-m-d H:i:s');
        $attempts = (int) $task->attempt_count;
        $willRetry = $retryable && $attempts < max(1, (int) $task->max_attempts);
        $state = $willRetry ? TaskState::RETRYING : TaskState::FAILED;
        $values = [
            'state' => $state,
            'error_code' => $errorCode,
            'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
            'locked_at' => null,
            'locked_by' => null,
        ];
        if ($willRetry) {
            $values['available_at'] = date('Y-m-d H:i:s', time() + TaskState::retryDelaySeconds($attempts));
        } else {
            $values['completed_at'] = $now;
        }
        $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)->update($values);
        if ($updated !== 1) {
            return;
        }
        TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
            'state' => $state,
            'error_code' => $errorCode,
            'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
            'completed_at' => $willRetry ? null : $now,
        ]);
        $this->audit((int) $task->environment_id, $willRetry ? 'task.retrying' : 'task.failed', (int) $task->id, 'SandAI task did not complete', [
            'task_type' => (string) $task->task_type,
            'error_code' => $errorCode,
            'attempt_count' => $attempts,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $taskId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'system_worker',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'task',
            'resource_id' => $taskId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
