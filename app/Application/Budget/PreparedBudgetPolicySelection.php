<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\BudgetPolicy;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use think\facade\Db;

/** Current policy selection locked before any prepared window/owner row. */
final readonly class PreparedBudgetPolicySelection
{
    private function __construct(
        private int $invocationId,
        private int $dispatchId,
        private int $modelId,
        private array $identity,
        private array $scopes,
        private array $policyRevisions,
        private ?ApiProblem $capacityProblem = null,
    ) {
    }

    /**
     * The coordinator already holds its PostgreSQL transaction. Read persisted
     * dimensions, then lock sorted scope keys and current policies before its
     * original windows/reservations/invocation/dispatch. No provider I/O.
     * Missing or already sent ledgers retain the coordinator's original path.
     */
    public static function lock(int $invocationId, int $dispatchId, ?UsageAttribution $attribution = null): ?self
    {
        // A repeatable-read snapshot taken before a publisher wins the scope
        // lock could hide its new hard budget. Refuse that prepared path; do
        // not alter the connection or an existing transaction's isolation.
        $isolation = Db::connect('pgsql')->query('SHOW transaction_isolation');
        if (count($isolation) !== 1 || ($isolation[0]['transaction_isolation'] ?? null) !== 'read committed') return null;
        $invocation = Invocation::find($invocationId);
        $dispatch = InvocationDispatch::find($dispatchId);
        if ($invocation === null || $dispatch === null
            || (string) $invocation->state !== 'running'
            || (string) $dispatch->state !== 'prepared' || $dispatch->dispatch_start_time !== null
            || (int) $dispatch->invocation_id !== $invocationId
            || (int) $dispatch->environment_id !== (int) $invocation->environment_id
            || (int) $dispatch->model_id !== (int) $invocation->model_id) return null;
        if ($attribution === null) {
            $dimensions = [];
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                $value = $invocation->{$field};
                if ((!is_int($value) && !is_string($value))
                    || (is_string($value) && (!ctype_digit($value) || (string) (int) $value !== $value))
                    || (int) $value < 1) return null;
                $dimensions[$field] = (int) $value;
            }
            $attribution = new UsageAttribution(
                $dimensions['environment_id'], $dimensions['organization_id'], $dimensions['application_id'], $dimensions['workload_client_id'],
                ['organization_ref' => (string) $dimensions['organization_id'], 'application_ref' => (string) $dimensions['application_id'],
                    'workload_client_ref' => (string) $dimensions['workload_client_id']],
                (string) $dispatch->source_kind, (string) $dispatch->service_action, (string) $dispatch->capability_code,
            );
        }
        if (!self::matches($invocation, $dispatch, $attribution)) return null;
        try {
            $scope = BudgetScopeContext::forCall($attribution, (int) $invocation->model_id, (string) $dispatch->capability_code);
        } catch (ApiProblem) {
            return null;
        }
        // applicable() acquires scope locks before policy rows. In particular,
        // do not call it for the first time after the original window locks.
        $capacityProblem = null;
        try {
            $policies = (new BudgetPolicyService())->applicable(
                $attribution, (int) $invocation->model_id, (string) $dispatch->capability_code, gmdate('Y-m-d H:i:s'), true, $invocationId,
            );
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_BUDGET_CAPACITY_UNVERIFIED') throw $problem;
            // Keep its scope/policy/window/member locks and defer the refusal
            // until the coordinator can release the original unsent group.
            $capacityProblem = $problem;
            $policies = (new BudgetPolicyService())->applicable(
                $attribution, (int) $invocation->model_id, (string) $dispatch->capability_code, gmdate('Y-m-d H:i:s'), false,
            );
        }
        return new self($invocationId, $dispatchId, (int) $invocation->model_id,
            self::identity($attribution), $scope->scopes, self::revisions($policies), $capacityProblem);
    }

    /** Compare current policy membership with the original frozen reservation group. */
    public function assertOriginalGroup(Invocation $invocation, InvocationDispatch $dispatch, UsageAttribution $attribution, array $members): void
    {
        $this->assertOriginalContext($invocation, $dispatch, $attribution);
        $ids = [];
        foreach ($members as $member) {
            $reservation = $member['reservation'];
            if ((int) $reservation->invocation_id !== $this->invocationId) self::changed();
            $ids[] = (int) $reservation->policy_id;
        }
        $this->assertOriginalPolicyIds($ids);
    }

    /** Rerank pending obligations have no fabricated monetary reservation. */
    public function assertOriginalPendingGroup(Invocation $invocation, InvocationDispatch $dispatch, UsageAttribution $attribution, array $rows): void
    {
        // Reuse owner/identity/current-time checks without pretending pending rows
        // are monetary reservations. The empty group is checked separately below.
        $this->assertOriginalContext($invocation, $dispatch, $attribution);
        $ids = [];
        foreach ($rows as $row) {
            if ((int) $row['invocation_id'] !== $this->invocationId
                || ($this->policyRevisions[(int) $row['policy_id']] ?? null) !== (int) $row['policy_revision']) self::changed();
            $ids[] = (int) $row['policy_id'];
        }
        $this->assertOriginalPolicyIds($ids);
    }

    public function currentPolicies(): array
    {
        return $this->policyRevisions === [] ? [] : BudgetPolicy::whereIn('id', array_keys($this->policyRevisions))->order('id')->select()->all();
    }

    private function assertOriginalContext(Invocation $invocation, InvocationDispatch $dispatch, UsageAttribution $attribution): void
    {
        if ((int) $invocation->id !== $this->invocationId || (int) $dispatch->id !== $this->dispatchId
            || (int) $invocation->model_id !== $this->modelId
            || (string) $dispatch->state !== 'prepared' || $dispatch->dispatch_start_time !== null
            || !self::matches($invocation, $dispatch, $attribution)
            || self::identity($attribution) !== $this->identity
            || BudgetScopeContext::forCall($attribution, $this->modelId, (string) $dispatch->capability_code)->scopes !== $this->scopes) self::changed();
        if ($this->capacityProblem !== null) throw $this->capacityProblem;
        // Existing shared scope locks prevent configuration changes. A period
        // boundary can still change membership, so check the current time too.
        $current = (new BudgetPolicyService())->applicable(
            $attribution, $this->modelId, (string) $dispatch->capability_code, gmdate('Y-m-d H:i:s'), false,
        );
        if (self::revisions($current) !== $this->policyRevisions) self::changed();
        \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget::assertHardCapacityPolicies($current);
    }

    private function assertOriginalPolicyIds(array $ids): void
    {
        sort($ids, SORT_NUMERIC);
        if (count(array_unique($ids)) !== count($ids) || $ids !== array_keys($this->policyRevisions)) self::changed();
        if ($ids !== [] && self::revisions(BudgetPolicy::whereIn('id', $ids)->order('id')->select()->all()) !== $this->policyRevisions) self::changed();
    }

    private static function matches(Invocation $invocation, InvocationDispatch $dispatch, UsageAttribution $attribution): bool
    {
        return $attribution->environmentId === (int) $invocation->environment_id
            && $attribution->organizationId === ($invocation->organization_id === null ? null : (int) $invocation->organization_id)
            && $attribution->applicationId === ($invocation->application_id === null ? null : (int) $invocation->application_id)
            && $attribution->workloadClientId === ($invocation->workload_client_id === null ? null : (int) $invocation->workload_client_id)
            && $attribution->sourceKind === (string) $dispatch->source_kind
            && $attribution->serviceAction === (string) $dispatch->service_action
            && $attribution->capabilityCode === (string) $dispatch->capability_code;
    }

    private static function identity(UsageAttribution $attribution): array
    {
        return [$attribution->environmentId, $attribution->organizationId, $attribution->applicationId, $attribution->workloadClientId,
            $attribution->identitySnapshot['organization_ref'] ?? null, $attribution->identitySnapshot['application_ref'] ?? null,
            $attribution->identitySnapshot['workload_client_ref'] ?? null, $attribution->sourceKind, $attribution->serviceAction, $attribution->capabilityCode];
    }

    private static function revisions(array $policies): array
    {
        $revisions = [];
        foreach ($policies as $policy) $revisions[(int) $policy->id] = (int) $policy->revision;
        ksort($revisions, SORT_NUMERIC);
        return $revisions;
    }

    private static function changed(): never
    {
        throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED',
            'Current budget policies differ from the original unsent prepared reservation; provider dispatch was not sent');
    }
}
