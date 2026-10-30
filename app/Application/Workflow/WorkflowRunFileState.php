<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;

final class WorkflowRunFileState
{
    /** @return array{analysis_state:string,failure_summary:?string} */
    public static function forTask(string $taskState, ?string $errorCode = null): array
    {
        $state = match ($taskState) {
            TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED => 'pending',
            TaskState::RUNNING, TaskState::RETRYING, TaskState::RECOVERY_PENDING,
            TaskState::SUCCEEDED, TaskState::FAILED, TaskState::CANCELED => $taskState,
            default => throw new ApiProblem('SAND_AI_TASK_INVALID', 'Workflow task state is invalid'),
        };
        return [
            'analysis_state' => $state,
            'failure_summary' => in_array($state, ['retrying', 'recovery_pending', 'failed'], true)
                ? self::safeErrorCode($errorCode)
                : null,
        ];
    }

    /**
     * A parent task cannot erase a separately committed file result.
     *
     * @param array{analysis_state:string,attempt_count:int,last_invocation_id:?int,failure_summary?:?string} $file
     * @param null|array{invocation_id:int,attempt_no:int} $result
     * @return null|array{analysis_state:string,failure_summary:?string,attempt_count?:int}
     */
    public static function forParentTask(
        array $file,
        ?array $result,
        string $taskState,
        ?string $errorCode = null,
        ?int $attemptCount = null,
        bool $hasFileResults = false,
        bool $singleFileExecution = false,
    ): ?array {
        if ($result !== null) {
            if ($file['analysis_state'] !== 'succeeded'
                || $file['last_invocation_id'] !== $result['invocation_id']
                || $file['attempt_count'] !== $result['attempt_no']) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A completed workflow file has inconsistent result evidence');
            }
            return null;
        }
        if ($file['analysis_state'] === 'succeeded'
            || ($hasFileResults && $taskState === TaskState::SUCCEEDED)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow file success has no result evidence');
        }
        // A separately recorded failure is retried only after explicit queueing.
        // Parent recovery or another file's retry must keep its original attempt.
        if ($singleFileExecution && $file['analysis_state'] === 'failed'
            && in_array($file['failure_summary'] ?? null, [
                'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE', 'SAND_AI_PROVIDER_REJECTED', 'SAND_AI_BUDGET_EXCEEDED',
                'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
            ], true)
            && in_array($taskState, [
                TaskState::RUNNING, TaskState::RETRYING, TaskState::RECOVERY_PENDING, TaskState::FAILED,
            ], true)) {
            return null;
        }
        $values = self::forTask($taskState, $errorCode);
        if ($attemptCount !== null) {
            if ($attemptCount < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow file attempt is invalid');
            }
            $values['attempt_count'] = $attemptCount;
        }
        return $values;
    }

    /** @return array{file_id:int,analysis_state:string,attempt_count:int,error_code:?string} */
    public static function present(int $fileId, string $state, int $attemptCount, ?string $failureSummary): array
    {
        if ($fileId < 1 || $attemptCount < 0 || !in_array($state, [
            'pending', 'running', 'retrying', 'recovery_pending', 'succeeded', 'failed', 'canceled',
        ], true)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow file state is invalid');
        }
        return [
            'file_id' => $fileId,
            'analysis_state' => $state,
            'attempt_count' => $attemptCount,
            'error_code' => in_array($state, ['retrying', 'recovery_pending', 'failed'], true)
                ? self::safeErrorCode($failureSummary)
                : null,
        ];
    }

    private static function safeErrorCode(?string $value): ?string
    {
        return is_string($value) && preg_match('/^SAND_AI_[A-Z0-9_]{1,96}$/D', $value) === 1
            ? $value
            : null;
    }
}
