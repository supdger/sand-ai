<?php

declare(strict_types=1);

namespace app\Application\Budget;

/** Public domain seam for the conservative expiry transition. */
final class BudgetReservationExpiryPolicy
{
    /**
     * @param array<string,string> $window
     * @param array{state:string,estimate_amount:string,metered_amount:?string,currency:string} $reservation
     * @param null|array{state:string} $invocation
     * @return array{window:array<string,string>,reservation:array{state:string,metered_amount:null,reason:string},close_running_invocation:bool,preserve_running_invocation:bool}
     */
    public function expire(array $window, array $reservation, ?array $invocation): array
    {
        $transition = (new BudgetReservationStateMachine())->hold($window, $reservation, $reservation['currency']);

        return [
            'window' => $transition['window'],
            'reservation' => ['state' => 'held', 'metered_amount' => null, 'reason' => 'reservation_expired'],
            'close_running_invocation' => ($invocation['state'] ?? null) === 'running',
            'preserve_running_invocation' => ($invocation['state'] ?? null) === 'running',
        ];
    }
}
