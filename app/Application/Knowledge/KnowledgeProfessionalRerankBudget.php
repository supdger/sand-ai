<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Budget\PreparedBudgetPolicySelection;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\TokenPriceCalculator;
use app\Infrastructure\Persistence\Model\BudgetEvent;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Provider\NeuronProfessionalRerankCapacity;
use think\facade\Db;

/** Professional integer-unit budget; token heuristics never reserve money here. */
final class KnowledgeProfessionalRerankBudget
{
    /**
     * @param array<string,mixed> $route
     * @param callable(Invocation,KnowledgeProfessionalRerankPrice):void $prepareDispatch
     * @return array{invocation:Invocation,reservation_id:?int,price_revision_id:?int}
     */
    public function prepare(
        string $requestId, string $fingerprint, UsageAttribution $attribution,
        KnowledgeAnswerAttribution $source, KnowledgeProfessionalRerankPrice $price,
        array $route, string $bodyDigest, ?array $capacityProof, int $ttl,
        callable $prepareDispatch,
    ): array {
        if ($attribution->sourceKind !== 'knowledge_query' || $attribution->serviceAction !== 'sand_ai.knowledge.query'
            || $attribution->capabilityCode !== 'rerank' || $ttl < 60 || $ttl > 3600) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Professional rerank budget scope is invalid');
        }
        $upperBound = NeuronProfessionalRerankCapacity::assertBinding($capacityProof,
            (string) $route['provider_adapter'], (string) $route['deployment_remote_model'], $bodyDigest, $price->meteringUnit);
        $result = Db::connect('pgsql')->transaction(function () use (
            $requestId, $fingerprint, $attribution, $source, $price, $route, $bodyDigest, $capacityProof, $upperBound, $ttl, $prepareDispatch,
        ): array {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,132))', ['sand_ai_chat_request:' . $requestId]);
            // Once recorded, even an unsent original must be recovered by its
            // original read path. This operation never automatically re-sends it.
            if (Invocation::where('request_id', $requestId)->find() !== null) {
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original professional rerank must be read by its original key', true);
            }
            $policies = [];
            $plans = [];
            $pendingPolicies = [];
            $amount = $price->reservationAmount($upperBound);
            $rejected = null;
            try {
                $policies = (new BudgetPolicyService())->applicable(
                    $attribution, (int) $route['model_id'], 'rerank', $price->calledAt, true,
                );
                // Validate every policy before admitting any member. A hard
                // policy in a mixed group cannot inherit soft permission.
                foreach ($policies as $policy) {
                    $hard = (string) $policy->enforcement === 'hard';
                    if (!in_array((string) $policy->enforcement, ['hard', 'soft'], true)
                        || !in_array((string) $policy->unpriced_policy, ['block', 'allow'], true)
                        || !in_array((string) $policy->unmeasured_policy, ['hold', 'allow'], true)
                        || ($hard && ((string) $policy->unpriced_policy !== 'block' || (string) $policy->unmeasured_policy !== 'hold'))) {
                        throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'Professional rerank budget policy is not executable');
                    }
                    if ($hard && $upperBound === null) throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Hard budget requires an official professional rerank capacity bound');
                    if ($price->revisionId === null && (string) $policy->unpriced_policy === 'block') throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'This policy requires a published professional rerank price');
                    if ($price->currency !== null && (string) $policy->currency !== $price->currency) throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'Professional rerank price and budget currencies differ');
                    if ($hard && ($amount === null || !BudgetDecimal::isPositive($amount))) throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Hard monetary reservation requires a verified positive cost bound');
                }
                foreach ($policies as $policy) {
                    $window = BudgetWindow::where('policy_id', (int) $policy->id)->lock(true)->find();
                    if ($window === null) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Professional rerank budget window is unavailable');
                    $snapshot = (new ChatBudgetGuard())->holdExpiredReservations($window, $price->calledAt);
                    if ($amount === null || !BudgetDecimal::isPositive($amount)) {
                        $pendingPolicies[] = $policy;
                        continue;
                    }
                    $plans[] = ['policy' => $policy, 'window' => $window, 'estimate' => $amount,
                        'transition' => (new BudgetReservationStateMachine())->reserve(
                            $snapshot, $amount, (string) $policy->currency, (string) $policy->enforcement === 'soft')];
                }
            } catch (ApiProblem $problem) {
                $rejected = $problem;
            }
            $invocation = Invocation::create([
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'environment_id' => $attribution->environmentId, 'organization_id' => $attribution->organizationId,
                'application_id' => $attribution->applicationId, 'workload_client_id' => $attribution->workloadClientId,
                'model_id' => (int) $route['model_id'], 'deployment_id' => (int) $route['deployment_id'],
                'knowledge_publication_id' => $source->publicationId, 'knowledge_base_version_id' => $source->versionId,
                'knowledge_source_scope_sha256' => $source->sourceScopeSha256, 'data_class' => $source->dataClass,
                'state' => $rejected === null ? 'running' : 'failed',
                'completed_at' => $rejected === null ? null : $price->calledAt,
                'error_code' => $rejected?->errorCode, 'error_summary' => $rejected?->getMessage(),
                ...$price->persistence(),
            ]);
            if ($rejected !== null) return ['problem' => $rejected];
            $anchor = null;
            foreach ($plans as $plan) {
                $transition = $plan['transition'];
                ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                    'UPDATE sand_ai_budget_window SET reserved_amount=CAST(? AS numeric(20,6)),settled_amount=CAST(? AS numeric(20,6)),held_amount=CAST(? AS numeric(20,6)),lock_version=lock_version+1,update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                    [$transition['window']['reserved_amount'], $transition['window']['settled_amount'], $transition['window']['held_amount'], (int) $plan['window']->id],
                ), 'SAND_AI_BUDGET_WINDOW_CHANGED', 'Professional rerank budget window changed');
                $rows = Db::connect('pgsql')->query(
                    "INSERT INTO sand_ai_budget_reservation (policy_id,invocation_id,request_id,request_fingerprint,estimate_amount,currency,price_revision_id,state,expires_at)
                     VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?,'reserved',?) RETURNING id",
                    [(int) $plan['policy']->id, (int) $invocation->id, $requestId, $fingerprint, $plan['estimate'], $price->currency,
                        $price->revisionId, gmdate('Y-m-d H:i:s', time() + $ttl)],
                );
                if (count($rows) !== 1) throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'Professional rerank reservation failed');
                $id = (int) $rows[0]['id'];
                $anchor ??= $id;
                Db::connect('pgsql')->execute(
                    "INSERT INTO sand_ai_budget_event (policy_id,reservation_id,invocation_id,event_type,amount,currency,idempotency_key,snapshot)
                     VALUES (?,?,?,'reserve',CAST(? AS numeric(20,6)),?,?,CAST(? AS jsonb))",
                    [(int) $plan['policy']->id, $id, (int) $invocation->id, $plan['estimate'], $price->currency,
                        'reservation:' . $id . ':reserve', json_encode(['metering_unit' => $price->meteringUnit,
                            'upper_bound' => $upperBound, 'body_sha256' => $bodyDigest, 'route_sha256' => $route['snapshot_sha256'],
                            'price_revision_id' => $price->revisionId, 'capacity_proof' => $capacityProof,
                            'reservation_rounding' => TokenPriceCalculator::RESERVATION_ROUNDING], JSON_THROW_ON_ERROR)],
                );
            }
            $prepareDispatch($invocation, $price);
            if ($pendingPolicies !== []) (new KnowledgeProfessionalRerankSoftBudget())->admit(
                $invocation, $pendingPolicies, $price, $route, $bodyDigest, $ttl);
            return ['invocation' => $invocation, 'reservation_id' => $anchor, 'price_revision_id' => $price->revisionId];
        });
        if (isset($result['problem'])) throw $result['problem'];
        return $result;
    }

    /** Called only inside the common claim transaction with current policy locks. */
    public function assertPrepared(
        Invocation $invocation, InvocationDispatch $dispatch, ?BudgetReservation $anchor,
        ?PreparedBudgetPolicySelection $selection,
    ): void {
        if ($selection === null || (string) $dispatch->capability_code !== 'rerank'
            || (string) $dispatch->execution_scope !== 'rerank' || (string) $dispatch->source_kind !== 'knowledge_query'
            || (string) $dispatch->service_action !== 'sand_ai.knowledge.query') {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Professional rerank current policy is unverified');
        }
        $identity = new UsageAttribution((int) $invocation->environment_id,
            $invocation->organization_id === null ? null : (int) $invocation->organization_id,
            $invocation->application_id === null ? null : (int) $invocation->application_id,
            $invocation->workload_client_id === null ? null : (int) $invocation->workload_client_id,
            ['organization_ref' => (string) $invocation->organization_id, 'application_ref' => (string) $invocation->application_id,
                'workload_client_ref' => (string) $invocation->workload_client_id], 'knowledge_query', 'sand_ai.knowledge.query', 'rerank');
        $group = $anchor === null ? [] : (new ChatBudgetGuard())->lockReservationGroup((int) $anchor->id);
        $pending = (new KnowledgeProfessionalRerankSoftBudget())->assertPrepared($invocation, $dispatch, $selection->currentPolicies());
        if ($pending !== []) {
            if ($group !== []) throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Mixed pending and monetary groups are invalid');
            $selection->assertOriginalPendingGroup($invocation, $dispatch, $identity, $pending);
        } else {
            $selection->assertOriginalGroup($invocation, $dispatch, $identity, $group);
        }
        $price = KnowledgeProfessionalRerankPrice::fromPersistence($invocation->call_started_at,
            $invocation->call_start_pricing_state, $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot);
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        $dispatchPrice = is_object($dispatch->price_snapshot) ? (array) $dispatch->price_snapshot : $dispatch->price_snapshot;
        if (!is_array($route) || !is_array($dispatchPrice)
            || !RequestPayloadFingerprint::same($dispatchPrice, $price->dispatchSnapshot(), [])) {
            throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'Professional rerank prepared price changed');
        }
        foreach ($group as $member) {
            $this->assertReservation($member['reservation'], $price, $route, (string) $dispatch->request_body_digest);
        }
    }

    /** Rebind the in-memory official request to its claimed ledger immediately before HTTP. */
    public function assertSending(
        int $invocationId, int $dispatchId, array $route, string $bodyDigest,
        ?array $capacityProof, KnowledgeProfessionalRerankPrice $price,
    ): void {
        $invocation = Invocation::where('id', $invocationId)->find();
        $dispatch = InvocationDispatch::where('id', $dispatchId)->find();
        $storedRoute = $dispatch?->route_snapshot;
        $storedRoute = is_object($storedRoute) ? (array) $storedRoute : $storedRoute;
        $storedPrice = $dispatch?->price_snapshot;
        $storedPrice = is_object($storedPrice) ? (array) $storedPrice : $storedPrice;
        if ($invocation === null || $dispatch === null || (int) $dispatch->invocation_id !== $invocationId
            || (string) $invocation->state !== 'running' || (string) $dispatch->state !== 'dispatching'
            || (string) $dispatch->execution_scope !== 'rerank' || (string) $dispatch->capability_code !== 'rerank'
            || (string) $dispatch->request_body_digest !== $bodyDigest
            || !is_array($storedRoute) || !RequestPayloadFingerprint::same($storedRoute, $route, [])
            || !is_array($storedPrice) || !RequestPayloadFingerprint::same($storedPrice, $price->dispatchSnapshot(), [])) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank request differs from its claimed ledger');
        }
        $stored = KnowledgeProfessionalRerankPrice::fromPersistence($invocation->call_started_at,
            $invocation->call_start_pricing_state, $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot);
        if (!RequestPayloadFingerprint::same($stored->dispatchSnapshot(), $price->dispatchSnapshot(), [])) {
            throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'Professional rerank price changed before send');
        }
        NeuronProfessionalRerankCapacity::assertBinding($capacityProof, (string) $route['provider_adapter'],
            (string) $route['deployment_remote_model'], $bodyDigest, $price->meteringUnit);
        $reservations = BudgetReservation::where('invocation_id', $invocationId)->select();
        foreach ($reservations as $reservation) {
            if ($capacityProof === null) {
                throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Professional rerank reserved capacity is missing');
            }
            $this->assertReservation($reservation, $price, $route, $bodyDigest, $capacityProof);
        }
    }

    private function assertReservation(
        BudgetReservation $reservation, KnowledgeProfessionalRerankPrice $price,
        array $route, string $bodyDigest, ?array $expectedCapacity = null,
    ): void {
        $event = BudgetEvent::where('reservation_id', (int) $reservation->id)->where('event_type', 'reserve')->find();
        $proof = $event?->snapshot;
        $proof = is_object($proof) ? (array) $proof : $proof;
        $capacity = is_array($proof) ? ($proof['capacity_proof'] ?? null) : null;
        $capacity = is_object($capacity) ? (array) $capacity : $capacity;
        if (!is_array($proof) || !is_array($capacity) || ($proof['metering_unit'] ?? null) !== $price->meteringUnit
            || ($proof['reservation_rounding'] ?? null) !== TokenPriceCalculator::RESERVATION_ROUNDING
            || ($proof['body_sha256'] ?? null) !== $bodyDigest
            || ($proof['route_sha256'] ?? null) !== ($route['snapshot_sha256'] ?? null)
            || ($proof['price_revision_id'] ?? null) !== $price->revisionId
            || (int) $reservation->price_revision_id !== $price->revisionId
            || (string) $reservation->currency !== $price->currency || (string) $reservation->state !== 'reserved'
            || ($expectedCapacity !== null && !RequestPayloadFingerprint::same($capacity, $expectedCapacity, []))) {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Professional rerank original capacity is unverified');
        }
        $upperBound = NeuronProfessionalRerankCapacity::assertBinding($capacity, (string) $route['provider_adapter'],
            (string) $route['deployment_remote_model'], $bodyDigest, $price->meteringUnit);
        $amount = $price->reservationAmount($upperBound);
        if (($proof['upper_bound'] ?? null) !== $upperBound || $amount === null || !BudgetDecimal::isPositive($amount)
            || BudgetDecimal::compare((string) $reservation->estimate_amount, $amount) !== 0) {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Professional rerank reservation changed');
        }
    }
}
