<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;

/** Pure attempt-limit decisions shared by claim, stale recovery, and manual retry. */
final class TaskAttemptPolicy
{
    public static function canClaim(int $attemptCount, int $maxAttempts): bool
    {
        self::assertCounters($attemptCount, $maxAttempts);
        return $attemptCount < $maxAttempts;
    }

    public static function staleState(int $attemptCount, int $maxAttempts): string
    {
        return self::canClaim($attemptCount, $maxAttempts) ? TaskState::RETRYING : TaskState::FAILED;
    }

    public static function manualRetryAllowed(int $attemptCount, int $maxAttempts): bool
    {
        return self::canClaim($attemptCount, $maxAttempts);
    }

    public static function explicitMaterialRetryLimit(int $attemptCount, int $maxAttempts): int
    {
        self::assertCounters($attemptCount, $maxAttempts);
        if ($attemptCount > $maxAttempts || $attemptCount >= 2147483647) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task attempt counters are invalid');
        }
        return $attemptCount === $maxAttempts ? $attemptCount + 1 : $maxAttempts;
    }

    private static function assertCounters(int $attemptCount, int $maxAttempts): void
    {
        if ($attemptCount < 0 || $maxAttempts < 1) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task attempt counters are invalid');
        }
    }
}
