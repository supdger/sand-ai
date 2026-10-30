<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\InternalModelInvocationContext;
use app\Application\Skill\SkillAttribution;
use app\Application\Knowledge\KnowledgeAnswerAttribution;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Application\Usage\PriceResolver;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\PriceRevision;
use app\Infrastructure\Persistence\Model\BudgetPolicy;
use app\Infrastructure\Persistence\Model\BudgetEvent;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\TokenPriceCalculator;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\PreDispatchRejection;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use think\facade\Db;

/** Atomically creates the invocation and reserves its upper-bound cost under an active policy. */
final class ChatBudgetGuard
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param null|callable(Invocation,CallStartPricingSnapshot):void $afterAcceptedInvocation Runs inside the
     *        same PostgreSQL transaction as Invocation and budget reservation.
     * @return array{invocation:Invocation,reservation_id:?int,estimated_amount:?string,price_revision_id:?int,called_at:string,pricing_snapshot:CallStartPricingSnapshot,idempotent_replay:bool}
     */
    public function prepare(
        int $environmentId,
        int $modelId,
        ModelDeployment $deployment,
        array $messages,
        ChatCompletionOptions $options,
        string $requestId,
        string $requestFingerprint,
        int $reservationTtlSeconds,
        string $capabilityCode = 'chat',
        ?InternalModelInvocationContext $internalContext = null,
        ?callable $afterAcceptedInvocation = null, ?SkillAttribution $skillAttribution = null,
        ?callable $afterRejectedInvocation = null,
        ?string $preDispatchTrustedScope = null,
        ?UsageAttribution $attribution = null,
        ?KnowledgeAnswerAttribution $knowledgeAnswerAttribution = null,
        ?BudgetInputTokenProof $inputTokenProof = null,
        ?array $providerRequestBody = null,
        ?array $providerRouteSnapshot = null,
        ?string $providerRequestProtocol = null,
    ): array {
        if ($reservationTtlSeconds < 60 || $reservationTtlSeconds > 3600) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget reservation ttl must be between 60 and 3600 seconds');
        }
        if ($skillAttribution !== null && $skillAttribution->environmentId !== $environmentId) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill attribution environment is inconsistent');
        }
        if ($knowledgeAnswerAttribution !== null && $skillAttribution !== null) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Knowledge answer and Skill attribution cannot be combined');
        }
        if ($capabilityCode === 'embedding' && ($options->maxOutputTokens !== null || count($messages) !== 1)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Embedding reservation requires one input and no output token bound');
        }
        if ($capabilityCode === 'embedding') {
            $input = $messages[0] ?? null;
            if (!is_array($input) || array_keys($input) !== ['role', 'content']
                || ($input['role'] ?? null) !== 'user' || !is_string($input['content'] ?? null)
                || trim($input['content']) === '' || strlen($input['content']) > 65536
                || preg_match('//u', $input['content']) !== 1) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Embedding input is invalid');
            }
        }
        $calledAt = gmdate('Y-m-d H:i:s');
        $budgetAt = gmdate('Y-m-d H:i:s');
        $result = Db::connect('pgsql')->transaction(function () use ($environmentId, $modelId, $deployment, $messages, $options, $requestId, $requestFingerprint, $reservationTtlSeconds, $calledAt, $budgetAt, $capabilityCode, $internalContext, $afterAcceptedInvocation, $skillAttribution, $afterRejectedInvocation, $preDispatchTrustedScope, $attribution, $knowledgeAnswerAttribution, $inputTokenProof, $providerRequestBody, $providerRouteSnapshot, $providerRequestProtocol): array {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 132))', ['sand_ai_chat_request:' . $requestId]);
            if ($preDispatchTrustedScope !== null) {
                $preRejected = PreDispatchRejection::where('trusted_scope', $preDispatchTrustedScope)->where('request_id', $requestId)->lock(true)->find();
                if ($preRejected !== null) {
                    if (!is_string($preRejected->request_fingerprint) || !hash_equals($preRejected->request_fingerprint, $requestFingerprint)) {
                        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id was already used with different input');
                    }
                    return ['rejected' => new ApiProblem((string) $preRejected->error_code, 'Provider dispatch was not sent')];
                }
            }
            $existing = Invocation::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->environment_id !== $environmentId
                    || !is_string($existing->request_fingerprint)
                    || !hash_equals($existing->request_fingerprint, $requestFingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id was already used with a different or unverifiable chat input');
                }
                $this->assertSkillAttributionReplay($existing, $skillAttribution);
                $this->assertKnowledgeAttributionReplay($existing, $knowledgeAnswerAttribution);

                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                // A Skill receipt is durable provider fact. It must be allowed
                // to close a running invocation even if the reservation timed
                // out while the local finalizer was unavailable.
                if ($skillAttribution !== null && (string) $existing->state === 'running') {
                    $pricingSnapshot = $this->pricingSnapshot($existing);
                    return ['invocation' => $existing, 'reservation_id' => $reservation === null ? null : (int) $reservation->id, 'estimated_amount' => $reservation === null ? null : (string) $reservation->estimate_amount, 'price_revision_id' => $pricingSnapshot->priceRevisionId, 'called_at' => $pricingSnapshot->calledAt, 'pricing_snapshot' => $pricingSnapshot, 'idempotent_replay' => true, 'skill_receipt_recovery' => true];
                }
                if ($reservation !== null && $this->lockForCompletion((int) $reservation->id)['expired']) {
                    return ['rejected' => new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before completion')];
                }

                if ((string) $existing->state === 'running' && $skillAttribution === null) {
                    $prepared = InvocationDispatch::where('invocation_id', (int) $existing->id)->where('state', 'prepared')->find();
                    if ($prepared !== null) {
                        $pricingSnapshot = $this->pricingSnapshot($existing);
                        $this->assertPreparedCapacity($existing, $prepared, $reservation, $pricingSnapshot, $attribution, $budgetAt);
                        return [
                            'invocation' => $existing,
                            'reservation_id' => $reservation === null ? null : (int) $reservation->id,
                            'estimated_amount' => $reservation === null ? null : (string) $reservation->estimate_amount,
                            'price_revision_id' => $pricingSnapshot->priceRevisionId,
                            'called_at' => $pricingSnapshot->calledAt,
                            'pricing_snapshot' => $pricingSnapshot,
                            'idempotent_replay' => false,
                            'resume_prepared' => true,
                        ];
                    }
                }

                $pricingSnapshot = $this->pricingSnapshot($existing);
                return ['invocation' => $existing, 'reservation_id' => $skillAttribution === null || $reservation === null ? null : (int) $reservation->id, 'estimated_amount' => $skillAttribution === null || $reservation === null ? null : (string) $reservation->estimate_amount, 'price_revision_id' => $pricingSnapshot->priceRevisionId, 'called_at' => $pricingSnapshot->calledAt, 'pricing_snapshot' => $pricingSnapshot, 'idempotent_replay' => true];
            }
            $pricingSnapshot = CallStartPricingSnapshot::resolve(
                new PriceResolver(),
                (int) $deployment->provider_id,
                $modelId,
                $capabilityCode,
                $calledAt,
            );
            if ($attribution === null || $attribution->environmentId !== $environmentId) {
                throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Trusted attribution is required before budget selection');
            }
            $policies = [];
            $estimate = null;
            $plans = [];
            try {
                $policies = (new BudgetPolicyService())->applicable($attribution, $modelId, $capabilityCode, $budgetAt, true);
                foreach ($policies as $policy) {
                    if (!in_array((string) $policy->enforcement, ['hard', 'soft'], true)
                        || (string) $policy->unpriced_policy !== 'block' || (string) $policy->unmeasured_policy !== 'hold') {
                        throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'The active budget policy is not executable');
                    }
                }
                if ($policies !== []) {
                    $config = $this->deploymentConfig($deployment);
                    $price = $pricingSnapshot->resolvedPrice();
                    if (array_key_exists('budget_capacity_attestation', $config)) {
                        if ($providerRequestBody === null || $providerRouteSnapshot === null) {
                            throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'The final provider body and frozen route are required for capacity reservation');
                        }
                        $capacity = BudgetCapacityBound::fromRoute($providerRouteSnapshot, $providerRequestBody, $environmentId, $providerRequestProtocol);
                        $capacity->assertTarget($deployment, $modelId, $environmentId, $providerRouteSnapshot, $providerRequestBody);
                        $estimate = $capacity->reservation($price, $options->maxOutputTokens ?? 0);
                        if (!BudgetDecimal::isPositive($estimate['estimated_amount'])) $estimate['estimated_amount'] = '0.000001';
                    } else {
                        // A tokenizer receipt proves provenance, not a strict
                        // monetary input bound. It cannot lower this reservation.
                        throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'A supported official protocol capacity is required under an active budget');
                    }
                    if ((int) $estimate['price_revision_id'] !== $pricingSnapshot->priceRevisionId) {
                        throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'Budget estimation did not use the call-start price revision');
                    }
                    $ids = array_map(static fn (BudgetPolicy $policy): int => (int) $policy->id, $policies);
                    $windows = BudgetWindow::whereIn('policy_id', $ids)->order('policy_id')->lock(true)->select();
                    $byPolicy = [];
                    foreach ($windows as $window) $byPolicy[(int) $window->policy_id] = $window;
                    if (count($byPolicy) !== count($policies)) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'An active budget policy has no runtime window');
                    foreach ($policies as $policy) {
                        $window = $byPolicy[(int) $policy->id];
                        $plans[] = ['policy' => $policy, 'window' => $window, 'transition' => (new BudgetReservationStateMachine())->reserve(
                            $this->windowSnapshot($window), $estimate['estimated_amount'], $estimate['currency'], (string) $policy->enforcement === 'soft')];
                    }
                }
            } catch (ApiProblem $problem) {
                try { \support\Log::error('SandAI budget prepare rejection', [
                    'error_code' => $problem->errorCode,
                    'error_origin' => $problem->getFile() . ':' . $problem->getLine(),
                    'error_message' => $problem->getMessage(),
                ]); } catch (\Throwable) {}
                $rejectedInvocation = $this->createInvocation($environmentId, $modelId, (int) $deployment->id, $requestId, $requestFingerprint, $pricingSnapshot, null, $skillAttribution, $attribution, $knowledgeAnswerAttribution);
                $rejectedInvocation->save(['state' => 'failed', 'completed_at' => $calledAt, 'error_code' => $problem->errorCode, 'error_summary' => $problem->getMessage()]);
                foreach ($policies as $policy) {
                    $this->event((int) $policy->id, null, (int) $rejectedInvocation->id, 'reject', $estimate['estimated_amount'] ?? null, (string) $policy->currency, $problem->errorCode,
                        ['request_id' => $requestId, 'request_fingerprint' => $requestFingerprint]);
                }
                $this->afterRejectedInvocation($rejectedInvocation, $problem, $afterRejectedInvocation);
                return ['rejected' => $problem];
            }
            // Every window was checked without mutations. Failures below roll back the entire group.
            $invocation = $this->createInvocation($environmentId, $modelId, (int) $deployment->id, $requestId, $requestFingerprint, $pricingSnapshot, $internalContext, $skillAttribution, $attribution, $knowledgeAnswerAttribution);
            $anchor = null;
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $reservationTtlSeconds);
            foreach ($plans as $plan) {
                $policy = $plan['policy'];
                $transition = $plan['transition'];
                $rows = Db::connect('pgsql')->query(
                    "INSERT INTO sand_ai_budget_reservation (policy_id, invocation_id, request_id, request_fingerprint, estimate_amount, currency, price_revision_id, state, expires_at) VALUES (?, ?, ?, ?, CAST(? AS numeric(20,6)), ?, ?, 'reserved', ?) RETURNING id",
                    [(int) $policy->id, (int) $invocation->id, $requestId, $requestFingerprint, $estimate['estimated_amount'], $estimate['currency'], $estimate['price_revision_id'], $expiresAt]);
                if (count($rows) !== 1) throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'Budget reservation could not be created');
                $reservationId = (int) $rows[0]['id'];
                $anchor ??= $reservationId;
                $this->persistWindow((int) $plan['window']->id, $transition['window']);
                $this->event((int) $policy->id, $reservationId, (int) $invocation->id, 'reserve', $estimate['estimated_amount'], $estimate['currency'], null,
                    ['request_id' => $requestId, 'price_revision_id' => $estimate['price_revision_id'],
                        'reserved_input_tokens' => $estimate['reserved_input_tokens'], 'output_token_upper_bound' => $estimate['output_token_upper_bound'],
                        'input_bound' => $estimate['input_bound'] ?? null, 'enforcement' => (string) $policy->enforcement, 'overrun' => BudgetDecimal::compare($transition['window']['available_amount'], '0.000000') < 0]);
            }
            $this->afterAcceptedInvocation($invocation, $pricingSnapshot, $afterAcceptedInvocation);
            return ['invocation' => $invocation, 'reservation_id' => $anchor, 'estimated_amount' => $estimate['estimated_amount'] ?? null,
                'price_revision_id' => $pricingSnapshot->priceRevisionId, 'called_at' => $calledAt, 'pricing_snapshot' => $pricingSnapshot, 'idempotent_replay' => false];

        });
        if (isset($result['rejected']) && $result['rejected'] instanceof ApiProblem) {
            throw $result['rejected'];
        }

        return $result;
    }

    /**
     * Called inside the coordinator's claim transaction after its original
     * windows/reservations, invocation, ledger and lease have been locked.
     * Only an unsent prepared request reaches this boundary.
     */
    public function assertPreparedCapacityForDispatch(Invocation $invocation, InvocationDispatch $dispatch, ?BudgetReservation $anchor, ?UsageAttribution $attribution = null, ?PreparedBudgetPolicySelection $policySelection = null): void
    {
        if ((int) $dispatch->invocation_id !== (int) $invocation->id
            || (int) $dispatch->environment_id !== (int) $invocation->environment_id
            || (int) $dispatch->model_id !== (int) $invocation->model_id) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Prepared capacity belongs to another invocation scope');
        }
        if ($attribution === null) {
            // These projections were persisted from the authenticated scope.
            // Missing/opaque legacy dimensions cannot be invented at claim.
            $dimensions = [];
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                $value = $invocation->{$field};
                if ((!is_int($value) && !is_string($value))
                    || (is_string($value) && (!ctype_digit($value) || (string) (int) $value !== $value))
                    || (int) $value < 1) {
                    throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Prepared invocation lacks its original trusted identity dimensions');
                }
                $dimensions[$field] = (int) $value;
            }
            $attribution = new UsageAttribution(
                $dimensions['environment_id'], $dimensions['organization_id'], $dimensions['application_id'], $dimensions['workload_client_id'],
                ['organization_ref' => (string) $dimensions['organization_id'], 'application_ref' => (string) $dimensions['application_id'],
                    'workload_client_ref' => (string) $dimensions['workload_client_id']],
                (string) $dispatch->source_kind, (string) $dispatch->service_action, (string) $dispatch->capability_code,
            );
        }
        if ($attribution->environmentId !== (int) $invocation->environment_id
            || $attribution->organizationId !== ($invocation->organization_id === null ? null : (int) $invocation->organization_id)
            || $attribution->applicationId !== ($invocation->application_id === null ? null : (int) $invocation->application_id)
            || $attribution->workloadClientId !== ($invocation->workload_client_id === null ? null : (int) $invocation->workload_client_id)
            || $attribution->sourceKind !== (string) $dispatch->source_kind
            || $attribution->serviceAction !== (string) $dispatch->service_action
            || $attribution->capabilityCode !== (string) $dispatch->capability_code) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Prepared capacity attribution differs from the persisted invocation');
        }
        if ($anchor === null && BudgetReservation::where('invocation_id', (int) $invocation->id)->find() !== null) {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Prepared claim omitted its original reservation group');
        }
        if ($policySelection === null) {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Prepared dispatch lacks current policy scope locks');
        }
        $members = $anchor === null ? [] : $this->lockReservationGroup((int) $anchor->id);
        $policySelection->assertOriginalGroup($invocation, $dispatch, $attribution, $members);
        $this->assertPreparedCapacity($invocation, $dispatch, $anchor, $this->pricingSnapshot($invocation), $attribution, gmdate('Y-m-d H:i:s'));
    }

    /**
     * Already sent requests retain their original facts. An unsent prepared
     * request can resume only with its original full capacity reservation;
     * legacy count-only reservations require an explicit new plan/rejection.
     * This method never reprices, changes a body, or adds a provider request.
     */
    private function assertPreparedCapacity(Invocation $invocation, InvocationDispatch $dispatch, ?BudgetReservation $anchor, CallStartPricingSnapshot $priceSnapshot, ?UsageAttribution $attribution, string $budgetAt): void
    {
        $reject = static function (): never {
            throw new ApiProblem('SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED', 'Original unsent prepared reservation lacks a verified full protocol capacity; provider dispatch was not sent');
        };
        if ($dispatch->dispatch_start_time !== null || (string) $dispatch->state !== 'prepared') $reject();
        if ($anchor === null) {
            if ($attribution === null || (new BudgetPolicyService())->applicable($attribution, (int) $invocation->model_id, (string) $dispatch->capability_code, $budgetAt, true) !== []) $reject();
            return;
        }
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        if (!is_array($route)) $reject();
        $route = DispatchRouteSnapshot::assert($route, array_key_exists('max_output_tokens', $route));
        $price = $priceSnapshot->resolvedPrice();
        if ($price === null) $reject();
        foreach ($this->lockReservationGroup((int) $anchor->id) as $member) {
            $reservation = $member['reservation'];
            $event = BudgetEvent::where('reservation_id', (int) $reservation->id)->where('event_type', 'reserve')->find();
            $snapshot = $event === null ? null : $event->snapshot;
            $snapshot = is_object($snapshot) ? (array) $snapshot : $snapshot;
            $bound = is_array($snapshot) ? ($snapshot['input_bound'] ?? null) : null;
            $bound = is_object($bound) ? (array) $bound : $bound;
            if (!is_array($bound) || ($bound['kind'] ?? null) !== 'official_protocol_capacity'
                || (array_key_exists('amount_rounding', $bound) && $bound['amount_rounding'] !== TokenPriceCalculator::RESERVATION_ROUNDING)
                || ($bound['counted_input_tokens'] ?? null) !== null
                || (string) $reservation->state !== 'reserved'
                || (int) $reservation->invocation_id !== (int) $invocation->id
                || (int) $reservation->price_revision_id !== $price->revisionId
                || (string) $reservation->currency !== $price->currency
                || ($snapshot['price_revision_id'] ?? null) !== $price->revisionId
                || ($bound['body_sha256'] ?? null) !== (string) $dispatch->request_body_digest
                || ($bound['route_sha256'] ?? null) !== $route['snapshot_sha256']) $reject();
            $source = OfficialModelCapacity::resolve([
                'version' => $bound['capacity_version'] ?? null, 'source_code' => $bound['source_code'] ?? null,
                'source_sha256' => $bound['source_sha256'] ?? null, 'definition_sha256' => $bound['definition_sha256'] ?? null,
            ], $route['deployment_remote_model'], $route['capability_code']);
            $input = $source['input_capacity_tokens']; $output = $snapshot['output_token_upper_bound'] ?? null;
            if ($source['protocol'] === BudgetChatWireProtocol::VERSION) {
                if (($bound['protocol'] ?? null) !== BudgetChatWireProtocol::VERSION
                    || ($bound['wire_evidence_sha256'] ?? null) !== RequestPayloadFingerprint::fingerprint($source['wire_evidence'], [])) $reject();
            }
            if ($route['capability_code'] === 'embedding') {
                $protocol = $bound['protocol'] ?? null;
                if ($protocol === OfficialModelCapacity::EMBEDDING_BATCH_PROTOCOL) {
                    $count = $bound['entry_count'] ?? null;
                    if ($route['deployment_remote_model'] !== $source['batch_model']
                        || !is_int($count) || $count < 1 || $count > $source['max_batch_entries']
                        || ($bound['source_input_capacity_tokens'] ?? null) !== $input) $reject();
                    $input *= $count;
                } elseif ($protocol !== null
                    || (array_key_exists('entry_count', $bound) && $bound['entry_count'] !== 1)
                    || (array_key_exists('source_input_capacity_tokens', $bound) && $bound['source_input_capacity_tokens'] !== $input)) {
                    $reject();
                }
                // An old single-string bound has a different canonical body
                // digest from a batch. It cannot be relabelled or multiplied.
            }
            if (($bound['input_capacity_tokens'] ?? null) !== $input || ($snapshot['reserved_input_tokens'] ?? null) !== $input
                || !is_int($output) || $output < 0 || $output > $source['max_output_tokens']
                || (($route['capability_code'] === 'chat' || $source['protocol'] === BudgetChatWireProtocol::VERSION) && $output < 1)
                || ($route['capability_code'] === 'embedding' && $output !== 0)) $reject();
            $amount = TokenPriceCalculator::calculateReservation($input, $output,
                $price->inputRate['unit_price'], $price->inputRate['per_quantity'],
                $price->outputRate['unit_price'], $price->outputRate['per_quantity']);
            $minimum = BudgetDecimal::isPositive($amount['amount']) ? $amount['amount'] : '0.000001';
            if (BudgetDecimal::compare((string) $reservation->estimate_amount, $minimum) < 0) $reject();
        }
    }

    /** @param null|callable(Invocation,CallStartPricingSnapshot):void $afterAcceptedInvocation */
    private function afterAcceptedInvocation(Invocation $invocation, CallStartPricingSnapshot $pricingSnapshot, ?callable $afterAcceptedInvocation): void
    {
        if ($afterAcceptedInvocation !== null) {
            $afterAcceptedInvocation($invocation, $pricingSnapshot);
        }
    }

    /** @param null|callable(Invocation,ApiProblem):void $afterRejectedInvocation */
    private function afterRejectedInvocation(Invocation $invocation, ApiProblem $problem, ?callable $afterRejectedInvocation): void
    {
        if ($afterRejectedInvocation !== null) {
            $afterRejectedInvocation($invocation, $problem);
        }
    }

    /**
     * Requires the caller's PostgreSQL transaction. Windows are locked before owner records.
     * @return list<array{reservation:BudgetReservation,window:BudgetWindow}>
     */
    public function lockReservationGroup(int $anchorId): array
    {
        $probe = BudgetReservation::find($anchorId);
        if ($probe === null) {
            throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'Budget reservation not found');
        }
        $owner = null;
        foreach (['invocation_id', 'speech_run_id', 'external_audio_dispatch_id', 'audio_task_run_id', 'ocr_dispatch_id'] as $field) {
            if ($probe->$field !== null) {
                if ($owner !== null || (int) $probe->$field < 1) {
                    throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Budget reservation owner is invalid');
                }
                $owner = $field;
            }
        }
        if ($owner === null) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Budget reservation has no owner');
        }
        $query = BudgetReservation::where($owner, (int) $probe->$owner);
        if ($owner === 'audio_task_run_id') {
            if (!is_string($probe->audio_execution_id) || $probe->audio_execution_id === '') throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Audio execution identity is missing');
            $query->where('audio_execution_id', $probe->audio_execution_id);
        }
        $members = $query->order('policy_id')->order('id')->select();
        $ids = [];
        $policies = [];
        foreach ($members as $member) {
            $ids[] = (int) $member->id;
            $policies[] = (int) $member->policy_id;
        }
        if (!in_array($anchorId, $ids, true) || count(array_unique($policies)) !== count($policies)) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Budget reservation group is inconsistent');
        }
        $windows = BudgetWindow::whereIn('policy_id', $policies)->order('policy_id')->lock(true)->select();
        $byPolicy = [];
        foreach ($windows as $window) $byPolicy[(int) $window->policy_id] = $window;
        $locked = BudgetReservation::whereIn('id', $ids)->order('policy_id')->order('id')->lock(true)->select();
        if (count($windows) !== count($ids) || count($locked) !== count($ids)) {
            throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Budget reservation group is incomplete');
        }
        $result = [];
        $terminalStates = [];
        foreach ($locked as $reservation) {
            if ((int) $reservation->$owner !== (int) $probe->$owner
                || $reservation->audio_execution_id !== $probe->audio_execution_id
                || $reservation->request_id !== $probe->request_id
                || $reservation->request_fingerprint !== $probe->request_fingerprint
                || $reservation->currency !== $probe->currency
                || BudgetDecimal::compare((string) $reservation->estimate_amount, (string) $probe->estimate_amount) !== 0
                || (int) $reservation->price_revision_id !== (int) $probe->price_revision_id) {
                throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Budget reservation members differ from their frozen owner');
            }
            $terminalStates[] = (string) $reservation->state;
            $result[] = ['reservation' => $reservation, 'window' => $byPolicy[(int) $reservation->policy_id]];
        }
        if ((in_array('settled', $terminalStates, true) || in_array('released', $terminalStates, true))
            && count(array_unique($terminalStates)) !== 1) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Budget group has inconsistent terminal states');
        }
        return $result;
    }

    public function settle(int $reservationId, ?string $meteredAmount, string $reason, ?int $priceRevisionId = null, string $capabilityCode = 'chat'): void
    {
        Db::connect('pgsql')->transaction(function () use ($reservationId, $meteredAmount, $reason, $priceRevisionId, $capabilityCode): void {
            $members = $this->lockReservationGroup($reservationId);
            if ($meteredAmount !== null && ($priceRevisionId === null
                || !PriceRevision::where('id', $priceRevisionId)->where('capability_code', $capabilityCode)->find())) {
                throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'The metered capability differs from the reservation');
            }
            foreach ($members as $member) {
                $reservation = $member['reservation'];
                if ($meteredAmount !== null && (int) $reservation->price_revision_id !== $priceRevisionId) {
                    throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'The metered price revision differs from the reservation');
                }
                if ($meteredAmount === null && in_array((string) $reservation->state, ['settled', 'released'], true)) continue;
                $this->transitionMember($member, $meteredAmount === null ? 'held' : 'settled', $meteredAmount, $reason);
            }
        });
    }

    public function release(int $reservationId, string $reason): void
    {
        Db::connect('pgsql')->transaction(function () use ($reservationId, $reason): void {
            foreach ($this->lockReservationGroup($reservationId) as $member) {
                $this->transitionMember($member, 'released', null, $reason);
            }
        });
    }

    /** @param array{reservation:BudgetReservation,window:BudgetWindow} $member */
    private function transitionMember(array $member, string $target, ?string $amount, string $reason): void
    {
        $reservation = $member['reservation'];
        $machine = new BudgetReservationStateMachine();
        $snapshot = ['state' => (string) $reservation->state, 'estimate_amount' => (string) $reservation->estimate_amount,
            'metered_amount' => $reservation->metered_amount === null ? null : (string) $reservation->metered_amount,
            'currency' => (string) $reservation->currency];
        $window = $this->windowSnapshot($member['window']);
        $transition = match ($target) {
            'held' => $machine->hold($window, $snapshot, (string) $reservation->currency),
            'settled' => $machine->settle($window, $snapshot, (string) $amount, (string) $reservation->currency),
            'released' => $machine->release($window, $snapshot, (string) $reservation->currency),
        };
        if ((string) $reservation->state === $target) {
            // Normalize an old unmeasured zero placeholder without repeating
            // the window movement, hold event, or changing the expiry reason.
            if ($target === 'held' && $reservation->metered_amount !== null) {
                self::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                    "UPDATE sand_ai_budget_reservation SET metered_amount = NULL, update_time = CURRENT_TIMESTAMP WHERE id = ? AND state = 'held' AND metered_amount = 0",
                    [(int) $reservation->id]
                ), 'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Budget reservation changed while normalizing an unknown amount');
            }
            return;
        }
        $this->persistWindow((int) $member['window']->id, $transition['window']);
        self::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            'UPDATE sand_ai_budget_reservation SET state = ?, metered_amount = CAST(? AS numeric(20,6)), reason = ?, expires_at = NULL, update_time = CURRENT_TIMESTAMP WHERE id = ? AND state = ?',
            [$target, $transition['reservation']['metered_amount'], $reason, (int) $reservation->id, (string) $reservation->state]
        ), 'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Budget reservation changed before group finalization');
        $this->event((int) $reservation->policy_id, (int) $reservation->id,
            $reservation->invocation_id === null ? null : (int) $reservation->invocation_id,
            $target === 'settled' ? 'settle' : ($target === 'released' ? 'release' : 'hold'),
            $amount ?? (string) $reservation->estimate_amount, (string) $reservation->currency, $reason,
            ['overrun' => $transition['overrun']], $reservation->speech_run_id === null ? null : (int) $reservation->speech_run_id,
            $reservation->external_audio_dispatch_id === null ? null : (int) $reservation->external_audio_dispatch_id,
            $reservation->audio_task_run_id === null ? null : (int) $reservation->audio_task_run_id,
            $reservation->audio_execution_id === null ? null : (string) $reservation->audio_execution_id,
            $reservation->ocr_dispatch_id === null ? null : (int) $reservation->ocr_dispatch_id);
    }

    /** @return array{expired:bool,state:string,price_revision_id:int} */
    public function lockForCompletion(int $reservationId): array
    {
        $members = $this->lockReservationGroup($reservationId);
        $now = gmdate('Y-m-d H:i:s');
        $expired = false;
        foreach ($members as $member) {
            $reservation = $member['reservation'];
            if ((string) $reservation->state === 'reserved' && $reservation->expires_at !== null && (string) $reservation->expires_at <= $now) {
                $this->expireReservation($member['window'], $reservation, $now);
                $reservation->state = 'held';
                $reservation->reason = 'reservation_expired';
            }
            $expired = $expired || ((string) $reservation->state === 'held' && (string) $reservation->reason === 'reservation_expired');
        }
        return ['expired' => $expired, 'state' => (string) $members[0]['reservation']->state,
            'price_revision_id' => (int) $members[0]['reservation']->price_revision_id];
    }

    private function createInvocation(int $environmentId, int $modelId, int $deploymentId, string $requestId, string $requestFingerprint, CallStartPricingSnapshot $pricingSnapshot, ?InternalModelInvocationContext $internalContext = null, ?SkillAttribution $skillAttribution = null, ?UsageAttribution $attribution = null, ?KnowledgeAnswerAttribution $knowledgeAnswerAttribution = null): Invocation
    {
        return Invocation::create([
            'request_id' => $requestId, 'environment_id' => $environmentId, 'model_id' => $modelId,
            'deployment_id' => $deploymentId, 'request_fingerprint' => $requestFingerprint, 'state' => 'running',
            'organization_id' => $attribution?->organizationId, 'application_id' => $attribution?->applicationId,
            'workload_client_id' => $attribution?->workloadClientId, 'data_class' => $skillAttribution?->dataClass ?? $knowledgeAnswerAttribution?->dataClass,
            'knowledge_publication_id' => $knowledgeAnswerAttribution?->publicationId,
            'knowledge_base_version_id' => $knowledgeAnswerAttribution?->versionId,
            'knowledge_source_scope_sha256' => $knowledgeAnswerAttribution?->sourceScopeSha256,
            'parent_workflow_run_id' => $internalContext?->workflowRunId,
            'parent_task_run_id' => $internalContext?->taskRunId,
            'parent_task_step_id' => $internalContext?->taskStepId,
            'parent_workflow_run_file_id' => $internalContext?->workflowRunFileId,
            'workflow_node_code' => $internalContext?->workflowNodeCode,
            'execution_attempt_no' => $internalContext?->attemptNo,
            'logical_execution_id' => $internalContext?->logicalExecutionId,
            'execution_snapshot_sha256' => $internalContext?->executionSnapshotSha256,
            'skill_id' => $skillAttribution?->skillId, 'skill_version_id' => $skillAttribution?->skillVersionId, 'skill_run_id' => $skillAttribution?->skillRunId,
            ...$pricingSnapshot->persistence(),
        ]);
    }

    private function pricingSnapshot(Invocation $invocation): CallStartPricingSnapshot
    {
        return CallStartPricingSnapshot::fromPersistence(
            $invocation->call_started_at,
            $invocation->call_start_pricing_state,
            $invocation->call_start_price_revision_id,
            $invocation->call_start_price_snapshot,
        );
    }

    private function assertSkillAttributionReplay(Invocation $invocation, ?SkillAttribution $attribution): void
    {
        $persisted = [(int) ($invocation->skill_id ?? 0), (int) ($invocation->skill_version_id ?? 0), (int) ($invocation->skill_run_id ?? 0)];
        $expected = $attribution === null ? [0, 0, 0] : [$attribution->skillId, $attribution->skillVersionId, $attribution->skillRunId];
        if ($persisted !== $expected) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another Skill attribution');
        }
        if ($attribution !== null && (int) $invocation->environment_id !== $attribution->environmentId) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another Skill environment');
        }
        if ($attribution !== null && $invocation->data_class !== $attribution->dataClass) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another Skill data class');
        }
    }

    private function assertKnowledgeAttributionReplay(Invocation $invocation, ?KnowledgeAnswerAttribution $attribution): void
    {
        $actual = [
            (int) ($invocation->knowledge_publication_id ?? 0),
            (int) ($invocation->knowledge_base_version_id ?? 0),
            (string) ($invocation->knowledge_source_scope_sha256 ?? ''),
        ];
        $expected = $attribution === null
            ? [0, 0, '']
            : [$attribution->publicationId, $attribution->versionId, $attribution->sourceScopeSha256];
        if ($actual !== $expected || ($attribution !== null && $invocation->data_class !== $attribution->dataClass)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another knowledge source');
        }
    }

    /** @return array<string,mixed> */
    private function deploymentConfig(ModelDeployment $deployment): array
    {
        $config = is_object($deployment->config) ? (array) $deployment->config : $deployment->config;

        return is_array($config) ? $config : [];
    }

    /** @return array<string,string> */
    private function windowSnapshot(BudgetWindow $window): array
    {
        return [
            'currency' => (string) $window->currency, 'limit_amount' => (string) $window->limit_amount,
            'reserved_amount' => (string) $window->reserved_amount, 'settled_amount' => (string) $window->settled_amount,
            'held_amount' => (string) $window->held_amount,
        ];
    }

    /** @return array<string,string> */
    public function holdExpiredReservations(BudgetWindow $window, string $calledAt): array
    {
        $snapshot = $this->windowSnapshot($window);
        $expired = BudgetReservation::where('policy_id', (int) $window->policy_id)
            ->where('state', 'reserved')
            ->where('expires_at', '<=', $calledAt)
            ->lock(true)
            ->select();
        foreach ($expired as $reservation) {
            $result = $this->expireReservation($window, $reservation, $calledAt, $snapshot);
            $snapshot = $result['window'];
        }

        return $snapshot;
    }

    /** @param array<string,string>|null $windowSnapshot @return array{window:array<string,string>} */
    private function expireReservation(BudgetWindow $window, BudgetReservation $reservation, string $expiredAt, ?array $windowSnapshot = null): array
    {
        $transition = (new BudgetReservationExpiryPolicy())->expire(
            $windowSnapshot ?? $this->windowSnapshot($window),
            ['state' => (string) $reservation->state, 'estimate_amount' => (string) $reservation->estimate_amount,
                'metered_amount' => $reservation->metered_amount === null ? null : (string) $reservation->metered_amount, 'currency' => (string) $reservation->currency],
            null,
        );
        $this->persistWindow((int) $window->id, $transition['window']);
        self::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            "UPDATE sand_ai_budget_reservation SET state = 'held', metered_amount = NULL, reason = 'reservation_expired', expires_at = NULL, update_time = CURRENT_TIMESTAMP WHERE id = ? AND state = 'reserved' AND expires_at <= ?",
            [(int) $reservation->id, $expiredAt]
        ), 'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Budget reservation changed while holding an expired reservation');
        $this->event((int) $reservation->policy_id, (int) $reservation->id, $reservation->invocation_id === null ? null : (int) $reservation->invocation_id, 'hold', (string) $reservation->estimate_amount, (string) $reservation->currency, 'reservation_expired', [], $reservation->speech_run_id === null ? null : (int) $reservation->speech_run_id,
            $reservation->external_audio_dispatch_id === null ? null : (int) $reservation->external_audio_dispatch_id,
            $reservation->audio_task_run_id === null ? null : (int) $reservation->audio_task_run_id,
            $reservation->audio_execution_id === null ? null : (string) $reservation->audio_execution_id,
            $reservation->ocr_dispatch_id === null ? null : (int) $reservation->ocr_dispatch_id);

        return ['window' => $transition['window']];
    }

    /** @param array<string,string> $snapshot */
    private function persistWindow(int $windowId, array $snapshot): void
    {
        self::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            'UPDATE sand_ai_budget_window SET reserved_amount = CAST(? AS numeric(20,6)), settled_amount = CAST(? AS numeric(20,6)), held_amount = CAST(? AS numeric(20,6)), lock_version = lock_version + 1, update_time = CURRENT_TIMESTAMP WHERE id = ?',
            [$snapshot['reserved_amount'], $snapshot['settled_amount'], $snapshot['held_amount'], $windowId]
        ), 'SAND_AI_BUDGET_WINDOW_CHANGED', 'Budget window changed before persistence');
    }

    public static function requireExactlyOneAffectedRow(int $affected, string $errorCode, string $message): void
    {
        if ($affected !== 1) {
            throw new ApiProblem($errorCode, $message);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function event(int $policyId, ?int $reservationId, ?int $invocationId, string $type, ?string $amount, string $currency, ?string $reason, array $snapshot, ?int $speechRunId = null, ?int $externalAudioDispatchId = null, ?int $audioTaskRunId = null, ?string $audioExecutionId = null, ?int $ocrDispatchId = null): void
    {
        $idempotencyKey = $reservationId === null
            ? 'reject:' . $policyId . ':' . hash('sha256', (string) ($snapshot['request_id'] ?? ''))
            : 'reservation:' . $reservationId . ':' . $type;
        Db::connect('pgsql')->query(
            'INSERT INTO sand_ai_budget_event (policy_id, reservation_id, invocation_id, speech_run_id, external_audio_dispatch_id, audio_task_run_id, audio_execution_id, ocr_dispatch_id, event_type, amount, currency, reason, idempotency_key, snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS numeric(20,6)), ?, ?, ?, CAST(? AS jsonb)) ON CONFLICT (idempotency_key) DO NOTHING',
            [$policyId, $reservationId, $invocationId, $speechRunId, $externalAudioDispatchId, $audioTaskRunId, $audioExecutionId, $ocrDispatchId, $type, $amount, $currency, $reason, $idempotencyKey, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
    }
}
