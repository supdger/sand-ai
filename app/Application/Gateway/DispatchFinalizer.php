<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;

/**
 * The single finalization boundary. Callers acquire state in this order:
 * all budget windows, reservations, invocation, ledger, then receipt/usage/audit.
 */
final class DispatchFinalizer
{
    public function __construct(private readonly DispatchFinalizationStore $store = new PostgresDispatchFinalizationStore())
    {
    }

    /** @template T @param callable():T $write @return T */
    public function success(callable $write): mixed
    {
        return $this->store->finalize($write);
    }

    /** @template T @param callable():T $write @return T */
    public function ambiguous(callable $write): mixed
    {
        return $this->store->finalize($write);
    }

    /**
     * Must run inside success()/ambiguous().  This is the actual lock order for
     * dispatch terminal work. Every provider path now has a ledger; the
     * Coordinator selects the appropriate trusted scope before this lock.
     * ChatBudgetGuard locks every window before every reservation.
     *
     * @return array{completion:array{expired:bool,state:string,price_revision_id:int},invocation:Invocation,dispatch:InvocationDispatch}
     */
    public function lockInternalTerminal(?int $reservationId, int $invocationId, int $dispatchId): array
    {
        $completion = $reservationId === null
            ? ['expired' => false, 'state' => 'unbudgeted', 'price_revision_id' => 0]
            : $this->lockCompletionForInvocation($reservationId, $invocationId);
        $invocation = Invocation::where('id', $invocationId)->where('state', 'running')->lock(true)->find();
        $dispatch = InvocationDispatch::where('id', $dispatchId)->where('invocation_id', $invocationId)->lock(true)->find();
        if ($invocation === null || $dispatch === null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Dispatch terminal records changed before finalization');
        }
        return ['completion' => $completion, 'invocation' => $invocation, 'dispatch' => $dispatch];
    }

    /** @return array{invocation:Invocation,dispatch:InvocationDispatch} */
    public function lockKnowledgeTerminal(?int $reservationId, int $invocationId, int $dispatchId): array
    {
        if ($reservationId !== null) {
            $this->lockCompletionForInvocation($reservationId, $invocationId);
        }
        $invocation = Invocation::where('id', $invocationId)->lock(true)->find();
        $dispatch = InvocationDispatch::where('id', $dispatchId)->where('invocation_id', $invocationId)->lock(true)->find();
        if ($invocation === null || $dispatch === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge dispatch terminal records are unavailable');
        }
        return ['invocation' => $invocation, 'dispatch' => $dispatch];
    }

    /** @return array{expired:bool,state:string,price_revision_id:int} */
    private function lockCompletionForInvocation(int $reservationId, int $invocationId): array
    {
        $guard = new ChatBudgetGuard();
        foreach ($guard->lockReservationGroup($reservationId) as $member) {
            if ((int) $member['reservation']->invocation_id !== $invocationId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Budget reservation group belongs to another invocation');
            }
        }
        return $guard->lockForCompletion($reservationId);
    }

    /** @return list<string> */
    public static function lockOrder(): array
    {
        return ['budget_window', 'budget_reservation', 'invocation', 'invocation_dispatch', 'receipt_usage_audit'];
    }

    /**
     * @param array{state:string} $invocation
     * @return array{state:string,error_code:null,error_summary:null}|null
     */
    public static function lateMeasuredSuccessMutation(array $invocation): ?array
    {
        if ($invocation['state'] !== 'running') {
            return null;
        }

        // The expiry marker is diagnostic while running only. A measured late
        // success is authoritative and must not expose stale failure evidence.
        return ['state' => 'succeeded', 'error_code' => null, 'error_summary' => null];
    }
}
