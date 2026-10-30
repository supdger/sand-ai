<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\DispatchCoordinator;
use app\Application\Gateway\DispatchClaimOutcome;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\DispatchFinalizer;
use app\Application\Gateway\DispatchRouteSnapshot;
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
use app\Application\Usage\UsageMeter;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\OpenAiCompatibleEmbeddingProvider;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use think\facade\Db;

/** Internal, one-source-at-a-time embedding dispatch with a private recovery receipt. */
final class KnowledgeEmbeddingBuildGateway
{
    /** @return array<string,mixed> */
    public function buildNext(int $environmentId, int $indexId): array
    {
        if ($environmentId < 1 || $indexId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding index is invalid');
        }
        $indexRows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_embedding_index WHERE id=? AND owner_environment_id=?',
            [$indexId, $environmentId],
        );
        if (count($indexRows) !== 1 || $indexRows[0]['state'] !== 'building') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index is not building');
        }
        $index = $indexRows[0];
        $sources = Db::connect('pgsql')->query(
            "SELECT s.source_block_id,s.content_sha256,b.content
               FROM sand_ai_knowledge_base_source s
               JOIN sand_ai_source_block b ON b.id=s.source_block_id
               LEFT JOIN sand_ai_knowledge_embedding e
                 ON e.index_id=? AND e.source_block_id=s.source_block_id
              WHERE s.knowledge_base_version_id=? AND e.source_block_id IS NULL
              ORDER BY s.source_block_id LIMIT 1",
            [$indexId, (int) $index['knowledge_base_version_id']],
        );
        if ($sources === []) {
            return ['state' => 'ready', 'index' => (new KnowledgeEmbeddingIndexGateway())->activate($indexId)];
        }
        return $this->embedOne($index, $sources[0]);
    }

    /** @param array<string,mixed> $index @param array<string,mixed> $source
     *  @return array<string,mixed>
     */
    private function embedOne(array $index, array $source): array
    {
        $indexId = (int) $index['id'];
        $environmentId = (int) $index['owner_environment_id'];
        $blockId = (int) $source['source_block_id'];
        $contentHash = (string) $source['content_sha256'];
        $content = (string) $source['content'];
        $requestId = 'knowledge-embedding:' . hash('sha256', $indexId . ':' . $blockId);
        $fingerprintPayload = [
            'index_id' => $indexId, 'source_block_id' => $blockId,
            'content_sha256' => $contentHash,
            'source_snapshot_sha256' => (string) $index['source_snapshot_sha256'],
            'deployment_id' => (int) $index['deployment_id'],
            'deployment_revision' => (int) $index['deployment_revision'],
            'provider_config_sha256' => (string) $index['provider_config_sha256'],
        ];
        $legacyFingerprint = RequestPayloadFingerprint::fingerprint($fingerprintPayload);
        $protocol = OpenAiCompatibleEmbeddingProvider::PROTOCOL_NEURON;
        $fingerprint = RequestPayloadFingerprint::fingerprint([
            ...$fingerprintPayload, 'embedding_request_protocol' => $protocol,
        ]);
        $existing = Invocation::where('request_id', $requestId)->find();
        // A frozen pre-Neuron invocation keeps both its complete fingerprint
        // and wire shape. An arbitrary mismatch never selects legacy mode.
        if ($existing !== null
            && hash_equals((string) $existing->request_fingerprint, $legacyFingerprint)) {
            $protocol = OpenAiCompatibleEmbeddingProvider::PROTOCOL_LEGACY;
            $fingerprint = $legacyFingerprint;
        }
        $scope = DispatchExecutionScope::embedding($environmentId, $indexId, $blockId, $fingerprint);
        $receipts = $this->receipts();
        $receipts->preflight('embedding', new OutcomeReceiptRequest(262144));
        if ($existing !== null) {
            if ((int) $existing->environment_id !== $environmentId
                || !hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Embedding request changed');
            }
            $existingDispatch = InvocationDispatch::where('invocation_id', (int) $existing->id)->find();
            if ($existingDispatch === null) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding dispatch is missing');
            }
            if ((string) $existingDispatch->state === 'dispatching'
                || ((string) $existingDispatch->state === 'succeeded' && (string) $existing->state === 'succeeded')) {
                $context = $this->receiptContext($existing, $fingerprint);
                $probe = $receipts->probe((int) $existing->id, $context);
                if ($probe === 'valid') {
                    $outcome = KnowledgeEmbeddingOutcome::decode($receipts->replay($context));
                    $usage = $this->finalizeAccounting(
                        $index, $source, $existing, $existingDispatch,
                        KnowledgeEmbeddingAccountingOutcome::fromOutcome($outcome),
                    );
                    return $this->appendSettled($index, $existing, $outcome, $usage, true);
                }
                if ($probe === 'invalid') {
                    if ((string) $existing->state === 'running'
                        && (string) $existingDispatch->state === 'dispatching') {
                        $accounting = $receipts->readKnowledgeEmbeddingAccounting($context);
                        if ($accounting !== null) {
                            $this->finalizeAccounting($index, $source, $existing, $existingDispatch, $accounting);
                        }
                    }
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Embedding result receipt is invalid');
                }
                if ((string) $existing->state === 'succeeded') {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Settled embedding result receipt is unavailable');
                }
                $startedAt = strtotime((string) $existingDispatch->dispatch_start_time);
                return [
                    'state' => $startedAt !== false && time() - $startedAt < 180
                        ? 'in_progress' : 'outcome_unknown',
                    'index_id' => $indexId, 'source_block_id' => $blockId,
                ];
            }
            if ((string) $existingDispatch->state !== 'prepared' || (string) $existing->state !== 'running') {
                if ((string) $existingDispatch->state === 'not_dispatched'
                    && (string) $existing->state === 'failed'
                    && (string) $existing->error_code === 'SAND_AI_BUDGET_RESERVATION_EXPIRED') {
                    throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
                }
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding invocation cannot be dispatched again');
            }
        }
        try {
            (new KnowledgeEmbeddingIndexGateway())->assertSourceCurrent($index);
            if (trim($content) === '' || strlen($content) > 65536
                || !hash_equals($contentHash, hash('sha256', $content))) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Embedding source is unavailable');
            }
            [$profile, $route, $deployment, $model, $provider, $routeSnapshot] = $this->routeForIndex($index);
            $providerConfig = $provider->encrypted_config;
            $providerConfig = is_object($providerConfig) ? (array) $providerConfig : $providerConfig;
            if (!is_array($providerConfig)) {
                throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Embedding provider configuration is unavailable');
            }
            $runtimeConfig = (new ProviderSecretDecryptor())->decrypt($providerConfig);
            $payload = OpenAiCompatibleEmbeddingProvider::requestBody(
                (string) $deployment->remote_model, [$content], $protocol,
            );
            $body = ProviderRequestBodyBuilder::bytes($payload);
            $digest = hash('sha256', $body);
            $capability = ProviderIdempotencyCapability::fromServerConfig('openai_compatible', $runtimeConfig);
            $providerKey = $this->providerKey($capability, $scope->logicalExecutionId, $digest);
            $dispatchContext = new ProviderDispatchContext($scope->logicalExecutionId, $digest, $providerKey);
            $attribution = $this->attribution($environmentId);
        } catch (ApiProblem $problem) {
            if ($existing !== null && isset($existingDispatch)
                && (string) $existingDispatch->state === 'prepared') {
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
                (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                    (int) $existingDispatch->id, $existing,
                    $reservation === null ? null : (int) $reservation->id, $scope,
                    $problem->errorCode, $problem->getMessage(),
                );
            }
            throw $problem;
        }
        if ($existing !== null && (string) $existing->state === 'running'
            && (string) $existingDispatch->state === 'prepared'
            && (new DispatchCoordinator())->rejectExpiredPreparedForScope(
                $existing, $scope, $attribution,
            )) {
            throw new ApiProblem(
                'SAND_AI_BUDGET_RESERVATION_EXPIRED',
                'Budget reservation expired before provider dispatch',
            );
        }
        $dispatch = null;
        try {
            $budget = (new ChatBudgetGuard())->prepare(
            $environmentId, (int) $model->id, $deployment,
            [['role' => 'user', 'content' => $content]], new ChatCompletionOptions(null),
            $requestId, $fingerprint,
            min(3600, max(60, (int) ceil((int) $provider->timeout_ms / 1000) + 60)),
            'embedding', null,
            function (Invocation $invocation, CallStartPricingSnapshot $pricing) use (
                &$dispatch, $environmentId, $indexId, $scope, $requestId, $attribution,
                $provider, $deployment, $model, $routeSnapshot, $digest, $capability, $providerKey,
            ): void {
                $current = Db::connect('pgsql')->query(
                    "SELECT kb.id FROM sand_ai_knowledge_base kb
                     JOIN sand_ai_knowledge_base_version v ON v.knowledge_base_id=kb.id
                     JOIN sand_ai_knowledge_embedding_index i ON i.knowledge_base_version_id=v.id
                     WHERE i.id=? AND i.owner_environment_id=?
                       AND v.state='published' AND kb.state='published' AND kb.active_version_id=v.id
                       AND kb.status=1 AND kb.delete_time IS NULL
                     FOR SHARE OF kb",
                    [$indexId, $environmentId],
                );
                if (count($current) !== 1) {
                    throw new KnowledgeEmbeddingDispatchClosed();
                }
                $currentIndex = Db::connect('pgsql')->query(
                    "SELECT id FROM sand_ai_knowledge_embedding_index
                     WHERE id=? AND owner_environment_id=? AND state='building' FOR SHARE",
                    [$indexId, $environmentId],
                );
                if (count($currentIndex) !== 1) {
                    throw new KnowledgeEmbeddingDispatchClosed();
                }
                $dispatch = (new DispatchCoordinator())->prepare([
                    'invocation_id' => (int) $invocation->id, 'environment_id' => $environmentId,
                    'execution_scope' => $scope->scope, 'logical_execution_id' => $scope->logicalExecutionId,
                    'logical_request_id' => hash('sha256', $requestId), 'attempt_no' => $scope->attemptNo,
                    'application_id' => $attribution->applicationId,
                    'service_action' => $attribution->serviceAction, 'source_kind' => $attribution->sourceKind,
                    'provider_id' => (int) $provider->id, 'deployment_id' => (int) $deployment->id,
                    'model_id' => (int) $model->id, 'capability_code' => 'embedding',
                    'route_snapshot' => $routeSnapshot, 'price_snapshot' => $pricing->dispatchSnapshot(),
                    'execution_snapshot_sha256' => $scope->executionSnapshotSha256,
                    'request_body_digest' => $digest,
                    'provider_idempotency_mode' => $capability->mode,
                    'provider_idempotency_key' => $providerKey,
                    'lease_owner' => $scope->leaseOwner, 'lease_token' => $scope->leaseToken,
                    'lease_fence' => $scope->leaseFence,
                ]);
            },
            attribution: $attribution,
            providerRequestBody: $payload,
            providerRouteSnapshot: $routeSnapshot,
            providerRequestProtocol: $protocol,
            );
        } catch (KnowledgeEmbeddingDispatchClosed) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index was disabled before dispatch');
        }
        $invocation = $budget['invocation'];
        if (($budget['idempotent_replay'] ?? false) === true) {
            return ['state' => 'in_progress', 'index_id' => $indexId, 'source_block_id' => $blockId];
        }
        $dispatch ??= InvocationDispatch::where('invocation_id', (int) $invocation->id)
            ->where('state', 'prepared')->find();
        if ($dispatch === null || !hash_equals((string) $dispatch->request_body_digest, $digest)
            || (string) $dispatch->provider_idempotency_key !== (string) $providerKey) {
            if ($dispatch !== null) {
                (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                    (int) $dispatch->id, $invocation, $budget['reservation_id'], $scope,
                    'SAND_AI_DISPATCH_STATE_INVALID', 'Embedding request changed after preparation',
                );
            }
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding request changed after preparation');
        }
        $reservationId = $budget['reservation_id'];
        $prepared = null;
        try {
            $prepared = (new OpenAiCompatibleEmbeddingProvider())->prepare(
                $runtimeConfig, (string) $deployment->remote_model, [$content],
                (int) $provider->timeout_ms, $dispatchContext, $protocol,
            );
        } catch (ApiProblem $problem) {
            (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                (int) $dispatch->id, $invocation, $reservationId, $scope,
                $problem->errorCode, $problem->getMessage(),
            );
            throw $problem;
        }
        $claim = Db::connect('pgsql')->transaction(function () use (
            $index, $dispatch, $invocation, $reservationId, $scope,
            $provider, $deployment, $model, $budget, $attribution,
        ): DispatchClaimOutcome {
            // Keep the current-version and build-state locks until the durable
            // claim crosses prepared -> dispatching. Publication or disable
            // cannot invalidate this check while the claim waits on its locks.
            try {
                $this->lockCurrentBuild($index);
                (new KnowledgeEmbeddingIndexGateway())->assertSourceCurrent($index);
            } catch (ApiProblem $problem) {
                (new DispatchCoordinator())->finalizeNotDispatchedForScope(
                    (int) $dispatch->id, $invocation, $reservationId, $scope,
                    $problem->errorCode, $problem->getMessage(),
                );
                return DispatchClaimOutcome::rejected($problem);
            }
            return (new DispatchCoordinator())->claimForScope(
                (int) $dispatch->id, $invocation, $reservationId, $scope,
                $provider, $deployment, (int) $model->id, 'embedding', $budget['price_revision_id'],
                attribution: $attribution,
            );
        });
        if (!$claim->claimed || $claim->provider === null || $claim->deployment === null) {
            throw $claim->problem ?? new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding claim was rejected');
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
            $problem = new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider execution failed', true);
            $this->finalizeFailure($dispatch, $invocation, $reservationId, $scope, $problem, $boundary);
            throw $problem;
        }
        try {
            $outcome = KnowledgeEmbeddingOutcome::fromProvider(
                $indexId, $blockId, $contentHash, $result,
                (int) round((microtime(true) - $startedAt) * 1000),
                $attribution, $claim->provider, $model, $claim->deployment, $routeSnapshot,
            );
            $receipts->write($outcome->encode(), $this->receiptContext($invocation, $fingerprint), 262144);
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_RECEIPT_PERSISTENCE_FAILED', 'Embedding result could not be sealed', true);
            $this->finalizeFailure($dispatch, $invocation, $reservationId, $scope, $problem, $boundary);
            throw $problem;
        }
        try {
            $usage = $this->finalizeAccounting(
                $index, $source, $invocation, $dispatch,
                KnowledgeEmbeddingAccountingOutcome::fromOutcome($outcome),
            );
        } catch (\Throwable) {
            // The receipt is durable. A later call recovers it without sending
            // the same source to the provider a second time.
            throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Embedding result is durably recoverable', true);
        }
        return $this->appendSettled($index, $invocation, $outcome, $usage, false);
    }

    /** @param array<string,mixed> $index */
    private function lockCurrentBuild(array $index): void
    {
        $current = Db::connect('pgsql')->query(
            "SELECT kb.id FROM sand_ai_knowledge_base kb
             JOIN sand_ai_knowledge_base_version v ON v.knowledge_base_id=kb.id
             WHERE v.id=? AND kb.environment_id=? AND kb.active_version_id=v.id
               AND v.state='published' AND kb.state='published'
               AND kb.status=1 AND kb.delete_time IS NULL
             FOR SHARE OF kb,v",
            [(int) $index['knowledge_base_version_id'], (int) $index['owner_environment_id']],
        );
        if (count($current) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Embedding version is no longer current');
        }
        $build = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_knowledge_embedding_index
             WHERE id=? AND owner_environment_id=? AND knowledge_base_version_id=?
               AND state='building' FOR SHARE",
            [(int) $index['id'], (int) $index['owner_environment_id'], (int) $index['knowledge_base_version_id']],
        );
        if (count($build) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index is no longer building');
        }
    }

    /** @param array<string,mixed> $index @return array{CapabilityProfile,CapabilityRoute,ModelDeployment,AiModel,Provider,array<string,mixed>} */
    public function routeForIndex(array $index): array
    {
        $environmentId = (int) $index['owner_environment_id'];
        $selected = (new CapabilityProfileResolver())->primary($environmentId, 'embedding');
        if ($selected === null || (int) ($selected['profile_id'] ?? 0) !== (int) $index['profile_id']
            || (int) ($selected['profile_revision'] ?? 0) !== (int) $index['profile_revision']
            || (int) ($selected['route_id'] ?? 0) !== (int) $index['route_id']
            || (int) ($selected['deployment_id'] ?? 0) !== (int) $index['deployment_id']) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Embedding route changed during build');
        }
        $profile = CapabilityProfile::where('id', (int) $index['profile_id'])->where('state', 'published')->where('status', 1)->find();
        $route = CapabilityRoute::where('id', (int) $index['route_id'])->where('status', 1)->find();
        $deployment = ModelDeployment::where('id', (int) $index['deployment_id'])->where('status', 1)->find();
        $model = AiModel::where('id', (int) $index['model_id'])->where('status', 1)->find();
        $provider = Provider::where('id', (int) $index['provider_id'])->where('status', 1)->find();
        if ($profile === null || $route === null || $deployment === null || $model === null || $provider === null
            || (string) $model->type !== 'embedding' || (string) $provider->adapter !== 'openai_compatible'
            || (int) $deployment->model_id !== (int) $model->id
            || (int) $deployment->provider_id !== (int) $provider->id
            || (int) $deployment->revision !== (int) $index['deployment_revision']
            || (string) $deployment->remote_model !== (string) $index['remote_model']) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Embedding model changed during build');
        }
        $encrypted = is_object($provider->encrypted_config) ? (array) $provider->encrypted_config : $provider->encrypted_config;
        if (!is_array($encrypted) || DispatchRouteSnapshot::configHash($encrypted)
            !== (string) $index['provider_config_sha256']) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Embedding provider changed during build');
        }
        $deploymentRevision = ConfigRevision::where('resource_type', 'model_deployment')
            ->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)
            ->where('state', 'published')->where('status', 1)->find();
        $profileRevision = ConfigRevision::where('resource_type', 'capability_profile')
            ->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)
            ->where('state', 'published')->where('status', 1)->find();
        if ($deploymentRevision === null || $profileRevision === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Embedding configuration revision is unavailable');
        }
        return [$profile, $route, $deployment, $model, $provider,
            DispatchRouteSnapshot::freeze($profile, $route, $deployment, $model, $provider,
                $deploymentRevision, $profileRevision)];
    }

    private function attribution(int $environmentId): UsageAttribution
    {
        try {
            $facts = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        return new UsageAttribution(
            $environmentId, $facts['organization_id'], $facts['application_id'], null,
            ['organization_ref' => (string) $facts['organization_id'],
                'application_ref' => (string) $facts['application_id']],
            'knowledge_index', 'sand_ai.knowledge.index', 'embedding',
        );
    }

    private function providerKey(ProviderIdempotencyCapability $capability, string $logicalId, string $digest): ?string
    {
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
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Embedding outcome encryption is unavailable');
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

    /**
     * Commit already incurred usage before checking whether source content can
     * still be indexed. Revocation must not strand budget or dispatch state.
     * @param array<string,mixed> $index
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function finalizeAccounting(
        array $index, array $source, Invocation $invocation, InvocationDispatch $dispatch,
        KnowledgeEmbeddingAccountingOutcome $outcome,
    ): array {
        if ($outcome->indexId !== (int) $index['id']
            || $outcome->sourceBlockId !== (int) $source['source_block_id']
            || !hash_equals($outcome->contentSha256, (string) $source['content_sha256'])
            || (int) ($outcome->attribution['environment_id'] ?? 0) !== (int) $invocation->environment_id
            || (int) ($outcome->attribution['organization_id'] ?? 0) !== (int) $invocation->organization_id
            || (int) ($outcome->attribution['application_id'] ?? 0) !== (int) $invocation->application_id
            || (int) ($outcome->model['id'] ?? 0) !== (int) $invocation->model_id
            || (int) ($outcome->deployment['id'] ?? 0) !== (int) $invocation->deployment_id
            || (int) ($outcome->provider['id'] ?? 0) !== (int) $dispatch->provider_id) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Embedding receipt differs from its invocation');
        }
        $reservation = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
        $reservationId = $reservation === null ? null : (int) $reservation->id;
        $pricing = CallStartPricingSnapshot::fromPersistence(
            $invocation->call_started_at, $invocation->call_start_pricing_state,
            $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
        );
        return (new DispatchFinalizer())->success(function () use (
            $outcome, $invocation, $dispatch, $reservationId, $pricing,
        ): array {
            $terminal = (new DispatchFinalizer())->lockKnowledgeTerminal(
                $reservationId, (int) $invocation->id, (int) $dispatch->id,
            );
            $locked = $terminal['invocation'];
            $attribution = UsageAttribution::fromPersisted(
                $outcome->attribution, (int) $locked->environment_id,
                'knowledge_index', 'sand_ai.knowledge.index',
            );
            if ((string) $locked->state === 'succeeded'
                && (string) $terminal['dispatch']->state === 'succeeded') {
                $events = Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_usage_event WHERE invocation_id=? LIMIT 1',
                    [(int) $locked->id],
                );
                if (count($events) !== 1) {
                    throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Settled embedding usage is unavailable');
                }
                return (new UsageMeter())->meterFrozen(
                    $locked, $attribution, $outcome->inputTokens, 0, $outcome->usageMeasured,
                    $pricing, $outcome->provider, $outcome->model, $outcome->deployment,
                );
            }
            if ((string) $locked->state !== 'running' || (string) $terminal['dispatch']->state !== 'dispatching') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Embedding dispatch is no longer recoverable');
            }
            $locked->save([
                'state' => 'succeeded', 'latency_ms' => $outcome->latencyMs,
                'completed_at' => gmdate('Y-m-d H:i:s'), 'error_code' => null, 'error_summary' => null,
            ]);
            $usage = (new UsageMeter())->meterFrozen(
                $locked, $attribution, $outcome->inputTokens, 0, $outcome->usageMeasured,
                $pricing, $outcome->provider, $outcome->model, $outcome->deployment,
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
                    $usage['metered_amount'] === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered',
                    $usage['metered_amount'] === null ? null : $pricing->priceRevisionId,
                    'embedding',
                );
            }
            (new DispatchCoordinator())->finalizeSuccessForScope(
                (int) $dispatch->id, null, ProviderDispatchTerminalizationPolicy::succeeded(),
            );
            return $usage;
        });
    }

    /** @param array<string,mixed> $index @param array<string,mixed> $usage @return array<string,mixed> */
    private function appendSettled(
        array $index, Invocation $invocation, KnowledgeEmbeddingOutcome $outcome,
        array $usage, bool $recovered,
    ): array {
        return Db::connect('pgsql')->transaction(function () use ($index, $invocation, $outcome, $usage, $recovered): array {
            $rows = Db::connect('pgsql')->query(
                "SELECT * FROM sand_ai_knowledge_embedding_index
                 WHERE id=? AND owner_environment_id=? AND state='building' FOR UPDATE",
                [(int) $index['id'], (int) $index['owner_environment_id']],
            );
            if (count($rows) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Settled embedding index is no longer writable');
            }
            (new KnowledgeEmbeddingIndexGateway())->assertSourceCurrent($rows[0]);
            $existing = Db::connect('pgsql')->query(
                'SELECT invocation_id FROM sand_ai_knowledge_embedding WHERE index_id=? AND source_block_id=?',
                [(int) $index['id'], $outcome->sourceBlockId],
            );
            (new KnowledgeEmbeddingIndexGateway())->append(
                (int) $index['id'], (int) $invocation->id,
                [[
                    'source_block_id' => $outcome->sourceBlockId,
                    'content_sha256' => $outcome->contentSha256,
                    'embedding' => $outcome->vector,
                ]],
            );
            if ($existing === []) {
                AuditLog::create([
                    'actor_type' => 'system_worker',
                    'actor_ref' => (string) $index['id'],
                    'action' => 'knowledge.embedding.indexed',
                    'resource_type' => 'invocation',
                    'resource_id' => (int) $invocation->id,
                    'summary' => 'Private knowledge source indexed',
                    'context' => [
                        'index_id' => (int) $index['id'],
                        'source_block_id' => $outcome->sourceBlockId,
                    ],
                ]);
            }
            return [
                'state' => 'indexed', 'index_id' => (int) $index['id'],
                'source_block_id' => $outcome->sourceBlockId,
                'invocation_id' => (int) $invocation->id, 'recovered' => $recovered,
                'usage' => $usage,
            ];
        });
    }

    private function finalizeFailure(
        InvocationDispatch $dispatch, Invocation $invocation, ?int $reservationId,
        DispatchExecutionScope $scope, ApiProblem $problem, ProviderSendBoundary $boundary,
    ): void {
        $outcome = ProviderDispatchTerminalizationPolicy::failed($problem, $boundary);
        if ($outcome->ledgerState === 'not_dispatched') {
            // send() marks the boundary before attempting I/O. A pre-send
            // failure after claim is conservatively treated as ambiguous.
            $outcome = ProviderDispatchTerminalizationPolicy::forFailure(
                new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding dispatch did not finish', true), true,
            );
        }
        (new DispatchCoordinator())->finalizeProviderOutcomeForScope(
            (int) $dispatch->id, $invocation, $reservationId, $scope,
            'embedding', $problem, $outcome,
        );
    }
}
