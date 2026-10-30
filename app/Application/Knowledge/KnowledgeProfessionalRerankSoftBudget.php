<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetAlertPolicy;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use think\facade\Db;

/** Unbounded soft cost is a real pending obligation, never a monetary estimate. */
final class KnowledgeProfessionalRerankSoftBudget
{
    /** Called in invocation/dispatch preparation with current policy/window locks. */
    public function admit(Invocation $invocation, array $policies, KnowledgeProfessionalRerankPrice $price, array $route, string $bodyDigest, int $ttl): void
    {
        foreach ($policies as $policy) {
            if ((string) $policy->enforcement !== 'soft'
                || !in_array((string) $policy->unpriced_policy, ['allow', 'block'], true)
                || !in_array((string) $policy->unmeasured_policy, ['allow', 'hold'], true)) {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'Unbounded cost requires an executable soft policy');
            }
            if ($price->revisionId === null && (string) $policy->unpriced_policy === 'block') {
                throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'This soft policy blocks unpublished prices');
            }
            if ($price->currency !== null && $price->currency !== (string) $policy->currency) {
                throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'Professional price differs from soft budget currency');
            }
            $snapshot = ['policy_revision' => (int) $policy->revision, 'enforcement' => 'soft',
                'unpriced_policy' => (string) $policy->unpriced_policy, 'unmeasured_policy' => (string) $policy->unmeasured_policy,
                'body_sha256' => $bodyDigest, 'route_sha256' => $route['snapshot_sha256'],
                'price' => $price->dispatchSnapshot()];
            $id = Db::connect('pgsql')->table('sand_ai_rerank_soft_budget')->insertGetId([
                'policy_id' => (int) $policy->id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint,
                'policy_revision' => (int) $policy->revision, 'currency' => (string) $policy->currency,
                'price_revision_id' => $price->revisionId, 'metering_unit' => $price->meteringUnit,
                'state' => 'prepared', 'metered_amount' => null,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
            $this->audit((int) $id, (int) $invocation->id, 'budget.soft.cost_pending', 'Soft budget admitted an unbounded professional cost');
            $this->recordCurrentWindowAlert((int) $id, (int) $invocation->id, (int) $policy->id);
        }
    }

    /** Current scope/policy locks precede the complete sorted window/member set. */
    public static function lockHardCapacityWindows(array $currentPolicies, ?int $originalInvocationId = null): void
    {
        $hard = array_filter($currentPolicies, static fn ($policy): bool => (string) $policy->enforcement === 'hard');
        if ($hard === []) return;
        $db = Db::connect('pgsql');
        $policyIds = array_map(static fn ($policy): int => (int) $policy->id, $currentPolicies);
        $pendingIds = [];
        foreach ($hard as $policy) {
            $rows = $db->table('sand_ai_rerank_soft_budget')->alias('pending')
                ->join('sand_ai_budget_policy original_policy', 'original_policy.id=pending.policy_id')
                ->where('original_policy.scope_kind', (string) $policy->scope_kind)
                ->where('original_policy.scope_ref', (string) $policy->scope_ref)
                ->where('original_policy.period_start', '<', (string) $policy->period_end)
                ->where('original_policy.period_end', '>', (string) $policy->period_start)
                ->whereIn('pending.state', ['prepared', 'held'])->field('pending.id,pending.policy_id')->select()->toArray();
            foreach ($rows as $row) { $policyIds[] = (int) $row['policy_id']; $pendingIds[] = (int) $row['id']; }
        }
        // A prepared claim may own an older monetary group or a now-revoked
        // soft group. Include those windows before the coordinator locks it.
        if ($originalInvocationId !== null) {
            foreach ($db->table('sand_ai_budget_reservation')->where('invocation_id', $originalInvocationId)->field('policy_id')->select()->toArray() as $row) $policyIds[] = (int) $row['policy_id'];
            foreach ($db->table('sand_ai_rerank_soft_budget')->where('invocation_id', $originalInvocationId)->field('id,policy_id')->select()->toArray() as $row) {
                $policyIds[] = (int) $row['policy_id']; $pendingIds[] = (int) $row['id'];
            }
        }
        $policyIds = array_values(array_unique($policyIds));
        sort($policyIds, SORT_NUMERIC);
        $windows = BudgetWindow::whereIn('policy_id', $policyIds)->order('policy_id')->lock(true)->select()->all();
        if (count($windows) !== count($policyIds)) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Hard capacity pending-cost window is unavailable');
        if ($pendingIds !== []) $db->table('sand_ai_rerank_soft_budget')->whereIn('id', array_values(array_unique($pendingIds)))->order('policy_id')->order('id')->lock(true)->select();
    }

    /** Call under the existing current scope/policy selection locks. */
    public static function assertHardCapacityPolicies(array $currentPolicies): void
    {
        foreach ($currentPolicies as $policy) {
            if ((string) $policy->enforcement !== 'hard') continue;
            $pending = Db::connect('pgsql')->table('sand_ai_rerank_soft_budget')->alias('pending')
                ->join('sand_ai_budget_policy original_policy', 'original_policy.id=pending.policy_id')
                ->where('original_policy.scope_kind', (string) $policy->scope_kind)
                ->where('original_policy.scope_ref', (string) $policy->scope_ref)
                ->where('original_policy.period_start', '<', (string) $policy->period_end)
                ->where('original_policy.period_end', '>', (string) $policy->period_start)
                ->whereIn('pending.state', ['prepared', 'held'])->field('pending.id')->find();
            if ($pending !== null) throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED',
                'An original professional soft cost in this hard budget scope remains unquantified; dispatch was not sent');
        }
    }

    /** Lock all original windows before pending rows and invocation/dispatch owners. */
    public function lockForInvocation(int $invocationId): array
    {
        $db = Db::connect('pgsql');
        $rows = $db->table('sand_ai_rerank_soft_budget')->where('invocation_id', $invocationId)->order('policy_id')->order('id')->select()->toArray();
        if ($rows === []) return [];
        $ids = array_map(static fn (array $row): int => (int) $row['policy_id'], $rows);
        $windows = BudgetWindow::whereIn('policy_id', $ids)->order('policy_id')->lock(true)->select()->all();
        if (count($windows) !== count($ids)) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Soft cost window is unavailable');
        return $db->table('sand_ai_rerank_soft_budget')->where('invocation_id', $invocationId)->order('policy_id')->order('id')->lock(true)->select()->toArray();
    }

    /** Original pending members must still be current, unsent and effective. */
    public function assertPrepared(Invocation $invocation, InvocationDispatch $dispatch, array $currentPolicies): array
    {
        $rows = $this->lockForInvocation((int) $invocation->id);
        $byPolicy = [];
        foreach ($currentPolicies as $policy) $byPolicy[(int) $policy->id] = $policy;
        foreach ($rows as $row) {
            $policy = $byPolicy[(int) $row['policy_id']] ?? null;
            $snapshot = is_string($row['snapshot']) ? json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR) : $row['snapshot'];
            $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
            $dispatchPrice = is_object($dispatch->price_snapshot) ? (array) $dispatch->price_snapshot : $dispatch->price_snapshot;
            if ($policy === null || (string) $policy->enforcement !== 'soft'
                || (int) $policy->revision !== (int) $row['policy_revision']
                || (string) $policy->currency !== (string) $row['currency']
                || ($snapshot['unpriced_policy'] ?? null) !== (string) $policy->unpriced_policy
                || ($snapshot['unmeasured_policy'] ?? null) !== (string) $policy->unmeasured_policy
                || $row['state'] !== 'prepared' || $dispatch->dispatch_start_time !== null
                || !is_string($row['expires_at']) || $row['expires_at'] <= gmdate('Y-m-d H:i:s')
                || !hash_equals((string) $row['request_fingerprint'], (string) $invocation->request_fingerprint)
                || ($snapshot['body_sha256'] ?? null) !== (string) $dispatch->request_body_digest
                || ($snapshot['route_sha256'] ?? null) !== ($route['snapshot_sha256'] ?? null)
                || !is_array($route) || !is_array($dispatchPrice) || !is_array($snapshot['price'] ?? null)
                || !RequestPayloadFingerprint::same($snapshot['price'], $dispatchPrice, [])) {
                throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Original soft cost admission changed or expired before dispatch');
            }
        }
        return $rows;
    }

    /** Runs inside terminal accounting; no original-read caller invokes this method. */
    public function finish(int $invocationId, ?string $amount, ?int $priceRevisionId, bool $sent): void
    {
        foreach ($this->lockForInvocation($invocationId) as $row) {
            if (in_array($row['state'], ['settled', 'released'], true)) {
                if ($sent && $amount !== null && ($row['state'] !== 'settled'
                    || BudgetDecimal::compare((string) $row['metered_amount'], $amount) !== 0)) {
                    throw new ApiProblem('SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT', 'Original soft cost settlement changed');
                }
                continue;
            }
            $target = !$sent ? 'released' : ($amount === null ? 'held' : 'settled');
            if ($target === 'settled') {
                if ($priceRevisionId === null || (int) $row['price_revision_id'] !== $priceRevisionId) {
                    throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'Soft cost settlement changed the original price');
                }
                ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                    'UPDATE sand_ai_budget_window SET settled_amount=settled_amount+CAST(? AS numeric(20,6)),lock_version=lock_version+1,update_time=CURRENT_TIMESTAMP(0) WHERE policy_id=?',
                    [$amount, (int) $row['policy_id']]), 'SAND_AI_BUDGET_WINDOW_CHANGED', 'Soft cost settlement window changed');
            }
            if ($row['state'] === $target) continue;
            ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                'UPDATE sand_ai_rerank_soft_budget SET state=?,metered_amount=CAST(? AS numeric(20,6)),expires_at=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND state=?',
                [$target, $target === 'settled' ? $amount : null, (int) $row['id'], $row['state']]),
                'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Original soft cost record changed');
            if ($target === 'settled') $this->recordCurrentWindowAlert((int) $row['id'], $invocationId, (int) $row['policy_id']);
            $this->audit((int) $row['id'], $invocationId, 'budget.soft.' . $target,
                $target === 'held' ? 'Actual professional cost remains unknown under the frozen unmeasured policy' : ($target === 'settled' ? 'Actual professional cost settled' : 'Unsent professional cost released'));
        }
    }

    /** Pure SELECT projection after the owning invocation has been authorized. */
    public function projection(int $invocationId): ?array
    {
        $rows = Db::connect('pgsql')->table('sand_ai_rerank_soft_budget')->where('invocation_id', $invocationId)->field('state')->select()->toArray();
        if ($rows === []) return null;
        $states = array_column($rows, 'state');
        $state = in_array('held', $states, true) ? 'held'
            : (in_array('prepared', $states, true) ? 'soft_cost_pending' : (in_array('settled', $states, true) ? 'settled' : 'released'));
        return ['state' => $state, 'warnings' => in_array($state, ['held', 'soft_cost_pending'], true)
            ? [['code' => 'SAND_AI_BUDGET_COST_PENDING']] : []];
    }

    /** Existing 80/90/100 percent alerts use only known window money. */
    private function recordCurrentWindowAlert(int $id, int $invocationId, int $policyId): void
    {
        $window = BudgetWindow::where('policy_id', $policyId)->find();
        if ($window === null) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Soft budget alert window is unavailable');
        $used = BudgetDecimal::add(BudgetDecimal::add((string) $window->reserved_amount, (string) $window->settled_amount), (string) $window->held_amount);
        $alert = BudgetAlertPolicy::forWindow(['limit_amount' => (string) $window->limit_amount,
            'available_amount' => BudgetDecimal::subtract((string) $window->limit_amount, $used)]);
        if ($alert['level'] === 'normal') return;
        AuditLog::create(['actor_type' => 'system_worker', 'actor_ref' => 'rerank:' . $invocationId,
            'action' => 'budget.soft.threshold_alert', 'resource_type' => 'rerank_soft_budget', 'resource_id' => $id,
            'summary' => 'Soft budget current monetary window reached its configured alert threshold',
            'context' => ['invocation_id' => $invocationId, 'alert' => $alert]]);
    }

    private function audit(int $id, int $invocationId, string $action, string $summary): void
    {
        AuditLog::create(['actor_type' => 'system_worker', 'actor_ref' => 'rerank:' . $invocationId,
            'action' => $action, 'resource_type' => 'rerank_soft_budget', 'resource_id' => $id,
            'summary' => $summary, 'context' => ['invocation_id' => $invocationId, 'amount_known' => $action === 'budget.soft.settled']]);
    }
}
