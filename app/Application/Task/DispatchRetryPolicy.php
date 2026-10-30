<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;

/** A provider-visible dispatch is never eligible for another provider POST. */
final class DispatchRetryPolicy
{
    /** @return list<string> */
    public static function providerVisibleStates(): array
    {
        // `failed` is reached only from a dispatching ledger record.  A
        // deterministic provider rejection is still provider-visible: sending
        // another POST would turn a terminal provider outcome into a duplicate
        // business operation.
        return ['dispatching', 'ambiguous', 'succeeded', 'failed', TaskState::RECOVERY_PENDING];
    }

    public static function assertRetryable(?string $dispatchState): void
    {
        if (in_array($dispatchState, self::providerVisibleStates(), true)) {
            throw new ApiProblem('SAND_AI_DISPATCH_RETRY_FORBIDDEN', 'This task has a provider-visible dispatch and requires recovery or review');
        }
    }
}
