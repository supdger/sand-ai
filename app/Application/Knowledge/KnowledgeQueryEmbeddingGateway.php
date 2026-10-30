<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\DispatchCoordinator;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\DispatchFinalizer;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptRequest;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderDispatchTerminalizationPolicy;
use app\Application\Gateway\ProviderIdempotencyCapability;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageGateway;
use app\Application\Usage\UsageMeter;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Provider\OpenAiCompatibleEmbeddingProvider;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use think\facade\Db;

/** Billed query vector for the current private index; never returns a vector to an HTTP caller. */
final class KnowledgeQueryEmbeddingGateway
{
    /**
     * Deliver only a completed original vector. This path never finalizes a
     * sealed running result, expires a prepared reservation or dispatches.
     * @param array<string,mixed> $publication
     * @param callable(int):void $authorizeInvocation
     * @return array{index_id:int,vector:list<float>,invocation:array<string,mixed>,usage:array<string,mixed>,idempotent_replay:bool}
     */
    public function readOriginal(
        array $publication, string $query, string $requestKey,
        UsageAttribution $attribution, callable $authorizeInvocation,
    ): array {
        $requestId = self::childRequestId($attribution->environmentId, (int) $publication['id'], $requestKey);
        $invocation = Invocation::where('request_id', $requestId)->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original knowledge query vector is unavailable');
        }
        if ($attribution->sourceKind !== 'knowledge_query'
            || $attribution->serviceAction !== 'sand_ai.knowledge.query'
            || $attribution->capabilityCode !== 'embedding'
            || (int) $invocation->environment_id !== $attribution->environmentId
            || (int) $invocation->organization_id !== $attribution->organizationId
            || (int) $invocation->application_id !== $attribution->applicationId
            || (int) $invocation->workload_client_id !== $attribution->workloadClientId
            || (int) $invocation->knowledge_publication_id !== (int) $publication['id']
            || (int) $invocation->knowledge_base_version_id !== (int) $publication['active_version_id']
            || (string) $invocation->knowledge_source_scope_sha256 !== (string) $publication['source_scope_sha256']
            || $invocation->data_class !== ($publication['data_class'] ?? null)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original knowledge query vector scope changed');
        }
        $authorizeInvocation((int) $invocation->id);
        $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ((string) $invocation->state !== 'succeeded') {
            if ((string) $invocation->state === 'failed') {
                $error = is_string($invocation->error_code)
                    && preg_match('/\ASAND_AI_[A-Z0-9_]+\z/D', $invocation->error_code) === 1
                    ? $invocation->error_code : 'SAND_AI_INVOCATION_NOT_SUCCEEDED';
                throw new ApiProblem($error, 'Original knowledge query vector did not succeed');
            }
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original knowledge query vector is still unresolved', true);
        }
        if ($dispatch === null || (string) $dispatch->state !== 'succeeded'
            || (string) $dispatch->execution_scope !== 'embedding'
            || (string) $dispatch->source_kind !== 'knowledge_query'
            || (string) $dispatch->service_action !== 'sand_ai.knowledge.query'
            || (int) $dispatch->environment_id !== $attribution->environmentId
            || (int) $dispatch->application_id !== $attribution->applicationId
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query vector dispatch is not complete');
        }
        $outcome = KnowledgeQueryEmbeddingOutcome::decode(
            $this->receipts()->replay($this->receiptContext($invocation, (string) $invocation->request_fingerprint)),
        );
        if ($outcome->attribution !== $attribution->toPersisted() + [
            'source_kind' => $attribution->sourceKind, 'service_action' => $attribution->serviceAction,
        ]) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query vector attribution changed');
        }
        $this->assertOutcome($publication, hash('sha256', $query), $invocation, $dispatch, $outcome);
        return $this->response($publication, $outcome, $invocation, true, $authorizeInvocation);
    }

    public static function childRequestId(int $consumerEnvironmentId, int $publicationId, string $requestKey): string
    {
        if ($consumerEnvironmentId < 1 || $publicationId < 1 || $requestKey === '') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge query request identity is invalid');
        }
        return 'knowledge-query-embedding:' . hash(
            'sha256', $consumerEnvironmentId . ':' . $publicationId . ':' . $requestKey,
        );
    }

    /**
     * A former vector request cannot silently become a lexical request after
     * its index is replaced or removed. Only the original unsent budget may
     * be closed here; no vector result or Provider call is recovered.
     *
     * @param array<string,mixed> $publication
     * @param callable(int):void $authorizeInvocation
     */
    public function assertNoOrphanedOriginalKey(
        array $publication, string $requestKey, UsageAttribution $attribution,
        callable $authorizeInvocation,
    ): void {
        $requestId = self::childRequestId(
            $attribution->environmentId, (int) ($publication['id'] ?? 0), $requestKey,
        );
        $existing = Invocation::where('request_id', $requestId)->find();
        if ($existing === null) return;
        if ((int) $existing->environment_id !== $attribution->environmentId
            || (int) $existing->knowledge_publication_id !== (int) $publication['id']
            || (int) $existing->knowledge_base_version_id !== (int) $publication['active_version_id']
            || (int) $existing->organization_id !== $attribution->organizationId
            || (int) $existing->application_id !== $attribution->applicationId
            || (int) $existing->workload_client_id !== $attribution->workloadClientId
            || (string) $existing->knowledge_source_scope_sha256 !== (string) $publication['source_scope_sha256']
            || $existing->data_class !== ($publication['data_class'] ?? null)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Knowledge query embedding request changed');
        }
        $authorizeInvocation((int) $existing->id);
        (new EmbeddingPreparedExpiryRecoveryService())->recoverInvocation((int) $existing->id);
        $current = Invocation::where('id', (int) $existing->id)->find();
        if ($current !== null && (string) $current->state === 'failed'
            && (string) $current->error_code === 'SAND_AI_BUDGET_RESERVATION_EXPIRED') {
            throw new ApiProblem(
                'SAND_AI_BUDGET_RESERVATION_EXPIRED',
                'Budget reservation expired before provider dispatch',
            );
        }
        throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Original query vector index is no longer active');
    }

    /** Settle a sealed provider result without relying on current publication or grant state. */
    public function recoverSealed(int $invocationId): void
    {
        if ($invocationId < 1) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query recovery identity is invalid');
        }
        $invocation = Invocation::where('id', $invocationId)->find();
        $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->find();
        if ($invocation === null || $dispatch === null
            || (string) $dispatch->execution_scope !== 'embedding'
            || (string) $dispatch->source_kind !== 'knowledge_query'
            || (string) $dispatch->service_action !== 'sand_ai.knowledge.query'
            || (int) $dispatch->environment_id !== (int) $invocation->environment_id) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query recovery facts are invalid');
        }
        if ((string) $invocation->state === 'succeeded'
            && (string) $dispatch->state === 'succeeded') return;
        if ((string) $invocation->state !== 'running'
            || (string) $dispatch->state !== 'dispatching') {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query outcome is not recoverable');
        }
        $context = $this->receiptContext($invocation, (string) $invocation->request_fingerprint);
        $receipts = $this->receipts();
        if ($receipts->probe($invocationId, $context) !== 'valid') {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector receipt is unavailable');
        }
        $outcome = KnowledgeQueryEmbeddingOutcome::decode($receipts->replay($context));
        $frozenPublication = [
            'id' => (int) $invocation->knowledge_publication_id,
            'active_version_id' => (int) $invocation->knowledge_base_version_id,
        ];
        $this->finalize(
            $frozenPublication, $outcome->querySha256, $invocation, $dispatch, $outcome,
        );
    }

    /**
     * @param array<string,mixed> $publication Current authorized publication.
     * @param callable(int):void $authorizeInvocation Rechecks SandIAM before send and replay.
     * @return array{index_id:int,vector:list<float>,invocation:array<string,mixed>,usage:array<string,mixed>,idempotent_replay:bool}
     */
    public function embed(
        array $publication, string $query, string $requestKey,
        UsageAttribution $attribution, callable $authorizeInvocation,
        ?TaskExecutionLease $parentLease = null,
    ): array {
        $normalized = KnowledgeBasePolicy::normalizeQuery(['query' => $query, 'limit' => 1]);
        $query = $normalized['query'];
        if ($requestKey === '' || strlen($requestKey) > 160
            || $attribution->sourceKind !== 'knowledge_query'
            || $attribution->serviceAction !== 'sand_ai.knowledge.query'
            || $attribution->capabilityCode !== 'embedding'
            || $attribution->environmentId !== (int) ($publication['consumer_environment_id'] ?? 0)
            || $attribution->organizationId !== (int) ($publication['consumer_organization_id'] ?? 0)
            || $attribution->applicationId !== (int) ($publication['consumer_application_id'] ?? 0)
            || $attribution->workloadClientId === null) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Knowledge query attribution is invalid');
        }
        $publicationId = (int) $publication['id'];
        $versionId = (int) $publication['active_version_id'];
        $querySha256 = hash('sha256', $query);
        $requestId = self::childRequestId($attribution->environmentId, $publicationId, $requestKey);
        $receipts = $this->receipts();
        $receipts->preflight('embedding', new OutcomeReceiptRequest(262144));
        $existing = Invocation::where('request_id', $requestId)->find();
        if ($existing !== null) {
            if ((int) $existing->environment_id !== $attribution->environmentId
                || (int) $existing->knowledge_publication_id !== $publicationId
                || (int) $existing->knowledge_base_version_id !== $versionId
                || (int) $existing->organization_id !== $attribution->organizationId
                || (int) $existing->application_id !== $attribution->applicationId
                || (int) $existing->workload_client_id !== $attribution->workloadClientId
                || !hash_equals(
                    (string) $existing->knowledge_source_scope_sha256,
                    (string) $publication['source_scope_sha256'],
                )
                || $existing->data_class !== ($publication['data_class'] ?? null)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Knowledge query embedding request changed');
            }
            $dispatch = InvocationDispatch::where('invocation_id', (int) $existing->id)->find();
            if ($dispatch === null) {
                if ((string) $existing->state === 'failed'
                    && is_string($existing->error_code)
                    && preg_match('/\ASAND_AI_[A-Z0-9_]+\z/D', $existing->error_code) === 1) {
                    throw new ApiProblem((string) $existing->error_code, 'Knowledge query embedding was rejected');
                }
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query embedding dispatch is missing');
            }
            $context = $this->receiptContext($existing, (string) $existing->request_fingerprint);
            $probe = $receipts->probe((int) $existing->id, $context);
            if ($probe === 'invalid') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector receipt is invalid');
            }
            if ($probe === 'valid') {
                $outcome = KnowledgeQueryEmbeddingOutcome::decode($receipts->replay($context));
                if ((string) $existing->state === 'succeeded') {
                    $this->assertOutcome($publication, $querySha256, $existing, $dispatch, $outcome);
                    if ((string) $dispatch->state !== 'succeeded') {
                        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector dispatch is not complete');
                    }
                    return $this->response($publication, $outcome, $existing, true, $authorizeInvocation);
                }
                if ((string) $dispatch->state === 'dispatching') {
                    try {
                        $this->finalize($publication, $querySha256, $existing, $dispatch, $outcome);
                    } catch (\Throwable) {
                        throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Knowledge query vector is durably recoverable', true);
                    }
                    return $this->response($publication, $outcome, $existing, true, $authorizeInvocation);
                }
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query receipt has no recoverable dispatch');
            }
            if ((string) $existing->state === 'succeeded') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector receipt is no longer available');
            }
            if ((string) $dispatch->state === 'dispatching' || (string) $dispatch->state === 'ambiguous') {
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Knowledge query vector is still unresolved', true);
            }
            if ((string) $dispatch->state !== 'prepared' || (string) $existing->state !== 'running') {
                if ((string) $dispatch->state === 'not_dispatched'
                    && (string) $existing->state === 'failed'
                    && (string) $existing->error_code === 'SAND_AI_BUDGET_RESERVATION_EXPIRED') {
                    $authorizeInvocation((int) $existing->id);
                    throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
                }
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge query embedding cannot be dispatched again');
            }
        }
        $index = (new KnowledgeEmbeddingIndexGateway())->currentIndex($publication);
        (new KnowledgeBaseQueryGateway())->query($publication, ['query' => $query, 'limit' => 1]);
        $indexId = (int) $index['id'];
        $fingerprintPayload = [
            'consumer' => $attribution->canonicalTrustedIdentity(),
            'publication_id' => $publicationId,
            'version_id' => $versionId,
            'source_scope_sha256' => (string) $publication['source_scope_sha256'],
            'data_class' => $publication['data_class'] ?? null,
            'index_id' => $indexId,
            'index_snapshot_sha256' => (string) $index['source_snapshot_sha256'],
            'profile_id' => (int) $index['profile_id'],
            'profile_revision' => (int) $index['profile_revision'],
            'deployment_id' => (int) $index['deployment_id'],
            'deployment_revision' => (int) $index['deployment_revision'],
            'provider_config_sha256' => (string) $index['provider_config_sha256'],
            'query_sha256' => $querySha256,
        ];
        $legacyFingerprint = RequestPayloadFingerprint::fingerprint($fingerprintPayload);
        $protocol = OpenAiCompatibleEmbeddingProvider::PROTOCOL_NEURON;
        $fingerprint = RequestPayloadFingerprint::fingerprint([
            ...$fingerprintPayload, 'embedding_request_protocol' => $protocol,
        ]);
        // Prepared historical requests must match all original identity,
        // query and index facts before retaining the old request wire shape.
        if ($existing !== null
            && hash_equals((string) $existing->request_fingerprint, $legacyFingerprint)) {
            $protocol = OpenAiCompatibleEmbeddingProvider::PROTOCOL_LEGACY;
            $fingerprint = $legacyFingerprint;
        }
        $scope = DispatchExecutionScope::knowledgeQueryEmbedding(
            $attribution->environmentId, $publicationId, $indexId, $requestId, $fingerprint,
        );
        if ($existing !== null) {
            if (!hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Knowledge query embedding request changed');
            }
            if ((string) $existing->state === 'running'
                && (string) $dispatch->state === 'prepared') {
                $authorizeInvocation((int) $existing->id);
                if ((new DispatchCoordinator())->rejectExpiredPreparedForScope(
                    $existing, $scope, $attribution,
                )) {
                    throw new ApiProblem(
                        'SAND_AI_BUDGET_RESERVATION_EXPIRED',
                        'Budget reservation expired before provider dispatch',
                    );
                }
            }
        }
        [$profile, $route, $deployment, $model, $provider, $routeSnapshot] =
            (new KnowledgeEmbeddingBuildGateway())->routeForIndex($index);
        $config = is_object($provider->encrypted_config)
            ? (array) $provider->encrypted_config : $provider->encrypted_config;
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Embedding provider configuration is unavailable');
        }
        $runtimeConfig = (new ProviderSecretDecryptor())->decrypt($config);
        $payload = OpenAiCompatibleEmbeddingProvider::requestBody(
            (string) $deployment->remote_model, [$query], $protocol,
        );
        $body = ProviderRequestBodyBuilder::bytes($payload);
        $digest = hash('sha256', $body);
        $capability = ProviderIdempotencyCapability::fromServerConfig('openai_compatible', $runtimeConfig);
        $providerKey = $this->providerKey($capability, $scope->logicalExecutionId, $digest);
        $dispatchContext = new ProviderDispatchContext($scope->logicalExecutionId, $digest, $providerKey);
        $source = KnowledgeAnswerAttribution::fromPublication($publication);
        $preparedDispatch = null;
        $budget = (new ChatBudgetGuard())->prepare(
            $attribution->environmentId, (int) $model->id, $deployment,
            [['role' => 'user', 'content' => $query]], new ChatCompletionOptions(null),
            $requestId, $fingerprint,
            min(3600, max(60, (int) ceil((int) $provider->timeout_ms / 1000) + 60)),
            'embedding', null,
            function (Invocation $invocation, CallStartPricingSnapshot $pricing) use (
                &$preparedDispatch, $publication, $indexId, $scope, $requestId,
                $attribution, $provider, $deployment, $model, $routeSnapshot,
                $digest, $capability, $providerKey,
            ): void {
                $this->assertPublishedIndex((int) $publication['id'], (int) $publication['active_version_id'], $indexId);
                $preparedDispatch = (new DispatchCoordinator())->prepare([
                    'invocation_id' => (int) $invocation->id,
                    'environment_id' => $attribution->environmentId,
                    'execution_scope' => $scope->scope,
                    'logical_execution_id' => $scope->logicalExecutionId,
                    'logical_request_id' => hash('sha256', $requestId),
                    'attempt_no' => $scope->attemptNo,
                    'application_id' => $attribution->applicationId,
                    'service_action' => $attribution->serviceAction,
                    'source_kind' => $attribution->sourceKind,
                    'provider_id' => (int) $provider->id,
                    'deployment_id' => (int) $deployment->id,
                    'model_id' => (int) $model->id,
                    'capability_code' => 'embedding',
                    'route_snapshot' => $routeSnapshot,
                    'price_snapshot' => $pricing->dispatchSnapshot(),
                    'execution_snapshot_sha256' => $scope->executionSnapshotSha256,
                    'request_body_digest' => $digest,
                    'provider_idempotency_mode' => $capability->mode,
                    'provider_idempotency_key' => $providerKey,
                    'lease_owner' => $scope->leaseOwner,
                    'lease_token' => $scope->leaseToken,
                    'lease_fence' => $scope->leaseFence,
                ]);
            },
            attribution: $attribution,
            knowledgeAnswerAttribution: $source,
            providerRequestBody: $payload,
            providerRouteSnapshot: $routeSnapshot,
            providerRequestProtocol: $protocol,
        );
        $invocation = $budget['invocation'];
        $this->authorizePrepared($authorizeInvocation, $invocation, $budget['reservation_id'], $scope);
        if (($budget['idempotent_replay'] ?? false) === true) {
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Knowledge query vector is still unresolved', true);
        }
        $dispatch = $preparedDispatch ?? InvocationDispatch::where('invocation_id', (int) $invocation->id)
            ->where('state', 'prepared')->find();
        if ($dispatch === null || !hash_equals((string) $dispatch->request_body_digest, $digest)
            || (string) $dispatch->provider_idempotency_key !== (string) $providerKey) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query embedding request changed after preparation');
        }
        $reservationId = $budget['reservation_id'];
        try {
            $prepared = (new OpenAiCompatibleEmbeddingProvider())->prepare(
                $runtimeConfig, (string) $deployment->remote_model, [$query],
                (int) $provider->timeout_ms, $dispatchContext, $protocol,
            );
        } catch (ApiProblem $problem) {
            (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                (int) $dispatch->id, $invocation, $reservationId, $scope,
                $problem->errorCode, $problem->getMessage(),
            );
            throw $problem;
        }
        $this->authorizePrepared($authorizeInvocation, $invocation, $reservationId, $scope);
        $claim = (new DispatchCoordinator())->claimForScope(
            (int) $dispatch->id, $invocation, $reservationId, $scope,
            $provider, $deployment, (int) $model->id, 'embedding', $budget['price_revision_id'],
            $parentLease,
        );
        if (!$claim->claimed || $claim->provider === null || $claim->deployment === null) {
            throw $claim->problem ?? new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query embedding claim was rejected');
        }
        $boundary = new ProviderSendBoundary();
        $startedAt = microtime(true);
        try {
            $result = (new DispatchCoordinator())->executeClaimedProvider(
                fn (): array => $prepared->send($boundary),
            );
        } catch (ApiProblem $problem) {
            $this->finalizeFailure($dispatch, $invocation, $reservationId, $scope, $problem, $boundary);
            throw $problem;
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Knowledge query embedding provider failed', true);
            $this->finalizeFailure($dispatch, $invocation, $reservationId, $scope, $problem, $boundary);
            throw $problem;
        }
        try {
            $outcome = KnowledgeQueryEmbeddingOutcome::fromProvider(
                $publicationId, $versionId, $indexId, $querySha256, $result,
                (int) round((microtime(true) - $startedAt) * 1000),
                $attribution, $claim->provider, $model, $claim->deployment, $routeSnapshot,
            );
            $receipts->write($outcome->encode(), $this->receiptContext($invocation, $fingerprint), 262144);
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_RECEIPT_PERSISTENCE_FAILED', 'Knowledge query vector could not be sealed', true);
            $this->finalizeFailure($dispatch, $invocation, $reservationId, $scope, $problem, $boundary);
            throw $problem;
        }
        try {
            $this->finalize($publication, $querySha256, $invocation, $dispatch, $outcome);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Knowledge query vector is durably recoverable', true);
        }
        return $this->response($publication, $outcome, $invocation, false, $authorizeInvocation);
    }

    /** @param array<string,mixed> $publication */
    private function finalize(
        array $publication, string $querySha256,
        Invocation $invocation, InvocationDispatch $dispatch,
        KnowledgeQueryEmbeddingOutcome $outcome,
    ): void {
        $this->assertOutcome($publication, $querySha256, $invocation, $dispatch, $outcome);
        $reservation = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
        $reservationId = $reservation === null ? null : (int) $reservation->id;
        $pricing = CallStartPricingSnapshot::fromPersistence(
            $invocation->call_started_at, $invocation->call_start_pricing_state,
            $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
        );
        (new DispatchFinalizer())->success(function () use (
            $invocation, $dispatch, $outcome, $reservationId, $pricing,
        ): void {
            $terminal = (new DispatchFinalizer())->lockKnowledgeTerminal(
                $reservationId, (int) $invocation->id, (int) $dispatch->id,
            );
            $locked = $terminal['invocation'];
            if ((string) $locked->state === 'succeeded'
                && (string) $terminal['dispatch']->state === 'succeeded') {
                $event = Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_usage_event WHERE invocation_id=? LIMIT 1',
                    [(int) $locked->id],
                );
                if (count($event) !== 1) {
                    throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Settled query usage event is unavailable');
                }
                return;
            }
            if ((string) $locked->state !== 'running'
                || (string) $terminal['dispatch']->state !== 'dispatching') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query dispatch cannot be recovered');
            }
            $locked->save([
                'state' => 'succeeded', 'latency_ms' => $outcome->latencyMs,
                'completed_at' => gmdate('Y-m-d H:i:s'), 'error_code' => null,
                'error_summary' => null,
            ]);
            $attribution = UsageAttribution::fromCurrentPersisted(
                $outcome->attribution, (int) $locked->environment_id,
                'knowledge_query', 'sand_ai.knowledge.query',
            );
            $usage = (new UsageMeter())->meterFrozen(
                $locked, $attribution, $outcome->inputTokens, 0,
                $outcome->usageMeasured, $pricing,
                $outcome->provider, $outcome->model, $outcome->deployment,
            );
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_usage
                 (invocation_id,input_tokens,output_tokens,total_tokens,cost_amount,currency,source)
                 VALUES (?,?,0,?,CAST(? AS numeric(20,6)),?,?)',
                [(int) $locked->id, $outcome->inputTokens, $outcome->inputTokens,
                    $usage['metered_amount'], $usage['currency'], 'openai_compatible'],
            );
            if ($reservationId !== null) {
                (new ChatBudgetGuard())->settle(
                    $reservationId, $usage['metered_amount'],
                    $usage['metered_amount'] === null
                        ? 'usage_unmeasured_or_unpriced' : 'usage_metered',
                    $usage['metered_amount'] === null ? null : $pricing->priceRevisionId,
                    'embedding',
                );
            }
            (new DispatchCoordinator())->finalizeSuccessForScope(
                (int) $dispatch->id, null, ProviderDispatchTerminalizationPolicy::succeeded(),
            );
        });
    }

    /** @param array<string,mixed> $publication */
    private function assertOutcome(
        array $publication, string $querySha256,
        Invocation $invocation, InvocationDispatch $dispatch,
        KnowledgeQueryEmbeddingOutcome $outcome,
    ): void {
        if ($outcome->publicationId !== (int) $publication['id']
            || $outcome->versionId !== (int) $publication['active_version_id']
            || !hash_equals($outcome->querySha256, $querySha256)
            || (int) ($outcome->attribution['environment_id'] ?? 0) !== (int) $invocation->environment_id
            || (int) ($outcome->attribution['organization_id'] ?? 0) !== (int) $invocation->organization_id
            || (int) ($outcome->attribution['application_id'] ?? 0) !== (int) $invocation->application_id
            || (int) ($outcome->attribution['workload_client_id'] ?? 0) !== (int) $invocation->workload_client_id
            || (int) ($outcome->model['id'] ?? 0) !== (int) $invocation->model_id
            || (int) ($outcome->deployment['id'] ?? 0) !== (int) $invocation->deployment_id
            || (int) ($outcome->provider['id'] ?? 0) !== (int) $dispatch->provider_id) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query receipt differs from its invocation');
        }
    }

    /** @param array<string,mixed> $publication */
    private function response(
        array $publication, KnowledgeQueryEmbeddingOutcome $outcome,
        Invocation $invocation, bool $replay, callable $authorizeInvocation,
    ): array {
        $index = (new KnowledgeEmbeddingIndexGateway())->currentIndex($publication);
        if ((int) $index['id'] !== $outcome->indexId
            || (int) $index['dimensions'] !== count($outcome->vector)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Knowledge query index changed');
        }
        $authorizeInvocation((int) $invocation->id);
        $usage = (new UsageGateway())->invocationCost(
            (int) $invocation->environment_id, (string) $invocation->request_id,
            static function (Invocation $read) use ($invocation): void {
                if ((int) $read->id !== (int) $invocation->id
                    || (string) $read->state !== 'succeeded') {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Knowledge query usage is unavailable');
                }
            },
        );
        if (($usage['usage']['state'] ?? null) === 'missing_event') {
            throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Knowledge query usage event is unavailable', true);
        }
        return [
            'index_id' => (int) $index['id'], 'vector' => $outcome->vector,
            'invocation' => ['id' => (int) $invocation->id, 'state' => 'succeeded'],
            'usage' => $usage, 'idempotent_replay' => $replay,
        ];
    }

    private function assertPublishedIndex(int $publicationId, int $versionId, int $indexId): void
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT p.id FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id
             JOIN sand_ai_knowledge_embedding_active a ON a.knowledge_base_version_id=v.id
             JOIN sand_ai_knowledge_embedding_index i ON i.id=a.index_id
             WHERE p.id=? AND p.state='published' AND kb.state='published'
               AND kb.status=1 AND kb.delete_time IS NULL AND v.id=?
               AND v.state='published' AND i.id=? AND i.state='ready'
             FOR SHARE OF p,kb,v,a,i",
            [$publicationId, $versionId, $indexId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Knowledge query index is no longer published');
        }
    }

    private function authorizePrepared(
        callable $authorizeInvocation, Invocation $invocation, ?int $reservationId,
        DispatchExecutionScope $scope,
    ): void {
        try {
            $authorizeInvocation((int) $invocation->id);
        } catch (ApiProblem $problem) {
            $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)
                ->where('state', 'prepared')->find();
            if ($dispatch !== null && (string) $invocation->state === 'running') {
                (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                    (int) $dispatch->id, $invocation, $reservationId, $scope,
                    $problem->errorCode, $problem->getMessage(),
                );
            }
            throw $problem;
        }
    }

    private function providerKey(
        ProviderIdempotencyCapability $capability, string $logicalId, string $digest,
    ): ?string {
        if ($capability->mode === 'none') return null;
        $secret = env('SAND_AI_DISPATCH_IDEMPOTENCY_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider idempotency secret is unavailable');
        }
        return hash_hmac('sha256', $logicalId . ':' . $digest, $secret);
    }

    private function receipts(): OutcomeReceiptService
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Knowledge query encryption is unavailable');
        }
        return new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config),
            $config['ttl_seconds'] ?? 0,
        );
    }

    /** @return array<string,int|string> */
    private function receiptContext(Invocation $invocation, string $fingerprint): array
    {
        return [
            'environment_id' => (int) $invocation->environment_id,
            'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => $fingerprint,
            'capability_code' => 'embedding',
            'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id,
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ];
    }

    private function finalizeFailure(
        InvocationDispatch $dispatch, Invocation $invocation, ?int $reservationId,
        DispatchExecutionScope $scope, ApiProblem $problem, ProviderSendBoundary $boundary,
    ): void {
        $outcome = ProviderDispatchTerminalizationPolicy::failed($problem, $boundary);
        if ($outcome->ledgerState === 'not_dispatched') {
            $outcome = ProviderDispatchTerminalizationPolicy::forFailure(
                new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Knowledge query dispatch did not finish', true), true,
            );
        }
        (new DispatchCoordinator())->finalizeProviderOutcomeForScope(
            (int) $dispatch->id, $invocation, $reservationId, $scope,
            'embedding', $problem, $outcome,
        );
    }
}
