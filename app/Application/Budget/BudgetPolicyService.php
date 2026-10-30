<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\BudgetPolicy;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Application\Usage\UsageAttribution;
use app\Application\Gateway\RequestPayloadFingerprint;
use think\facade\Db;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Capability\CapabilityDriverRegistry;

final class BudgetPolicyService
{
    /** The caller holds its preparation/dispatch transaction; zero means this call has no model. */
    public function assertUnpricedCallAllowed(UsageAttribution $attribution, string $capabilityCode, string $at, int $modelId = 0): void
    {
        foreach ($this->applicable($attribution, $modelId, $capabilityCode, $at, true) as $policy) {
            if ((string) $policy->unpriced_policy !== 'block' || (string) $policy->unmeasured_policy !== 'hold') {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'The active budget policy is not executable');
            }
            throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'This capability has no frozen price upper bound and an applicable budget blocks dispatch');
        }
    }

    /** @return list<BudgetPolicy> */
    public function applicable(UsageAttribution $attribution, int $modelId, string $capabilityCode, string $at, bool $lock = false, ?int $originalInvocationId = null): array
    {
        $context = BudgetScopeContext::forCall($attribution, $modelId, $capabilityCode);
        if ($lock) {
            $context->lockForSelection();
        }
        $ids = [];
        foreach ($context->scopes as $scope) {
            $policies = BudgetPolicy::where('scope_kind', $scope['scope_kind'])
                ->where('scope_ref', $scope['scope_ref'])->where('state', 'published')
                ->where('period_start', '<=', $at)->where('period_end', '>', $at)
                ->whereNull('delete_time')->order('id')->limit(2)->select();
            if (count($policies) > 1) {
                throw new ApiProblem('SAND_AI_BUDGET_CONFIGURATION_CONFLICT', 'Multiple active budget revisions apply to one scope');
            }
            foreach ($policies as $policy) {
                $ids[] = (int) $policy->id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $query = BudgetPolicy::whereIn('id', $ids)->order('id');
        if ($lock) {
            $query->lock(true);
        }
        $policies = $query->select()->all();
        if ($lock) {
            \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget::lockHardCapacityWindows($policies, $originalInvocationId);
            \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget::assertHardCapacityPolicies($policies);
        }
        return $policies;
    }

    /** @return array<string,mixed> */
    public function list(int $page, int $limit, ?int $environmentId, ?string $state, ?string $scopeKind = null, ?string $scopeRef = null): array
    {
        if ($state !== null && !in_array($state, ['draft', 'published', 'archived'], true)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget policy state filter is invalid');
        }
        $query = BudgetPolicy::order('scope_kind')->order('scope_ref')->order('revision', 'desc');
        if ($scopeKind !== null || $scopeRef !== null) {
            $scope = (new BudgetPolicyValidator())->normalizeScope(['scope_kind' => $scopeKind, 'scope_ref' => $scopeRef]);
            $query->where('scope_kind', $scope['scope_kind'])->where('scope_ref', $scope['scope_ref']);
        }
        if ($environmentId !== null) {
            $query->where('environment_id', $environmentId);
        }
        if ($state !== null) {
            $query->where('state', $state);
        }
        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $result['data'] = array_map(fn (array $row): array => $this->summary($row), $result['data']);

        return $result;
    }

    /** @return array<string,mixed> */
    public function read(int $id): array
    {
        $policy = BudgetPolicy::find($id);
        if ($policy === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Budget policy revision not found');
        }

        return $this->summary($policy->toArray());
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveDraft(array $input, string $actorRef, ?string $requestKey, \Closure $revalidateAdmin): array
    {
        $validator = new BudgetPolicyValidator();
        $requestKey = $requestKey === null ? null : $validator->normalizeRequestKey($requestKey);
        $this->assertDraftActor($actorRef);
        $revalidateAdmin();

        $outcome = Db::connect('pgsql')->transaction(function () use ($input, $actorRef, $requestKey, $revalidateAdmin, $validator): array {
            if ($requestKey !== null) {
                Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 132))', [
                    'sand_ai_budget_draft:' . RequestPayloadFingerprint::fingerprint(['actor' => $actorRef, 'key' => $requestKey]),
                ]);
            }
            $revalidateAdmin();
            $receipt = $requestKey === null ? null : $this->draftReceipt($actorRef, $requestKey);
            if ($receipt !== null) {
                try {
                    $fingerprint = (string) $receipt['state'] === 'not_saved'
                        ? $this->draftRejectionFingerprint($input)
                        : RequestPayloadFingerprint::fingerprint($validator->normalizeDraft($input));
                } catch (ApiProblem|\InvalidArgumentException|\JsonException) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original budget save request belongs to a different draft payload');
                }
                if (!hash_equals((string) $receipt['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original budget save request belongs to a different draft payload');
                }
                $scope = $validator->normalizeScope($input);
                $result = $this->draftReceiptSnapshot($receipt, $scope['scope_kind'], $scope['scope_ref']);
                $revalidateAdmin();
                return $result;
            }

            // Scope and current administrative authority must be proven before recording a rejection.
            $target = $validator->normalizeDraftTarget($input);
            $this->assertReference($target, $target['reference_environment_id']);
            $policy = null;
            if ($target['id'] !== null) {
                $policy = BudgetPolicy::where('id', $target['id'])->whereNull('delete_time')->lock(true)->find();
                if ($policy !== null && ((string) $policy->scope_kind !== $target['scope_kind']
                    || (string) $policy->scope_ref !== $target['scope_ref'])) {
                    throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'A budget revision cannot move to another scope');
                }
            }
            try {
                $data = $validator->normalizeDraft($input);
                if ($data['id'] !== null) {
                    if ($policy === null) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Budget policy revision not found');
                    }
                    $validator->assertMutable((string) $policy->state);
                }
            } catch (ApiProblem $problem) {
                if ($requestKey === null || !in_array($problem->errorCode, [
                    'SAND_AI_BUDGET_VALIDATION_ERROR', 'SAND_AI_BUDGET_IMMUTABLE', 'SAND_AI_RESOURCE_NOT_FOUND',
                ], true)) {
                    throw $problem;
                }
                $result = ['state' => 'not_saved', 'error_code' => $problem->errorCode, 'message' => $problem->getMessage()];
                $revalidateAdmin();
                $this->recordDraftReceipt($actorRef, $requestKey, $target, null, 'not_saved',
                    $this->draftRejectionFingerprint($input), $result);
                // Return so the transaction commits the rejection; the public error is raised after commit.
                return $result;
            }

            // No catch below this boundary: SQL, audit and later authorization failures roll back all writes.
            if ($data['id'] === null) {
                $this->lockScope($data['scope_kind'], $data['scope_ref']);
                $revision = (int) BudgetPolicy::where('scope_kind', $data['scope_kind'])->where('scope_ref', $data['scope_ref'])->max('revision') + 1;
                $rows = Db::connect('pgsql')->query(
                    "INSERT INTO sand_ai_budget_policy (revision, environment_id, scope_kind, scope_ref, period_start, period_end, currency, limit_amount, enforcement, unpriced_policy, unmeasured_policy, state) VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS numeric(20,6)), ?, ?, ?, 'draft') RETURNING id",
                    [max(1, $revision), $data['environment_id'], $data['scope_kind'], $data['scope_ref'], $data['period_start'], $data['period_end'], $data['currency'], $data['limit_amount'], $data['enforcement'], $data['unpriced_policy'], $data['unmeasured_policy']]
                );
                $id = (int) $rows[0]['id'];
            } else {
                Db::connect('pgsql')->execute(
                    'UPDATE sand_ai_budget_policy SET environment_id = ?, scope_kind = ?, scope_ref = ?, period_start = ?, period_end = ?, currency = ?, limit_amount = CAST(? AS numeric(20,6)), enforcement = ?, unpriced_policy = ?, unmeasured_policy = ?, update_time = CURRENT_TIMESTAMP WHERE id = ?',
                    [$data['environment_id'], $data['scope_kind'], $data['scope_ref'], $data['period_start'], $data['period_end'], $data['currency'], $data['limit_amount'], $data['enforcement'], $data['unpriced_policy'], $data['unmeasured_policy'], $data['id']]
                );
                $id = (int) $data['id'];
            }

            $result = $this->read($id);
            $revalidateAdmin();
            if ($requestKey !== null) {
                $this->recordDraftReceipt($actorRef, $requestKey, $data, $id, 'saved',
                    RequestPayloadFingerprint::fingerprint($data), $result);
            }
            $this->audit($actorRef, 'budget.policy.draft', $id, 'Saved SandAI budget policy draft');
            $revalidateAdmin();
            return $result;
        });
        if (($outcome['state'] ?? null) === 'not_saved') {
            throw new ApiProblem((string) $outcome['error_code'], (string) $outcome['message']);
        }
        return $outcome;
    }

    /** @param array<string,mixed> $input */
    private function draftRejectionFingerprint(array $input): string
    {
        return RequestPayloadFingerprint::fingerprint(['domain' => 'budget_policy_draft_rejected@1', 'input' => $input], []);
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $response */
    private function recordDraftReceipt(string $actorRef, string $requestKey, array $scope, ?int $policyId,
        string $state, string $fingerprint, array $response): void
    {
        Db::connect('pgsql')->execute(
            'INSERT INTO sand_ai_budget_policy_operation (created_by, idempotency_key, scope_kind, scope_ref, budget_policy_id, reference_environment_id, state, request_fingerprint, response_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb))',
            [$actorRef, $requestKey, $scope['scope_kind'], $scope['scope_ref'], $policyId, $scope['reference_environment_id'], $state, $fingerprint,
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
    }

    /** Recover only the committed original save response; this method performs no writes.
     * @param array<string,mixed> $input @return array<string,mixed>
     */
    public function readDraftByRequestKey(array $input, string $actorRef, \Closure $revalidateAdmin): array
    {
        $query = (new BudgetPolicyValidator())->normalizeDraftLookup($input);
        $this->assertDraftActor($actorRef);
        $revalidateAdmin();
        $receipt = $this->draftReceipt($actorRef, $query['request_key']);
        if ($receipt === null) {
            $this->draftReceiptMissing();
        }
        $policy = $this->draftReceiptSnapshot($receipt, $query['scope_kind'], $query['scope_ref']);
        $revalidateAdmin();
        $current = $this->draftReceipt($actorRef, $query['request_key']);
        if ($current === null || !RequestPayloadFingerprint::same($receipt, $current)) {
            $this->draftReceiptMissing();
        }
        $this->draftReceiptSnapshot($current, $query['scope_kind'], $query['scope_ref']);
        $revalidateAdmin();
        return [...$policy, 'idempotent_replay' => true];
    }

    /** @return array<string,mixed>|null */
    private function draftReceipt(string $actorRef, string $requestKey): ?array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT created_by, idempotency_key, scope_kind, scope_ref, budget_policy_id, reference_environment_id, state, request_fingerprint, response_snapshot FROM sand_ai_budget_policy_operation WHERE created_by = ? AND idempotency_key = ?',
            [$actorRef, $requestKey]
        );
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || (string) $rows[0]['created_by'] !== $actorRef
            || (string) $rows[0]['idempotency_key'] !== $requestKey || !is_string($rows[0]['request_fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/', $rows[0]['request_fingerprint']) !== 1) {
            $this->draftReceiptMissing();
        }
        return $rows[0];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function draftReceiptSnapshot(array $receipt, string $scopeKind, string $scopeRef): array
    {
        if ((string) $receipt['scope_kind'] !== $scopeKind || (string) $receipt['scope_ref'] !== $scopeRef) {
            $this->draftReceiptMissing();
        }
        try {
            $snapshot = is_string($receipt['response_snapshot'])
                ? json_decode($receipt['response_snapshot'], true, 16, JSON_THROW_ON_ERROR)
                : $receipt['response_snapshot'];
        } catch (\JsonException) {
            $this->draftReceiptMissing();
        }
        $referenceEnvironmentId = $receipt['reference_environment_id'] === null ? null : (int) $receipt['reference_environment_id'];
        if ((string) $receipt['state'] === 'not_saved') {
            if ($receipt['budget_policy_id'] !== null || !is_array($snapshot) || array_is_list($snapshot)
                || count($snapshot) !== 3 || array_diff(array_keys($snapshot), ['state', 'error_code', 'message']) !== []
                || ($snapshot['state'] ?? null) !== 'not_saved'
                || !in_array($snapshot['error_code'] ?? null, [
                    'SAND_AI_BUDGET_VALIDATION_ERROR', 'SAND_AI_BUDGET_IMMUTABLE', 'SAND_AI_RESOURCE_NOT_FOUND',
                ], true) || !is_string($snapshot['message'] ?? null) || trim($snapshot['message']) === ''
                || strlen($snapshot['message']) > 256) {
                $this->draftReceiptMissing();
            }
            $this->assertReference(['scope_kind' => $scopeKind, 'scope_ref' => $scopeRef], $referenceEnvironmentId);
            return $snapshot;
        }
        if ((string) $receipt['state'] !== 'saved' || (int) $receipt['budget_policy_id'] < 1) {
            $this->draftReceiptMissing();
        }
        $fields = ['id', 'revision', 'environment_id', 'scope_kind', 'scope_ref', 'period_start', 'period_end', 'currency',
            'limit_amount', 'enforcement', 'unpriced_policy', 'unmeasured_policy', 'state', 'published_time', 'create_time', 'update_time'];
        if (!is_array($snapshot) || array_is_list($snapshot) || array_diff(array_keys($snapshot), $fields) !== []
            || count($snapshot) !== count($fields) || (int) ($snapshot['id'] ?? 0) !== (int) $receipt['budget_policy_id']
            || (int) ($snapshot['revision'] ?? 0) < 1 || ($snapshot['scope_kind'] ?? null) !== $scopeKind
            || ($snapshot['scope_ref'] ?? null) !== $scopeRef || ($snapshot['state'] ?? null) !== 'draft') {
            $this->draftReceiptMissing();
        }
        $policy = BudgetPolicy::where('id', (int) $receipt['budget_policy_id'])->whereNull('delete_time')->find();
        if ($policy === null || (string) $policy->scope_kind !== $scopeKind || (string) $policy->scope_ref !== $scopeRef
            || (int) $policy->revision !== (int) $snapshot['revision']) {
            $this->draftReceiptMissing();
        }
        $this->assertReference($policy->toArray(), $referenceEnvironmentId);
        // The current mutable row proves access only; its later edits must not replace this saved response.
        return $snapshot;
    }

    private function assertDraftActor(string $actorRef): void
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/', $actorRef) !== 1
            || filter_var($actorRef, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'A canonical authenticated administrator is required');
        }
    }

    private function draftReceiptMissing(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'The original budget draft save could not be confirmed');
    }

    /** @param array<string,mixed>|null $expectedPolicy @return array<string,mixed> */
    public function publish(int $id, string $actorRef = 'saiadmin', ?int $referenceEnvironmentId = null, ?array $expectedPolicy = null): array
    {
        return Db::connect('pgsql')->transaction(function () use ($id, $actorRef, $referenceEnvironmentId, $expectedPolicy): array {
            $probe = BudgetPolicy::find($id);
            if ($probe === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Budget policy revision not found');
            $this->lockScope((string) $probe->scope_kind, (string) $probe->scope_ref);
            $policy = BudgetPolicy::where('id', $id)->lock(true)->find();
            if ($policy === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Budget policy revision not found');
            }
            if ($expectedPolicy !== null) {
                $this->assertPublicationExpectation($policy, $expectedPolicy);
            }
            if ((string) $policy->state === 'published') {
                return $this->read($id);
            }
            (new BudgetPolicyValidator())->assertTransition((string) $policy->state, 'published');
            if (!in_array((string) $policy->enforcement, ['hard', 'soft'], true)
                || !in_array((string) $policy->unpriced_policy, ['block', 'allow'], true)
                || !in_array((string) $policy->unmeasured_policy, ['hold', 'allow'], true)
                || ((string) $policy->enforcement === 'hard'
                    && ((string) $policy->unpriced_policy !== 'block'
                        || (string) $policy->unmeasured_policy !== 'hold'))) {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'Hard budgets require unpriced=block and unmeasured=hold; soft policies must use supported missing-usage settings');
            }
            $this->assertReference($policy->toArray(), $referenceEnvironmentId);
            $overlap = BudgetPolicy::where('scope_kind', (string) $policy->scope_kind)->where('scope_ref', (string) $policy->scope_ref)
                ->where('state', 'published')
                ->where('period_start', '<', (string) $policy->period_end)
                ->where('period_end', '>', (string) $policy->period_start)
                ->lock(true)
                ->find();
            if ($overlap !== null) {
                throw new ApiProblem('SAND_AI_BUDGET_PERIOD_CONFLICT', 'Published budget periods for one scope must not overlap');
            }
            Db::connect('pgsql')->execute("UPDATE sand_ai_budget_policy SET state = 'published', published_time = CURRENT_TIMESTAMP, update_time = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
            Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_budget_window (policy_id, currency, limit_amount) VALUES (?, ?, CAST(? AS numeric(20,6))) ON CONFLICT (policy_id) DO NOTHING',
                [$id, (string) $policy->currency, (string) $policy->limit_amount]
            );

            $published = $this->read($id);
            $this->audit($actorRef, 'budget.policy.publish', $id, 'Published SandAI budget policy revision');

            return $published;
        });
    }

    /** @param array<string,mixed> $expected */
    private function assertPublicationExpectation(BudgetPolicy $policy, array $expected): void
    {
        $fields = ['revision', 'scope_kind', 'scope_ref', 'environment_id', 'period_start', 'period_end',
            'currency', 'limit_amount', 'enforcement', 'unpriced_policy', 'unmeasured_policy'];
        if (count($expected) !== count($fields) || array_diff(array_keys($expected), $fields) !== []
            || !is_int($expected['revision'] ?? null) || $expected['revision'] <= 0
            || ($expected['environment_id'] !== null && (!is_int($expected['environment_id']) || $expected['environment_id'] <= 0))) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Expected policy must contain the complete published budget configuration');
        }
        foreach ($fields as $field) {
            if (!in_array($field, ['revision', 'environment_id'], true) && !is_string($expected[$field])) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Expected policy configuration fields must be strings');
            }
        }
        $revision = $expected['revision'];
        unset($expected['revision']);
        $validator = new BudgetPolicyValidator();
        $normalized = $validator->normalizeDraft($expected);
        if ($expected['environment_id'] !== $normalized['environment_id']) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Expected environment must match the budget scope');
        }
        $current = $validator->normalizeDraft([
            'scope_kind' => (string) $policy->scope_kind,
            'scope_ref' => (string) $policy->scope_ref,
            'environment_id' => $policy->environment_id === null ? null : (int) $policy->environment_id,
            'period_start' => (string) $policy->period_start,
            'period_end' => (string) $policy->period_end,
            'currency' => (string) $policy->currency,
            'limit_amount' => (string) $policy->limit_amount,
            'enforcement' => (string) $policy->enforcement,
            'unpriced_policy' => (string) $policy->unpriced_policy,
            'unmeasured_policy' => (string) $policy->unmeasured_policy,
        ]);
        if ($revision !== (int) $policy->revision || $normalized !== $current) {
            throw new ApiProblem('SAND_AI_BUDGET_CONFIGURATION_CONFLICT', 'Budget draft changed after confirmation; the confirmed configuration was not published');
        }
    }

    /** Archive only future applicability; existing reservations and metering remain intact.
     * @return array<string,mixed>
     */
    public function archive(int $id, string $actorRef, ?int $referenceEnvironmentId = null): array
    {
        if ($id <= 0) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Budget policy id must be a positive integer');
        }
        return Db::connect('pgsql')->transaction(function () use ($id, $actorRef, $referenceEnvironmentId): array {
            $reference = BudgetPolicy::where('id', $id)->whereNull('delete_time')->find();
            if ($reference === null) {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_FOUND', 'Budget policy not found', 404);
            }
            $this->lockScope((string) $reference->scope_kind, (string) $reference->scope_ref);
            $policy = BudgetPolicy::where('id', $id)->whereNull('delete_time')->lock(true)->find();
            if ($policy === null) {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_FOUND', 'Budget policy not found', 404);
            }
            if (in_array((string) $policy->scope_kind, ['organization', 'application', 'environment'], true)) {
                $this->assertReference($policy->toArray(), $referenceEnvironmentId);
            }
            if ((string) $policy->state === 'archived') {
                return $this->summary($policy->toArray());
            }
            if ((string) $policy->state !== 'published') {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_IMMUTABLE', 'Only a published budget policy can be archived');
            }
            $updated = BudgetPolicy::where('id', $id)->where('state', 'published')->whereNull('delete_time')
                ->update(['state' => 'archived', 'update_time' => gmdate('Y-m-d H:i:s')]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_BUDGET_CONFIGURATION_CONFLICT', 'Budget policy changed; refresh before retrying');
            }
            $this->audit($actorRef, 'budget_policy.archive', $id, 'Archived budget policy revision; existing reservations remain accounted');
            return $this->summary(BudgetPolicy::find($id)->toArray());
        });
    }

    /** @return array<string,mixed> */
    public function window(int $policyId): array
    {
        $policy = BudgetPolicy::find($policyId);
        $window = BudgetWindow::where('policy_id', $policyId)->find();
        if ($policy === null || $window === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published budget window not found');
        }
        $amounts = (new BudgetReservationStateMachine())->summary([
            'currency' => (string) $window->currency,
            'limit_amount' => (string) $window->limit_amount,
            'reserved_amount' => (string) $window->reserved_amount,
            'settled_amount' => (string) $window->settled_amount,
            'held_amount' => (string) $window->held_amount,
        ]);

        return [
            'policy' => $this->summary($policy->toArray()),
            'window' => $amounts,
            'alert' => BudgetAlertPolicy::forWindow($amounts),
        ];
    }

    /** @return array{active:bool,summary:?array<string,mixed>} */
    public function activeWindow(int $environmentId): array
    {
        return $this->activeScope('environment', (string) $environmentId);
    }

    /** @return array{active:bool,summary:?array<string,mixed>} */
    public function activeScope(string $kind, string $reference): array
    {
        $scope = (new BudgetPolicyValidator())->normalizeScope(['scope_kind' => $kind, 'scope_ref' => $reference]);
        $now = gmdate('Y-m-d H:i:s');
        $policies = BudgetPolicy::where('scope_kind', $scope['scope_kind'])->where('scope_ref', $scope['scope_ref'])
            ->where('state', 'published')->where('period_start', '<=', $now)->where('period_end', '>', $now)->order('id')->limit(2)->select();
        if (count($policies) > 1) throw new ApiProblem('SAND_AI_BUDGET_CONFIGURATION_CONFLICT', 'Multiple active policies apply to this scope');
        $policy = $policies->first();
        return $policy === null ? ['active' => false, 'summary' => null] : ['active' => true, 'summary' => $this->window((int) $policy->id)];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function summary(array $data): array
    {
        $summary = array_intersect_key($data, array_flip([
            'id', 'revision', 'environment_id', 'scope_kind', 'scope_ref', 'period_start', 'period_end', 'currency',
            'limit_amount', 'enforcement', 'unpriced_policy', 'unmeasured_policy', 'state', 'published_time',
            'create_time', 'update_time',
        ]));
        if (isset($summary['limit_amount'])) {
            $summary['limit_amount'] = (string) $summary['limit_amount'];
        }

        return $summary;
    }

    private function lockScope(string $kind, string $reference): void
    {
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 132))', [BudgetScopeContext::key($kind, $reference)]);
    }

    /** @param array<string,mixed> $scope */
    private function assertReference(array $scope, ?int $referenceEnvironmentId): void
    {
        $kind = (string) $scope['scope_kind'];
        $ref = (string) $scope['scope_ref'];
        if (in_array($kind, ['organization', 'application', 'environment'], true)) {
            $environment = $kind === 'environment' ? (int) $ref : $referenceEnvironmentId;
            if ($environment === null) throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Select an environment belonging to this organization or application for verification');
            try { $facts = (new HostSandIamEnvironmentFacts())->resolve($environment); }
            catch (IdentityContextException) { throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'The official SandIAM environment reference could not be verified'); }
            if ((string) $facts[$kind . '_id'] !== $ref) throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'The selected environment does not belong to the budget scope');
        } elseif ($kind === 'model') {
            if (AiModel::where('id', (int) $ref)->where('status', 1)->find() === null) throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Select an enabled SandAI model');
        } elseif ($kind === 'capability') {
            if ($ref !== 'external_readonly' && !array_key_exists($ref, CapabilityDriverRegistry::metadata())) throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Select a registered SandAI capability');
        }
    }

    private function audit(string $actorRef, string $action, int $resourceId, string $summary): void
    {
        AuditLog::create([
            'actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => $action,
            'resource_type' => 'budget_policy', 'resource_id' => $resourceId, 'summary' => $summary, 'context' => [],
        ]);
    }
}
