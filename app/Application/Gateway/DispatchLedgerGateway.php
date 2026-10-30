<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use think\facade\Db;

/** Private ledger operations; public Chat never calls this gateway. */
final class DispatchLedgerGateway
{
    /** @param array<string,mixed> $record */
    public function prepare(array $record): InvocationDispatch
    {
        return InvocationDispatch::create($record + ['state' => 'prepared']);
    }

    public function dispatching(int $dispatchId, string $owner, string $token, int $fence): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId, $owner, $token, $fence): void {
            $ledger = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')
                ->where('lease_owner', $owner)->where('lease_token', $token)->where('lease_fence', $fence)->find();
            $invocation = $ledger === null ? null : Invocation::where('id', (int) $ledger->invocation_id)->find();
            if ($ledger === null || $invocation === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Dispatch lease was lost before provider call');
            }
            (new ProviderCallLimitGuard())->claim($invocation, $dispatchId);
            $updated = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')->where('lease_owner', $owner)->where('lease_token', $token)->where('lease_fence', $fence)->update(['state' => 'dispatching', 'dispatch_start_time' => date('Y-m-d H:i:s')]);
            if ($updated !== 1) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Dispatch lease was lost before provider call'); }
        });
    }

    public function isDispatching(int $dispatchId): bool
    {
        return InvocationDispatch::where('id', $dispatchId)->where('state', 'dispatching')->find() !== null;
    }

    public function notDispatched(int $dispatchId, string $owner, string $token, int $fence, string $errorCode, string $summary): void
    {
        $updated = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')
            ->where('lease_owner', $owner)->where('lease_token', $token)->where('lease_fence', $fence)
            ->update([
                'state' => 'not_dispatched', 'dispatch_complete_time' => date('Y-m-d H:i:s'),
                'error_code' => $errorCode, 'error_summary' => mb_strcut($summary, 0, 512, 'UTF-8'),
            ]);
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Dispatch lease was lost before provider call');
        }
    }

    public function rejectPrepared(int $dispatchId, Invocation $invocation, ?int $reservationId, InternalModelInvocationContext $context, string $errorCode, string $summary): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId, $invocation, $reservationId, $context, $errorCode, $summary): void {
            // The coordinator already holds the ledger row. Do not require the
            // old worker lease here: configuration mismatch must still be able
            // to close an un-dispatched prepared record safely.
            $changed = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')->update([
                'state' => 'not_dispatched', 'dispatch_complete_time' => date('Y-m-d H:i:s'),
                'error_code' => $errorCode, 'error_summary' => mb_strcut($summary, 0, 512, 'UTF-8'),
            ]);
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Prepared dispatch changed before rejection');
            }
            if ($reservationId !== null) {
                (new ChatBudgetGuard())->release($reservationId, 'dispatch_not_authorized');
            }
            $updated = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $errorCode, 'error_summary' => mb_strcut($summary, 0, 512, 'UTF-8'),
            ]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before prepared dispatch rejection');
            }
            AuditLog::create([
                'actor_type' => 'system_worker', 'actor_ref' => (string) $context->workflowRunId,
                'action' => 'model.dispatch.not_dispatched', 'resource_type' => 'invocation', 'resource_id' => $invocation->id,
                'summary' => 'Trusted provider dispatch was not sent', 'context' => ['error_code' => $errorCode],
            ]);
        });
    }

    public function terminal(int $dispatchId, string $state, ?string $errorCode = null, ?string $summary = null, ?InternalModelInvocationContext $context = null): void
    {
        DispatchStatePolicy::assertTransition('dispatching', $state);
        $query = InvocationDispatch::where('id', $dispatchId)->where('state', 'dispatching');
        if ($context !== null) {
            $query = $query->where('lease_owner', $context->lease->owner)
                ->where('lease_token', $context->lease->token)
                ->where('lease_fence', $context->lease->fence);
        }
        $updated = $query->update([
            'state' => $state, 'dispatch_complete_time' => date('Y-m-d H:i:s'), 'ambiguous_time' => $state === 'ambiguous' ? date('Y-m-d H:i:s') : null,
            'error_code' => $errorCode, 'error_summary' => $summary === null ? null : mb_strcut($summary, 0, 512, 'UTF-8'),
        ]);
        if ($updated !== 1) {
            throw new ApiProblem($context === null ? 'SAND_AI_DISPATCH_STATE_INVALID' : 'SAND_AI_TASK_LEASE_LOST', 'Dispatch state changed unexpectedly');
        }
    }
}
