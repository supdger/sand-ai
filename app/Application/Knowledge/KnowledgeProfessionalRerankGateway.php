<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\DispatchCoordinator;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\DispatchFinalizer;
use app\Application\Gateway\DispatchLedgerGateway;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderIdempotencyCapability;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageGateway;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\NeuronProfessionalRerankProvider;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** Dedicated professional rerank. Original reads never dispatch or settle. */
final class KnowledgeProfessionalRerankGateway
{
    public static function childRequestId(string $requestKey): string
    {
        return KnowledgeRerankGateway::childRequestId($requestKey);
    }

    /** @return list<array{model:string,display_name:string,rerank_mode:string}> */
    public function availableModels(int $environmentId): array
    {
        try {
            [, $model] = $this->route($environmentId, null);
            return [['model' => (string) $model->code, 'display_name' => (string) $model->getAttr('name'), 'rerank_mode' => 'professional']];
        } catch (ApiProblem) {
            return [];
        }
    }

    public function assertAvailable(int $environmentId, string $model): void
    {
        $this->route($environmentId, $model);
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public function rerank(
        int $environmentId, string $model, string $query, array $candidates, string $answerRequestId,
        UsageAttribution $attribution, KnowledgeAnswerAttribution $source, callable $authorizeInvocation,
    ): array {
        [$route, $modelRecord, $deployment, $provider, $driver] = $this->route($environmentId, $model);
        $this->assertInput($environmentId, $query, $candidates, $attribution);
        $requestId = self::childRequestId($answerRequestId);
        $fingerprint = $this->fingerprint($query, $candidates, $attribution, $source, $route, $driver);
        $existing = Invocation::where('request_id', $requestId)->find();
        if ($existing !== null) {
            return $this->readOriginal($environmentId, $model, $query, $candidates, $answerRequestId,
                $attribution, $source, $authorizeInvocation);
        }
        if (count($candidates) < 2) return $this->smallResult($candidates);
        $config = is_object($provider->encrypted_config) ? (array) $provider->encrypted_config : $provider->encrypted_config;
        if (!is_array($config)) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank configuration is unavailable');
        $runtime = (new ProviderSecretDecryptor())->decrypt($config);
        $payload = NeuronProfessionalRerankProvider::requestBody($driver, (string) $deployment->remote_model, $query, $candidates);
        $digest = hash('sha256', ProviderRequestBodyBuilder::bytes($payload));
        $scope = DispatchExecutionScope::knowledgeRerank($environmentId, $requestId, $fingerprint);
        $capability = new ProviderIdempotencyCapability($runtime['idempotency_mode'] ?? 'none', $runtime['idempotency_header'] ?? null);
        $key = $this->providerKey($capability, $scope->logicalExecutionId, $digest);
        $prepared = (new NeuronProfessionalRerankProvider())->prepare($driver, $runtime,
            (string) $deployment->remote_model, $query, $candidates, (int) $provider->timeout_ms,
            new ProviderDispatchContext($scope->logicalExecutionId, $digest, $key));
        $price = KnowledgeProfessionalRerankPrice::resolve((int) $provider->id, (int) $modelRecord->id,
            KnowledgeProfessionalRerankPrice::unitForDriver($driver), gmdate('Y-m-d H:i:s'));
        $receipts = $this->receipts();
        $dispatch = null;
        $budget = (new KnowledgeProfessionalRerankBudget())->prepare($requestId, $fingerprint, $attribution, $source,
            $price, $route, $digest, $prepared['capacity_proof'],
            min(3600, max(60, (int) ceil((int) $provider->timeout_ms / 1000) + 60)),
            function (Invocation $invocation, KnowledgeProfessionalRerankPrice $pricing) use (
                &$dispatch, $attribution, $scope, $requestId, $route, $digest, $capability, $key,
            ): void {
                $dispatch = (new DispatchCoordinator())->prepare([
                    'invocation_id' => (int) $invocation->id, 'environment_id' => $attribution->environmentId,
                    'execution_scope' => 'rerank', 'logical_execution_id' => $scope->logicalExecutionId,
                    'logical_request_id' => hash('sha256', $requestId), 'attempt_no' => 1,
                    'application_id' => $attribution->applicationId, 'service_action' => $attribution->serviceAction,
                    'source_kind' => $attribution->sourceKind, 'provider_id' => $route['provider_id'],
                    'deployment_id' => $route['deployment_id'], 'model_id' => $route['model_id'], 'capability_code' => 'rerank',
                    'route_snapshot' => $route, 'price_snapshot' => $pricing->dispatchSnapshot(),
                    'execution_snapshot_sha256' => $scope->executionSnapshotSha256, 'request_body_digest' => $digest,
                    'provider_idempotency_mode' => $capability->mode, 'provider_idempotency_key' => $key,
                    'lease_owner' => $scope->leaseOwner, 'lease_token' => $scope->leaseToken, 'lease_fence' => $scope->leaseFence,
                ]);
            });
        $invocation = $budget['invocation'];
        $reservationId = $budget['reservation_id'];
        if (!$dispatch instanceof InvocationDispatch) throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank preparation is incomplete');
        try {
            $authorizeInvocation((int) $invocation->id);
        } catch (ApiProblem $problem) {
            (new DispatchCoordinator())->finalizeNotDispatchedForScope((int) $dispatch->id, $invocation,
                $reservationId, $scope, $problem->errorCode, $problem->getMessage());
            throw $problem;
        }
        $claim = (new DispatchCoordinator())->claimForScope((int) $dispatch->id, $invocation, $reservationId,
            $scope, $provider, $deployment, (int) $modelRecord->id, 'rerank', $price->revisionId);
        if (!$claim->claimed) throw $claim->problem ?? new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank claim failed');
        $boundary = new ProviderSendBoundary();
        $started = microtime(true);
        try {
            $authorizeInvocation((int) $invocation->id);
            (new KnowledgeProfessionalRerankBudget())->assertSending((int) $invocation->id, (int) $dispatch->id,
                $route, $prepared['body_digest'], $prepared['capacity_proof'], $price);
            $outcome = (new DispatchCoordinator())->executeClaimedProvider(fn (): ProfessionalRerankOutcome => ($prepared['send'])($boundary));
        } catch (\Throwable $error) {
            $problem = $error instanceof ApiProblem ? $error : new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank failed', true);
            $evidence = ($prepared['usage_evidence'])();
            if (!$boundary->started()) {
                // A claimed request that never entered HTTP is provably unsent.
                $this->finish($invocation, $dispatch, $reservationId, $attribution, $price, $route, null, $problem, false);
            } else {
                $this->finish($invocation, $dispatch, $reservationId, $attribution, $price, $route,
                    $evidence instanceof ProfessionalRerankOutcome ? $evidence : null, $problem, true);
            }
            throw $problem;
        }
        try {
            $this->ordered($candidates, $outcome);
        } catch (ApiProblem $problem) {
            $this->finish($invocation, $dispatch, $reservationId, $attribution, $price, $route, $outcome, $problem, true);
            throw $problem;
        }
        $latency = (int) round((microtime(true) - $started) * 1000);
        $sealed = ['version' => 1, 'fingerprint' => $fingerprint, 'route' => $route,
            'price' => $price->dispatchSnapshot(), 'outcome' => $outcome->toArray(), 'latency_ms' => $latency];
        try {
            $receipts->write(json_encode($sealed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $this->receiptContext($invocation, $fingerprint), 262144);
        } catch (\Throwable) {
            // Real measured usage remains billable even if result sealing fails.
            $problem = new ApiProblem('SAND_AI_RECEIPT_PERSISTENCE_FAILED', 'Professional rerank receipt could not be sealed', true);
            $this->finish($invocation, $dispatch, $reservationId, $attribution, $price, $route, $outcome, $problem, true);
            throw $problem;
        }
        $this->finish($invocation, $dispatch, $reservationId, $attribution, $price, $route, $outcome, null, true, $latency);
        return $this->readOriginal($environmentId, $model, $query, $candidates, $answerRequestId, $attribution, $source, $authorizeInvocation);
    }

    /** SELECT-only delivery of a fully completed original, under current route/source authorization. */
    public function readOriginal(
        int $environmentId, string $model, string $query, array $candidates, string $answerRequestId,
        UsageAttribution $attribution, KnowledgeAnswerAttribution $source, callable $authorizeInvocation,
    ): array {
        [$route, , , , $driver] = $this->route($environmentId, $model);
        $this->assertInput($environmentId, $query, $candidates, $attribution);
        $fingerprint = $this->fingerprint($query, $candidates, $attribution, $source, $route, $driver);
        $requestId = self::childRequestId($answerRequestId);
        $invocation = Invocation::where('request_id', $requestId)->find();
        if ($invocation === null) {
            if (count($candidates) < 2) return $this->smallResult($candidates);
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original professional rerank is unavailable');
        }
        if ((int) $invocation->environment_id !== $environmentId
            || (int) $invocation->organization_id !== $attribution->organizationId
            || (int) $invocation->application_id !== $attribution->applicationId
            || (int) $invocation->workload_client_id !== $attribution->workloadClientId
            || (int) $invocation->knowledge_publication_id !== $source->publicationId
            || (int) $invocation->knowledge_base_version_id !== $source->versionId
            || (string) $invocation->knowledge_source_scope_sha256 !== $source->sourceScopeSha256
            || $invocation->data_class !== $source->dataClass
            || !is_string($invocation->request_fingerprint) || !hash_equals($invocation->request_fingerprint, $fingerprint)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original professional rerank intent changed');
        }
        $authorizeInvocation((int) $invocation->id);
        $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($dispatch !== null && in_array((string) $dispatch->state, ['dispatching', 'ambiguous', 'prepared'], true)) {
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original professional rerank is unresolved', true);
        }
        if ((string) $invocation->state !== 'succeeded') {
            $error = (string) $invocation->error_code;
            throw new ApiProblem(preg_match('/\ASAND_AI_[A-Z0-9_]+\z/D', $error) === 1 ? $error : 'SAND_AI_INVOCATION_NOT_SUCCEEDED', 'Original professional rerank did not succeed');
        }
        $storedRoute = $dispatch === null ? null : $dispatch->route_snapshot;
        $storedRoute = is_object($storedRoute) ? (array) $storedRoute : $storedRoute;
        if ($dispatch === null || (string) $dispatch->state !== 'succeeded' || (string) $dispatch->execution_scope !== 'rerank'
            || (string) $dispatch->capability_code !== 'rerank' || (string) $dispatch->source_kind !== 'knowledge_query'
            || (string) $dispatch->service_action !== 'sand_ai.knowledge.query'
            || (int) $dispatch->environment_id !== $environmentId || (int) $dispatch->application_id !== $attribution->applicationId
            || (int) $dispatch->model_id !== (int) $invocation->model_id || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || !is_array($storedRoute) || !RequestPayloadFingerprint::same($storedRoute, $route, [])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original professional rerank dispatch changed');
        }
        $sealed = json_decode($this->receipts()->replay($this->receiptContext($invocation, $fingerprint)), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($sealed) || ($sealed['version'] ?? null) !== 1 || ($sealed['fingerprint'] ?? null) !== $fingerprint
            || !is_array($sealed['outcome'] ?? null) || !is_array($sealed['route'] ?? null)
            || !RequestPayloadFingerprint::same($sealed['route'], $route, []) || !is_int($sealed['latency_ms'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original professional rerank receipt changed');
        }
        $price = KnowledgeProfessionalRerankPrice::fromPersistence($invocation->call_started_at,
            $invocation->call_start_pricing_state, $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot);
        $dispatchPrice = is_object($dispatch->price_snapshot) ? (array) $dispatch->price_snapshot : $dispatch->price_snapshot;
        if (!is_array($sealed['price'] ?? null) || !is_array($dispatchPrice)
            || !RequestPayloadFingerprint::same($sealed['price'], $price->dispatchSnapshot(), [])
            || !RequestPayloadFingerprint::same($dispatchPrice, $price->dispatchSnapshot(), [])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original professional rerank price changed');
        }
        $value = $sealed['outcome'];
        $outcome = new ProfessionalRerankOutcome($value['rankings'] ?? [], $value['metering_unit'] ?? '',
            $value['metering_quantity'] ?? null, $value['usage_measured'] ?? false, $value['http_responded'] ?? false, $value['http_status'] ?? null);
        if ($outcome->meteringUnit !== $price->meteringUnit) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original professional rerank meter changed');
        $citations = $this->ordered($candidates, $outcome);
        $usage = (new UsageGateway())->invocationCost($environmentId, $requestId,
            function (Invocation $read) use ($invocation, $authorizeInvocation): void {
                if ((int) $read->id !== (int) $invocation->id || (string) $read->state !== 'succeeded') {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original professional rerank usage is unavailable');
                }
                $authorizeInvocation((int) $read->id);
            });
        if (($usage['usage']['state'] ?? null) === 'missing_event') throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Original professional rerank usage is unavailable', true);
        $quantity = $usage['usage']['metering_quantity'] ?? null;
        if (($usage['usage']['metering_unit'] ?? null) !== $outcome->meteringUnit
            || ($outcome->meteringQuantity === null ? $quantity !== null
                : (!is_string($quantity) && !is_int($quantity))
                    || \app\Application\Budget\BudgetDecimal::compare((string) $quantity, (string) $outcome->meteringQuantity) !== 0)
            || ($usage['usage']['state'] ?? null) !== ($outcome->usageMeasured ? 'measured' : 'unmeasured')
            || ($price->amount($outcome->meteringQuantity) === null
                ? ($usage['price']['metered_amount'] ?? null) !== null
                : !is_string($usage['price']['metered_amount'] ?? null)
                    || \app\Application\Budget\BudgetDecimal::compare($usage['price']['metered_amount'], $price->amount($outcome->meteringQuantity)) !== 0)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original professional rerank usage differs from its receipt');
        }
        $authorizeInvocation((int) $invocation->id);
        return ['citations' => $citations, 'invocation' => ['request_id' => $requestId, 'state' => 'succeeded', 'latency_ms' => $sealed['latency_ms']],
            'usage' => $usage, 'state' => 'reordered', 'budget' => (new KnowledgeProfessionalRerankSoftBudget())->projection((int) $invocation->id)];
    }

    private function finish(Invocation $invocation, InvocationDispatch $dispatch, ?int $reservationId,
        UsageAttribution $attribution, KnowledgeProfessionalRerankPrice $price, array $route,
        ?ProfessionalRerankOutcome $outcome, ?ApiProblem $failure, bool $sent, ?int $latency = null): void
    {
        (new DispatchFinalizer())->success(function () use ($invocation, $dispatch, $reservationId, $attribution, $price, $route, $outcome, $failure, $sent, $latency): void {
            $softBudget = new KnowledgeProfessionalRerankSoftBudget();
            $softBudget->lockForInvocation((int) $invocation->id);
            $terminal = (new DispatchFinalizer())->lockKnowledgeTerminal($reservationId, (int) $invocation->id, (int) $dispatch->id);
            if ((string) $terminal['invocation']->state !== 'running' || (string) $terminal['dispatch']->state !== 'dispatching') {
                throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Professional rerank terminal changed', true);
            }
            $measured = $outcome !== null && $outcome->usageMeasured;
            if ($sent) {
                $usage = (new KnowledgeProfessionalRerankUsage())->record($terminal['invocation'], $attribution, $price, $route,
                    $measured ? $outcome->meteringQuantity : null);
                if ($reservationId !== null) (new ChatBudgetGuard())->settle($reservationId, $usage['metered_amount'],
                    $usage['metered_amount'] === null ? 'rerank_usage_unknown_or_unpriced' : 'rerank_usage_measured', $usage['price_revision_id'], 'rerank');
            } elseif ($reservationId !== null) {
                (new ChatBudgetGuard())->release($reservationId, 'rerank_not_dispatched');
            }
            $softBudget->finish((int) $invocation->id, $sent ? ($usage['metered_amount'] ?? null) : null, $price->revisionId, $sent);
            $state = $failure === null ? 'succeeded' : ($sent && !$measured ? 'ambiguous' : 'failed');
            (new DispatchLedgerGateway())->terminal((int) $dispatch->id, $state, $failure?->errorCode, $failure?->getMessage());
            $terminal['invocation']->save(['state' => $failure === null ? 'succeeded' : 'failed', 'latency_ms' => $latency,
                'error_code' => $state === 'ambiguous' ? 'SAND_AI_DISPATCH_AMBIGUOUS' : $failure?->errorCode,
                'error_summary' => $failure?->getMessage(), 'completed_at' => gmdate('Y-m-d H:i:s')]);
        });
    }

    /** @return array{0:array,1:AiModel,2:ModelDeployment,3:Provider,4:string} */
    private function route(int $environmentId, ?string $modelCode): array
    {
        $selected = (new CapabilityProfileResolver())->primary($environmentId, 'rerank');
        if ($selected === null) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Professional rerank route is not published');
        $profile = CapabilityProfile::where('id', (int) $selected['profile_id'])->where('environment_id', $environmentId)->where('state', 'published')->where('status', 1)->find();
        $route = CapabilityRoute::where('id', (int) $selected['route_id'])->where('profile_id', (int) $selected['profile_id'])->where('capability_code', 'rerank')->where('status', 1)->find();
        $deployment = ModelDeployment::where('id', (int) $selected['deployment_id'])->where('status', 1)->find();
        $model = AiModel::where('id', (int) $selected['model_id'])->where('type', 'rerank')->where('status', 1)->find();
        $provider = Provider::where('id', (int) $selected['provider_id'])->where('status', 1)->find();
        if ($profile === null || $route === null || $deployment === null || $model === null || $provider === null
            || ($modelCode !== null && (string) $model->code !== $modelCode)
            || (int) $deployment->model_id !== (int) $model->id || (int) $deployment->provider_id !== (int) $provider->id) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Professional rerank model is unavailable');
        }
        $driver = (string) $provider->adapter;
        if (!is_string($driver) || !in_array($driver, ['cohere_rerank', 'jina_rerank'], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Professional rerank driver is unavailable');
        }
        $revision = ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)->where('state', 'published')->where('status', 1)->find();
        $profileRevision = ConfigRevision::where('resource_type', 'capability_profile')->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)->where('state', 'published')->where('status', 1)->find();
        if ($revision === null || $profileRevision === null) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Professional rerank revisions are unavailable');
        return [DispatchRouteSnapshot::freeze($profile, $route, $deployment, $model, $provider, $revision, $profileRevision), $model, $deployment, $provider, $driver];
    }

    private function assertInput(int $environmentId, string $query, array $candidates, UsageAttribution $attribution): void
    {
        if ($attribution->environmentId !== $environmentId || $attribution->sourceKind !== 'knowledge_query'
            || $attribution->serviceAction !== 'sand_ai.knowledge.query' || $attribution->capabilityCode !== 'rerank'
            || trim($query) === '' || strlen($query) > 4000 || preg_match('//u', $query) !== 1
            || !array_is_list($candidates) || count($candidates) > 10) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Professional rerank input is invalid');
        }
        $seen = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['source_block_id'] ?? null; $excerpt = $candidate['excerpt'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || !is_string($excerpt) || strlen($excerpt) > 500 || preg_match('//u', $excerpt) !== 1) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Professional rerank current source is invalid');
            }
            $seen[$id] = true;
        }
    }

    private function fingerprint(string $query, array $candidates, UsageAttribution $attribution, KnowledgeAnswerAttribution $source, array $route, string $driver): string
    {
        return RequestPayloadFingerprint::fingerprint(['rerank_mode' => 'professional', 'protocol' => NeuronProfessionalRerankProvider::PROTOCOL,
            'query' => $query, 'candidates' => $candidates, 'identity' => $attribution->toPersisted(),
            'source' => ['publication_id' => $source->publicationId, 'version_id' => $source->versionId, 'source_scope_sha256' => $source->sourceScopeSha256, 'data_class' => $source->dataClass],
            'service_action' => $attribution->serviceAction, 'source_kind' => $attribution->sourceKind, 'driver' => $driver, 'route' => $route]);
    }

    private function ordered(array $candidates, ProfessionalRerankOutcome $outcome): array
    {
        $byId = [];
        foreach ($candidates as $candidate) $byId[$candidate['source_block_id']] = $candidate;
        if (count($outcome->rankings) !== count($byId)) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank omitted a current source', true);
        $ordered = [];
        foreach ($outcome->rankings as $ranking) {
            $id = $ranking['source_block_id'];
            if (!isset($byId[$id])) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank changed a current source', true);
            $ordered[] = $byId[$id] + ['rerank_relevance_score' => $ranking['relevance_score']];
            unset($byId[$id]);
        }
        return $ordered;
    }

    private function smallResult(array $candidates): array
    {
        return ['citations' => $candidates, 'invocation' => null, 'usage' => null, 'budget' => null,
            'budget' => null, 'state' => $candidates === [] ? 'no_sources' : 'single_source'];
    }

    private function providerKey(ProviderIdempotencyCapability $capability, string $logicalId, string $digest): ?string
    {
        if ($capability->mode === 'none') return null;
        $secret = env('SAND_AI_DISPATCH_IDEMPOTENCY_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider idempotency secret is unavailable');
        return hash_hmac('sha256', $logicalId . ':' . $digest, $secret);
    }

    private function receipts(): OutcomeReceiptService
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Professional rerank receipt configuration is unavailable');
        return new OutcomeReceiptService(new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), $config['ttl_seconds'] ?? 0);
    }

    private function receiptContext(Invocation $invocation, string $fingerprint): array
    {
        return ['environment_id' => (int) $invocation->environment_id, 'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => $fingerprint, 'capability_code' => 'rerank', 'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id, 'version' => OutcomeReceiptCipher::RECEIPT_VERSION];
    }
}
