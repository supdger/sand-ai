<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Gateway\DispatchLedgerGateway;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\PreparedDispatchRejectionPolicy;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;

/** Original task cancellation owns this transaction; it never creates a worker lease or a dispatch. */
final class TaskPreparedDispatchCancellation
{
    /**
     * @param array<int,Invocation> $invocations
     * @param array<int,InvocationDispatch> $dispatches
     * @param list<int> $reservationIds
     */
    private function __construct(
        private readonly array $invocations,
        private readonly array $dispatches,
        private readonly array $reservationIds,
    ) {
    }

    /** Must precede the task lock: all windows, reservations, invocations, then dispatches. */
    public static function lockBeforeTask(TaskRun $task): self
    {
        $probes = self::preparedForTask($task)->order('id')->select();
        $dispatchIds = [];
        $invocationIds = [];
        foreach ($probes as $probe) {
            $dispatchIds[] = (int) $probe->id;
            $invocationIds[] = (int) $probe->invocation_id;
        }
        if ($dispatchIds === []) return new self([], [], []);
        $invocationIds = array_values(array_unique($invocationIds));
        sort($invocationIds, SORT_NUMERIC);

        $reservationProbes = BudgetReservation::whereIn('invocation_id', $invocationIds)
            ->order('policy_id')->order('id')->select();
        $policyIds = [];
        $reservationIds = [];
        foreach ($reservationProbes as $reservation) {
            $policyIds[] = (int) $reservation->policy_id;
            $reservationIds[] = (int) $reservation->id;
        }
        $policyIds = array_values(array_unique($policyIds));
        sort($policyIds, SORT_NUMERIC);
        if ($policyIds !== []) {
            $windows = BudgetWindow::whereIn('policy_id', $policyIds)->order('policy_id')->lock(true)->select();
            if (count($windows) !== count($policyIds)) {
                throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Task cancellation budget group is incomplete');
            }
            $reservations = BudgetReservation::whereIn('id', $reservationIds)
                ->order('policy_id')->order('id')->lock(true)->select();
            if (count($reservations) !== count($reservationIds)) {
                throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_CHANGED', 'Task cancellation budget group changed');
            }
        }
        $invocations = [];
        foreach (Invocation::whereIn('id', $invocationIds)->order('id')->lock(true)->select() as $invocation) {
            $invocations[(int) $invocation->id] = $invocation;
        }
        $dispatches = [];
        foreach (InvocationDispatch::whereIn('id', $dispatchIds)->order('id')->lock(true)->select() as $dispatch) {
            $dispatches[(int) $dispatch->id] = $dispatch;
        }
        if (count($invocations) !== count($invocationIds) || count($dispatches) !== count($dispatchIds)) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task cancellation provider records changed');
        }
        sort($reservationIds, SORT_NUMERIC);
        return new self($invocations, $dispatches, $reservationIds);
    }

    /** Called after the canonical task is locked and before its lease is canceled. */
    public function closeUnsent(TaskRun $task, string $now): void
    {
        // A prepare committed between the probe and task lock must be retried
        // with its own group locked first, never locked in reverse order here.
        foreach (self::preparedForTask($task)->column('id') as $id) {
            if (!isset($this->dispatches[(int) $id])) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A new provider preparation appeared before cancellation');
            }
        }
        if ($this->dispatches === []) return;
        $currentReservationIds = array_map('intval', BudgetReservation::whereIn('invocation_id', array_keys($this->invocations))->column('id'));
        sort($currentReservationIds, SORT_NUMERIC);
        if ($currentReservationIds !== $this->reservationIds) {
            throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_CHANGED', 'Task cancellation reservation membership changed');
        }
        $steps = [];
        foreach (TaskStep::where('task_id', (int) $task->id)->order('id')->lock(true)->select() as $step) {
            $steps[(int) $step->id] = $step;
        }
        foreach ($this->dispatches as $dispatch) {
            $invocation = $this->invocations[(int) $dispatch->invocation_id] ?? null;
            $step = $steps[(int) $dispatch->parent_task_step_id] ?? null;
            // A concurrent claim that won the lock remains charged/unknown.
            if ((string) $dispatch->state !== 'prepared' || $dispatch->dispatch_start_time !== null
                || $invocation === null || (string) $invocation->state !== 'running') continue;
            $this->assertFrozenScope($task, $step, $invocation, $dispatch);
            if (UsageEvent::where('invocation_id', (int) $invocation->id)->find() !== null
                || $this->hasOtherProviderEvidence($dispatch)) continue;

            $anchor = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id')->order('id')->find();
            $guard = new ChatBudgetGuard();
            $group = $anchor === null ? [] : $guard->lockReservationGroup((int) $anchor->id);
            $releasable = true;
            foreach ($group as $member) {
                $reservation = $member['reservation'];
                if ((int) $reservation->invocation_id !== (int) $invocation->id
                    || (string) $reservation->request_id !== (string) $invocation->request_id
                    || !is_string($reservation->request_fingerprint)
                    || !hash_equals($reservation->request_fingerprint, (string) $invocation->request_fingerprint)
                    || (int) $reservation->price_revision_id !== (int) $invocation->call_start_price_revision_id) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation frozen reservation differs from its invocation');
                }
                if (PreparedDispatchRejectionPolicy::budgetAction($reservation, $member['window'], (int) $invocation->id)
                    !== PreparedDispatchRejectionPolicy::RELEASE) $releasable = false;
            }
            // A non-expiry hold or terminal accounting is not zero-send proof.
            if (!$releasable) continue;

            // The stored original lease is only a CAS value. No worker scope is
            // signed/rebound, and the held ledger lock forbids a concurrent send.
            (new DispatchLedgerGateway())->notDispatched(
                (int) $dispatch->id, (string) $dispatch->lease_owner,
                (string) $dispatch->lease_token, (int) $dispatch->lease_fence,
                'SAND_AI_TASK_LEASE_LOST', 'Task canceled before provider dispatch',
            );
            if ($anchor !== null) $guard->release((int) $anchor->id, 'dispatch_not_dispatched');
            $changed = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => 'failed', 'completed_at' => $now, 'error_code' => 'SAND_AI_TASK_LEASE_LOST',
                'error_summary' => 'Task canceled before provider dispatch',
            ]);
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed during task cancellation');
            }
            AuditLog::create([
                'actor_type' => 'api_credential', 'actor_ref' => (string) $task->environment_id,
                'action' => 'model.dispatch.not_dispatched', 'resource_type' => 'invocation',
                'resource_id' => (int) $invocation->id, 'summary' => 'Task canceled before provider dispatch',
                'context' => ['task_id' => (int) $task->id, 'error_code' => 'SAND_AI_TASK_LEASE_LOST'],
            ]);
        }
    }

    private static function preparedForTask(TaskRun $task): \think\db\BaseQuery
    {
        return InvocationDispatch::where('parent_task_run_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)
            ->whereIn('execution_scope', ['ai_task', 'agent', 'structured_workflow'])
            ->where('state', 'prepared')->whereNull('dispatch_start_time');
    }

    private function hasOtherProviderEvidence(InvocationDispatch $dispatch): bool
    {
        foreach (InvocationDispatch::where('environment_id', (int) $dispatch->environment_id)
            ->where('logical_execution_id', (string) $dispatch->logical_execution_id)
            ->where('id', '<>', (int) $dispatch->id)->select() as $other) {
            if ($other->dispatch_start_time !== null || (string) $other->state !== 'not_dispatched') return true;
        }
        return false;
    }

    private function assertFrozenScope(TaskRun $task, ?TaskStep $step, Invocation $invocation, InvocationDispatch $dispatch): void
    {
        if ($step === null || (int) $step->task_id !== (int) $task->id
            || (int) $dispatch->parent_task_run_id !== (int) $task->id
            || (int) $dispatch->environment_id !== (int) $task->environment_id
            || (int) $invocation->environment_id !== (int) $task->environment_id
            || (int) $dispatch->invocation_id !== (int) $invocation->id
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || !is_string($invocation->request_fingerprint)
            || preg_match('/\A[a-f0-9]{64}\z/D', $invocation->request_fingerprint) !== 1
            || (string) $dispatch->logical_request_id !== hash('sha256', (string) $invocation->request_id)
            || !is_string($dispatch->workflow_node_code) || $dispatch->workflow_node_code === ''
            || preg_match('/\A[a-f0-9]{48}\z/D', (string) $dispatch->logical_execution_id) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $dispatch->execution_snapshot_sha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $dispatch->request_body_digest) !== 1
            || (int) $dispatch->lease_fence < 1 || (int) $dispatch->lease_fence > (int) $task->lease_fence
            || (int) $dispatch->attempt_no < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation provider scope is unverifiable');
        }
        foreach (['organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ($task->$field === null || (int) $task->$field < 1
                || (int) $invocation->$field !== (int) $task->$field) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation invocation belongs to another caller');
            }
        }
        if ((int) $dispatch->application_id !== (int) $task->application_id) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation dispatch belongs to another application');
        }
        if ((int) $dispatch->lease_fence === (int) $task->lease_fence && (string) $task->state === TaskState::RUNNING
            && ((string) $dispatch->lease_owner !== (string) $task->lease_owner
                || !hash_equals((string) $dispatch->lease_token, (string) $task->lease_token))) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task cancellation worker scope changed');
        }
        if ((string) $dispatch->execution_scope === 'structured_workflow') {
            foreach (['parent_workflow_run_id', 'parent_task_run_id', 'parent_task_step_id', 'parent_workflow_run_file_id'] as $field) {
                $invocationId = $invocation->$field === null ? null : (int) $invocation->$field;
                $dispatchId = $dispatch->$field === null ? null : (int) $dispatch->$field;
                if ($invocationId !== $dispatchId) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation workflow scope differs from its invocation');
                }
            }
            foreach (['workflow_node_code', 'logical_execution_id', 'execution_snapshot_sha256'] as $field) {
                if ((string) $invocation->$field !== (string) $dispatch->$field) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation workflow snapshot differs from its invocation');
                }
            }
            if ((int) $invocation->execution_attempt_no !== (int) $dispatch->attempt_no) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation workflow attempt differs from its invocation');
            }
        }
        $route = DispatchRouteSnapshot::assert(self::arrayValue($dispatch->route_snapshot), false);
        foreach (['provider_id', 'model_id', 'deployment_id'] as $field) {
            if ($route[$field] !== (int) $dispatch->$field) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation route differs from its frozen target');
            }
        }
        if ($route['capability_code'] !== (string) $dispatch->capability_code) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task cancellation capability differs from its route');
        }
        $price = self::arrayValue($dispatch->price_snapshot);
        $invocationPrice = CallStartPricingSnapshot::fromPersistence(
            $invocation->call_started_at, $invocation->call_start_pricing_state,
            $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
        );
        $dispatchPrice = CallStartPricingSnapshot::fromPersistence(
            $price['called_at'] ?? null, $price['pricing_state'] ?? null,
            $price['price_revision_id'] ?? null, $price['price_snapshot'] ?? null,
        );
        if ($invocationPrice->dispatchSnapshot() !== $dispatchPrice->dispatchSnapshot()) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Task cancellation frozen prices differ');
        }
    }

    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : (is_object($value) ? (array) $value : []);
    }
}
