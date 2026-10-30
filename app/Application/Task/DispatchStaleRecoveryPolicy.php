<?php

declare(strict_types=1);

namespace app\Application\Task;

/**
 * A possibly-sent provider request is never an ordinary automatic retry.
 * Receipt-only local recovery remains a later database-backed B2 operation.
 */
final class DispatchStaleRecoveryPolicy
{
    /** @return array{state:string,error_code:string,manual_review:bool,provider_retry:bool} */
    public static function decide(?string $dispatchState, int $attemptCount, int $maxAttempts): array
    {
        if (in_array($dispatchState, ['dispatching', 'ambiguous', 'succeeded', 'failed'], true)) {
            return [
                'state' => $dispatchState === 'succeeded' ? TaskState::RECOVERY_PENDING : TaskState::FAILED,
                'error_code' => $dispatchState === 'succeeded' ? 'SAND_AI_LOCAL_RECOVERY_PENDING' : 'SAND_AI_DISPATCH_AMBIGUOUS',
                'manual_review' => true,
                'provider_retry' => false,
            ];
        }

        return [
            'state' => TaskAttemptPolicy::staleState($attemptCount, $maxAttempts),
            'error_code' => 'SAND_AI_TASK_WORKER_STALE',
            'manual_review' => false,
            'provider_retry' => false,
        ];
    }
}
