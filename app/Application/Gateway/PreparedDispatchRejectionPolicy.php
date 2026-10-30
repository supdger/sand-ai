<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;

/** Called only after the locked ledger has become not_dispatched with no send-start evidence. */
final class PreparedDispatchRejectionPolicy
{
    public const NO_OP = 'no_op';
    public const RELEASE = 'release';

    public static function budgetAction(?BudgetReservation $reservation, ?BudgetWindow $window, int $invocationId): string
    {
        if ($reservation === null || $window === null
            || (int) $reservation->policy_id !== (int) $window->policy_id
            || (int) $reservation->invocation_id !== $invocationId
            || !in_array((string) $reservation->state, ['reserved', 'held'], true)) {
            return self::NO_OP;
        }

        // Expiry alone is uncertainty about sending. The durable not-dispatched
        // transition resolves it. Other hold reasons retain their uncertainty.
        if ((string) $reservation->state === 'held'
            && (string) $reservation->reason !== 'reservation_expired') {
            return self::NO_OP;
        }

        return self::RELEASE;
    }

    public static function ledgerBelongsToInvocation(int $ledgerInvocationId, int $invocationId): bool
    {
        return $ledgerInvocationId === $invocationId;
    }
}
