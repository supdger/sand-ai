<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\RequestScopedProviderConnection;
use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;
use think\db\ConnectionInterface;
use think\facade\Db;

/** API 1.44 atomic draft persistence; it never validates or publishes a deployment. */
final class DeploymentDraftSaveService
{
    public function __construct(
        private readonly DeploymentDraftSaveRequestPolicy $requestPolicy = new DeploymentDraftSaveRequestPolicy(),
        private readonly ProviderExecutionConfigFingerprint $connectionFingerprint = new ProviderExecutionConfigFingerprint(),
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @param RequestScopedProviderConnection|null $connectionMaterial opaque request-scoped material
     * @return array<string,mixed>
     */
    public function save(array $input, ?RequestScopedProviderConnection $connectionMaterial, string $idempotencyKey, string $actorRef): array
    {
        $actorRef = trim($actorRef);
        if ($actorRef === '') {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        $normalized = $this->requestPolicy->normalize($input, $connectionMaterial !== null);
        try {
            return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($normalized, $connectionMaterial, $idempotencyKey, $actorRef): array {
            $this->assertAdvisoryLock($connection, $idempotencyKey, $actorRef);
            $replay = $this->findReplay($connection, $idempotencyKey, $actorRef, $normalized, $connectionMaterial);
            if ($replay !== null) {
                return $replay;
            }

            $chain = $normalized['deployment_id'] === null
                ? $this->createChain($connection, $normalized, $connectionMaterial)
                : $this->advanceChain($connection, $normalized);
            $fingerprint = $this->requestPolicy->fingerprint($this->canonicalRequest($normalized, $chain['provider'], $chain['provider_fingerprint']), $idempotencyKey, $actorRef);
            $revision = $chain['next_revision'];
            $deployment = $chain['deployment'];
            if ($deployment === null) {
                $deployment = ModelDeployment::create([
                    'model_id' => (int) $chain['model']['id'],
                    'provider_id' => (int) $chain['provider']['id'],
                    'remote_model' => $normalized['remote_model'],
                    'config' => $normalized['config'],
                    'revision' => $revision,
                    'status' => 2,
                ])->toArray();
            } else {
                $updated = $connection->table('sand_ai_model_deployment')->json(['config'])->where('id', (int) $deployment['id'])
                    ->whereNull('delete_time')->update([
                        'remote_model' => $normalized['remote_model'], 'config' => $normalized['config'],
                        'revision' => $revision, 'status' => 2, 'update_time' => date('Y-m-d H:i:s'),
                    ]);
                if ($updated !== 1) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment chain changed while saving a draft');
                }
                $deployment = [...$deployment, 'remote_model' => $normalized['remote_model'], 'config' => $normalized['config'], 'revision' => $revision, 'status' => 2];
            }
            ConfigRevision::create([
                'resource_type' => 'model_deployment',
                'resource_id' => (int) $deployment['id'],
                'revision' => $revision,
                'payload' => [
                    'draft_configuration' => [
                        'version' => 'draft_configuration@1',
                        'remote_model' => $normalized['remote_model'],
                        'config' => $normalized['config'],
                    ],
                    ...(in_array($chain['provider']['adapter'] ?? null, ['local_runtime', 'local_ocr_runtime'], true)
                        ? ['local_runtime_execution_config' => $chain['provider_fingerprint']] : []),
                    'draft_save' => [
                        'version' => 'draft_save@1',
                        'idempotency_hmac' => $fingerprint['idempotency_hmac'],
                        'request_fingerprint_hmac' => $fingerprint['request_fingerprint_hmac'],
                        'key_version' => $fingerprint['key_version'],
                        'key_id' => $fingerprint['key_id'],
                        'saved_time' => date('Y-m-d H:i:s'),
                    ],
                ],
                'state' => 'draft',
                'status' => 1,
            ]);
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => 'draft_save@1',
                'resource_type' => 'model_deployment', 'resource_id' => (int) $deployment['id'],
                'summary' => 'Saved SandAI deployment draft',
                'context' => ['revision' => $revision, 'outcome' => 'draft_saved'],
            ]);
            return $this->summary($chain['provider'], $chain['model'], $deployment, 'draft', false);
            });
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_DRAFT_SAVE_FAILED', 'Deployment draft could not be saved safely', true);
        }
    }

    /**
     * Find only an already committed save made by this administrator.
     * The original secret-bearing request is not needed and is never rebuilt.
     * @return array<string,mixed>
     */
    public function readByRequestKey(string $idempotencyKey, string $actorRef): array
    {
        // Reuse the original save key/actor validation without taking a write lock.
        $this->requestPolicy->advisoryLockFingerprint($idempotencyKey, $actorRef);
        try {
            return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($idempotencyKey, $actorRef): array {
                $rows = $connection->table('sand_ai_config_revision')->json(['payload'], true)
                    ->where('resource_type', 'model_deployment')->where('status', 1)->whereNull('delete_time')->select();
                $match = null;
                foreach ($rows as $row) {
                    $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                    $keyId = $this->requestPolicy->replayKeyId($payload);
                    if ($keyId === null) {
                        continue;
                    }
                    $candidate = $this->requestPolicy->idempotencyForKeyId($idempotencyKey, $actorRef, $keyId);
                    if (!hash_equals($candidate['idempotency_hmac'], $payload['draft_save']['idempotency_hmac'])) {
                        continue;
                    }
                    if ($match !== null) {
                        throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Original deployment draft save is not unique');
                    }
                    $match = ['deployment_id' => (int) $row['resource_id'], 'revision' => (int) $row['revision']];
                }
                if ($match === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original deployment draft save not found');
                }
                $chain = $this->lockExactChain($connection, $match['deployment_id'], $match['revision']);
                $payload = is_array($chain['revision']['payload'] ?? null) ? $chain['revision']['payload'] : [];
                $keyId = $this->requestPolicy->replayKeyId($payload);
                if ($keyId === null || (int) ($chain['revision']['status'] ?? 0) !== 1) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Original deployment draft save changed');
                }
                $candidate = $this->requestPolicy->idempotencyForKeyId($idempotencyKey, $actorRef, $keyId);
                if (!hash_equals($candidate['idempotency_hmac'], $payload['draft_save']['idempotency_hmac'])) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Original deployment draft save changed');
                }
                $state = (string) $chain['revision']['state'];
                if (!in_array($state, ['draft', 'verifying', 'tested', 'verification_ambiguous', 'published'], true)) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Original deployment draft state is unavailable');
                }
                $configuration = $this->draftConfiguration($payload);
                $summary = $this->summary(
                    $chain['provider'],
                    $chain['model'],
                    [...$chain['deployment'], 'remote_model' => $configuration['remote_model'], 'config' => $configuration['config']],
                    $state,
                    true,
                    $match['revision'],
                );
                // This lookup's empty configuration is a JSON object, matching
                // the original embedding draft request without changing storage.
                if ($configuration['config'] === []) {
                    $summary['deployment']['config'] = new \stdClass();
                }
                return $summary;
            });
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_DRAFT_READ_FAILED', 'Original deployment draft save could not be read safely', true);
        }
    }

    /** @return array<string,mixed> */
    public function read(int $deploymentId, ?int $revision = null): array
    {
        if ($deploymentId < 1 || ($revision !== null && $revision < 1)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment draft revision not found');
        }
        try {
            return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision): array {
            $chain = $this->lockExactChain($connection, $deploymentId, $revision);
            $revisionRow = $chain['revision'];
            if (!is_array($revisionRow)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment draft revision not found');
            }
            $selectedRevision = (int) $revisionRow['revision'];
            $deployment = $chain['deployment']; $provider = $chain['provider']; $model = $chain['model'];
            if ($this->isDraftSavePayload($revisionRow['payload'] ?? null)) {
                $configuration = $this->draftConfiguration($revisionRow['payload']);
            } else {
                // API 1.17 verification still creates a supported tested draft.
                // Read its original frozen values without inventing draft-save
                // markers or upgrading any persisted verification evidence.
                $legacy = $this->legacyReadConfiguration($connection, $chain, $deploymentId, $selectedRevision);
                $configuration = $legacy['configuration'];
                $model = [...$model, 'type' => $legacy['model_type'], 'capabilities' => $legacy['capabilities']];
            }
            return $this->summary(
                $provider,
                $model,
                [...$deployment, 'remote_model' => $configuration['remote_model'], 'config' => $configuration['config'], 'revision' => $selectedRevision],
                (string) $revisionRow['state'],
                false,
                $selectedRevision,
            );
            });
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_DRAFT_READ_FAILED', 'Deployment draft could not be read safely', true);
        }
    }

    /** @param array<string,mixed> $normalized @return array{provider:array<string,mixed>,provider_fingerprint:array{version:string,hmac_sha256:string},model:array<string,mixed>,deployment:?array<string,mixed>,next_revision:int} */
    private function createChain(ConnectionInterface $connection, array $normalized, ?RequestScopedProviderConnection $connectionMaterial): array
    {
        $provider = $normalized['provider_id'] === null
            ? $this->newProvider($normalized['provider'], $connectionMaterial)
            : $this->lockProvider($connection, $normalized['provider_id']);
        $model = $normalized['model_id'] === null
            ? $this->newModel($normalized['model'])
            : $this->lockModel($connection, $normalized['model_id']);
        $this->assertAvailable($provider, $model, $normalized['config'], $normalized['remote_model']);
        return ['provider' => $provider, 'provider_fingerprint' => $connectionMaterial?->executionFingerprint() ?? $this->connectionFingerprint->fromProviderId((int) $provider['id']), 'model' => $model, 'deployment' => null, 'next_revision' => $this->nextRevision($connection, (int) $model['id'])];
    }

    /** @param array<string,mixed> $normalized @return array{provider:array<string,mixed>,provider_fingerprint:array{version:string,hmac_sha256:string},model:array<string,mixed>,deployment:array<string,mixed>,next_revision:int} */
    private function advanceChain(ConnectionInterface $connection, array $normalized): array
    {
        $reference = $connection->table('sand_ai_model_deployment')->field(['id', 'provider_id', 'model_id', 'revision'])
            ->where('id', $normalized['deployment_id'])->whereNull('delete_time')->find();
        if (!is_array($reference)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment not found');
        }
        $provider = $this->lockProvider($connection, (int) $reference['provider_id']);
        $model = $this->lockModel($connection, (int) $reference['model_id']);
        $nextRevision = $this->nextRevision($connection, (int) $model['id']);
        $deployment = $connection->table('sand_ai_model_deployment')->json(['config'], true)->where('id', (int) $reference['id'])
            ->whereNull('delete_time')->lock(true)->find();
        if (!is_array($deployment) || (int) $deployment['provider_id'] !== (int) $reference['provider_id'] || (int) $deployment['model_id'] !== (int) $reference['model_id'] || (int) $deployment['revision'] !== (int) $reference['revision']) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment chain changed');
        }
        $revision = $connection->table('sand_ai_config_revision')->json(['payload'], true)->where('resource_type', 'model_deployment')
            ->where('resource_id', (int) $reference['id'])->where('revision', (int) $reference['revision'])->whereNull('delete_time')->lock(true)->find();
        if (!is_array($revision)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment revision changed');
        }
        if ($normalized['provider_id'] !== (int) $provider['id'] || $normalized['model_id'] !== (int) $model['id']) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Existing deployment must retain its provider and model');
        }
        $state = (string) $revision['state'];
        if ($state === 'published') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Published deployment requires a successor deployment');
        }
        if ((int) ($deployment['status'] ?? 0) !== 2) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Unpublished deployment chain is unavailable');
        }
        if ($state === 'verifying') {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_IN_PROGRESS', 'Deployment verification is in progress', true);
        }
        if (!in_array($state, ['draft', 'tested', 'verification_ambiguous'], true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deployment is not available for a new draft revision');
        }
        $this->assertAvailable($provider, $model, $normalized['config'], $normalized['remote_model']);
        return ['provider' => $provider, 'provider_fingerprint' => $this->connectionFingerprint->fromProviderId((int) $provider['id']), 'model' => $model, 'deployment' => $deployment, 'next_revision' => $nextRevision];
    }

    /** @param array{code:string,name:string,adapter:string,timeout_ms:int}|null $provider @return array<string,mixed> */
    private function newProvider(?array $provider, ?RequestScopedProviderConnection $connectionMaterial): array
    {
        if ($provider !== null && in_array($provider['adapter'], ['local_runtime', 'local_ocr_runtime'], true) && $connectionMaterial === null) {
            return Provider::create([...$provider, 'status' => 1])->toArray();
        }
        if ($provider === null || $connectionMaterial === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'New provider connection is required');
        }
        return $connectionMaterial->createProvider($provider);
    }

    /** @param array{code:string,name:string,type:string,capabilities:list<string>}|null $model @return array<string,mixed> */
    private function newModel(?array $model): array
    {
        if ($model === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'New model is required');
        }
        return AiModel::create([...$model, 'status' => 1])->toArray();
    }

    /** @return array<string,mixed> */
    private function lockProvider(ConnectionInterface $connection, int $providerId): array
    {
        $provider = $connection->table('sand_ai_provider')->field(['id', 'code', 'name', 'adapter', 'timeout_ms', 'status'])
            ->fieldRaw("CASE WHEN encrypted_config = '{}'::jsonb THEN FALSE ELSE TRUE END AS has_encrypted_config")
            ->where('id', $providerId)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($provider)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Provider not found');
        }
        return $provider;
    }

    /** @return array<string,mixed> */
    private function lockModel(ConnectionInterface $connection, int $modelId): array
    {
        $model = $connection->table('sand_ai_model')->json(['capabilities'], true)->where('id', $modelId)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($model)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Model not found');
        }
        return $model;
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $model */
    private function assertAvailable(array $provider, array $model, array $config, string $remoteModel): void
    {
        if ((int) ($provider['status'] ?? 0) !== 1 || (int) ($model['status'] ?? 0) !== 1) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Provider or model is unavailable');
        }
        $type = (string) ($model['type'] ?? '');
        if (!in_array($type, ['chat', 'embedding', 'rerank', 'audio_speech', 'audio_transcription', 'image_ocr'], true)
            || !in_array($type, is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Model does not support deployment verification');
        }
        $professionalDriver = in_array($provider['adapter'] ?? null, ['cohere_rerank', 'jina_rerank'], true);
        if (($type === 'rerank') !== $professionalDriver || ($type === 'rerank' && ($model['capabilities'] ?? null) !== ['rerank'])) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Professional rerank requires a matching rerank model and provider');
        }
        (new DeploymentDraftConfigurationPolicy())->assertForModel($config, $type, $remoteModel);
        if ($type === 'audio_speech'
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $remoteModel) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Speech model identifier is invalid');
        }
        if ($type === 'image_ocr' || ($provider['adapter'] ?? null) === 'local_ocr_runtime') {
            \app\Application\File\LocalOcrDeploymentPolicy::assertTarget((string) $provider['adapter'], $type, $remoteModel);
            return;
        }
        if (($provider['adapter'] ?? null) === 'local_runtime') {
            \app\Application\Audio\LocalAudioDeploymentPolicy::assertTarget('local_runtime', $type, $remoteModel);
            return;
        }
        if ($type === 'audio_transcription'
            && !in_array($remoteModel, ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Transcription model identifier is unsupported');
        }
    }

    private function nextRevision(ConnectionInterface $connection, int $modelId): int
    {
        $row = $connection->table('sand_ai_model_deployment')->field(['revision'])->where('model_id', $modelId)
            ->order('revision', 'desc')->lock(true)->find();
        $current = is_array($row) ? ($row['revision'] ?? null) : null;
        if (!is_int($current) && !is_numeric($current)) {
            return 1;
        }
        $revision = (int) $current;
        if ($revision < 0 || $revision >= PHP_INT_MAX) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deployment revision cannot be allocated');
        }
        return $revision + 1;
    }

    private function assertAdvisoryLock(ConnectionInterface $connection, string $idempotencyKey, string $actorRef): void
    {
        $lockFingerprint = $this->requestPolicy->advisoryLockFingerprint($idempotencyKey, $actorRef);
        $connection->execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 138))', ['sand-ai-deployment-draft-save:' . $lockFingerprint]);
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed>|null */
    private function findReplay(ConnectionInterface $connection, string $idempotencyKey, string $actorRef, array $normalized, ?RequestScopedProviderConnection $connectionMaterial): ?array
    {
        $rows = $connection->table('sand_ai_config_revision')->json(['payload'], true)->where('resource_type', 'model_deployment')->whereNull('delete_time')->select();
        foreach ($rows as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $storedKeyId = $this->requestPolicy->replayKeyId($payload);
            if ($storedKeyId === null) {
                continue;
            }
            $save = $payload['draft_save'];
            $candidate = $this->requestPolicy->idempotencyForKeyId($idempotencyKey, $actorRef, $storedKeyId);
            if (!is_string($save['idempotency_hmac'] ?? null) || !hash_equals($candidate['idempotency_hmac'], $save['idempotency_hmac'])) {
                continue;
            }
            $chain = $this->lockExactChain($connection, (int) $row['resource_id'], (int) $row['revision']);
            $payload = $chain['revision']['payload'];
            if (!is_array($payload)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft replay payload changed');
            }
            $storedKeyId = $this->requestPolicy->replayKeyId($payload);
            if ($storedKeyId === null) {
                continue;
            }
            $save = $payload['draft_save'];
            $candidate = $this->requestPolicy->idempotencyForKeyId($idempotencyKey, $actorRef, $storedKeyId);
            if (!is_string($save['idempotency_hmac'] ?? null) || !hash_equals($candidate['idempotency_hmac'], $save['idempotency_hmac'])) {
                continue;
            }
            $secretFingerprint = $connectionMaterial?->executionFingerprint() ?? $this->connectionFingerprint->fromProviderId((int) $chain['provider']['id']);
            $expected = $this->requestPolicy->fingerprintForKeyId($this->canonicalRequest($normalized, $chain['provider'], $secretFingerprint), $idempotencyKey, $actorRef, $storedKeyId);
            if (!is_string($save['request_fingerprint_hmac'] ?? null) || !hash_equals($expected['request_fingerprint_hmac'], $save['request_fingerprint_hmac'])) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Idempotency-Key belongs to another deployment draft request');
            }
            return $this->replaySummary($chain);
        }
        return null;
    }

    /** @param array{provider:array<string,mixed>,model:array<string,mixed>,deployment:array<string,mixed>,revision:array<string,mixed>} $chain @return array<string,mixed> */
    private function replaySummary(array $chain): array
    {
        $deployment = $chain['deployment']; $provider = $chain['provider']; $model = $chain['model']; $revision = $chain['revision'];
        $configuration = $this->draftConfiguration($revision['payload'] ?? null);
        return $this->summary(
            $provider,
            $model,
            [...$deployment, 'remote_model' => $configuration['remote_model'], 'config' => $configuration['config'], 'revision' => (int) $revision['revision']],
            'draft',
            true,
            (int) $revision['revision'],
        );
    }

    /** @param array<string,mixed> $normalized @param array<string,mixed> $provider @param array{version:string,hmac_sha256:string} $secretFingerprint @return array<string,mixed> */
    private function canonicalRequest(array $normalized, array $provider, array $secretFingerprint): array
    {
        return [
            'provider' => ['id' => (int) $provider['id'], 'execution_config' => $secretFingerprint],
            'provider_id' => $normalized['provider_id'], 'provider_request' => $normalized['provider'],
            'model_id' => $normalized['model_id'], 'model' => $normalized['model'], 'deployment_id' => $normalized['deployment_id'],
            'remote_model' => $normalized['remote_model'], 'config' => $normalized['config'],
        ];
    }

    /** @return array{provider:array<string,mixed>,model:array<string,mixed>,deployment:array<string,mixed>,revision:array<string,mixed>} */
    private function lockExactChain(ConnectionInterface $connection, int $deploymentId, ?int $revisionNumber): array
    {
        $reference = $connection->table('sand_ai_model_deployment')->field(['id', 'provider_id', 'model_id'])->where('id', $deploymentId)->whereNull('delete_time')->find();
        if (!is_array($reference)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment draft revision not found');
        }
        $provider = $this->lockProvider($connection, (int) $reference['provider_id']);
        $model = $this->lockModel($connection, (int) $reference['model_id']);
        $deployment = $connection->table('sand_ai_model_deployment')->json(['config'], true)->where('id', $deploymentId)->whereNull('delete_time')->lock(true)->find();
        // Select the current head only after the existing deployment lock; explicit historical reads keep their target.
        $selectedRevision = $revisionNumber ?? (int) ($deployment['revision'] ?? 0);
        $revision = $connection->table('sand_ai_config_revision')->json(['payload'], true)->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)->where('revision', $selectedRevision)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($deployment) || !is_array($revision) || (int) $deployment['provider_id'] !== (int) $reference['provider_id'] || (int) $deployment['model_id'] !== (int) $reference['model_id']) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft chain changed');
        }
        return ['provider' => $provider, 'model' => $model, 'deployment' => $deployment, 'revision' => $revision];
    }

    /**
     * @param array{provider:array<string,mixed>,model:array<string,mixed>,deployment:array<string,mixed>,revision:array<string,mixed>} $chain
     * @return array{configuration:array{remote_model:string,config:array<string,int|string>},model_type:string,capabilities:list<string>}
     */
    private function legacyReadConfiguration(ConnectionInterface $connection, array $chain, int $deploymentId, int $revision): array
    {
        $row = $chain['revision'];
        $payload = $row['payload'] ?? null;
        if (!is_array($payload) || ($payload['onboarding'] ?? null) !== true
            || !in_array($row['state'] ?? null, ['tested', 'published'], true)
            || array_intersect(array_keys($payload), ['draft_save', 'draft_configuration', 'deployment_validation', 'deployment_verification_claim']) !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment draft revision not found');
        }
        $audit = $connection->table('sand_ai_audit_log')->json(['context'], true)
            ->where('action', 'onboarding.model.verified')->where('resource_type', 'model_deployment')
            ->where('resource_id', $deploymentId)->whereNull('delete_time')->order('id', 'desc')->find();
        (new LegacyTestedDeploymentPolicy())->assertUpgradeable($payload, is_array($audit) ? $audit : null, $deploymentId, $revision);
        $snapshot = $payload['execution_snapshot'];
        try {
            $digest = RequestPayloadFingerprint::fingerprint($snapshot, []);
        } catch (\JsonException|\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy tested deployment snapshot is invalid');
        }
        $frozen = $snapshot['deployment'] ?? null;
        $frozenProvider = $snapshot['provider'] ?? null;
        $frozenModel = $snapshot['model'] ?? null;
        if (($snapshot['version'] ?? null) !== DeploymentExecutionSnapshot::VERSION
            || !hash_equals($payload['execution_snapshot_digest'], $digest)
            || !is_array($frozen) || !is_array($frozenProvider) || !is_array($frozenModel)
            || ($frozen['id'] ?? null) !== $deploymentId || ($frozen['revision'] ?? null) !== $revision
            || ($frozen['provider_id'] ?? null) !== (int) $chain['provider']['id']
            || ($frozen['model_id'] ?? null) !== (int) $chain['model']['id']
            || ($frozenProvider['id'] ?? null) !== (int) $chain['provider']['id']
            || ($frozenModel['id'] ?? null) !== (int) $chain['model']['id']
            || ($frozenModel['type'] ?? null) !== 'chat' || ($frozenProvider['adapter'] ?? null) !== 'openai_compatible'
            || ($frozen['status'] ?? null) !== 2 || ($frozenProvider['status'] ?? null) !== 1 || ($frozenModel['status'] ?? null) !== 1) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy tested deployment snapshot identity changed');
        }
        if ($row['state'] === 'published') {
            $published = $payload['published_execution_snapshot'] ?? null;
            $publishedDigest = $payload['published_execution_snapshot_digest'] ?? null;
            $expected = $snapshot;
            $expected['deployment']['status'] = 1;
            try {
                $publicationValid = is_array($published) && is_string($publishedDigest)
                    && hash_equals($publishedDigest, RequestPayloadFingerprint::fingerprint($published, []))
                    && RequestPayloadFingerprint::same($expected, $published, []);
            } catch (\JsonException|\InvalidArgumentException) {
                $publicationValid = false;
            }
            if (!$publicationValid || !(new DeploymentProcessingPolicy())->isLegacyProtocolPayload($payload)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy published deployment snapshot changed');
            }
        } elseif (array_intersect(array_keys($payload), ['processing_policy', 'published_processing_policy', 'published_execution_snapshot', 'published_execution_snapshot_digest']) !== []) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy tested deployment publication state is inconsistent');
        }
        $capabilities = $frozenModel['capabilities'] ?? null;
        if (!is_array($capabilities) || !array_is_list($capabilities)
            || array_filter($capabilities, static fn (mixed $value): bool => !is_string($value)) !== []
            || !in_array('chat', $capabilities, true) || !is_array($frozen['config'] ?? null)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy tested deployment model configuration is invalid');
        }
        $configuration = $this->draftConfiguration(['draft_configuration' => [
            'version' => 'draft_configuration@1', 'remote_model' => RemoteModelIdentifier::normalize($frozen['remote_model'] ?? null), 'config' => $frozen['config'],
        ]]);
        return ['configuration' => $configuration, 'model_type' => 'chat', 'capabilities' => $capabilities];
    }

    private function isDraftSavePayload(mixed $payload): bool
    {
        if (!is_array($payload) || !is_array($payload['draft_configuration'] ?? null) || ($payload['draft_configuration']['version'] ?? null) !== 'draft_configuration@1'
            || !is_array($payload['draft_save'] ?? null) || ($payload['draft_save']['version'] ?? null) !== 'draft_save@1') {
            return false;
        }
        try {
            $this->draftConfiguration($payload);
            return true;
        } catch (ApiProblem) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload @return array{remote_model:string,config:array<string,int|string>} */
    private function draftConfiguration(array $payload): array
    {
        $configuration = $payload['draft_configuration'] ?? null;
        if (!is_array($configuration) || array_diff(array_keys($configuration), ['version', 'remote_model', 'config']) !== []
            || ($configuration['version'] ?? null) !== 'draft_configuration@1' || !is_string($configuration['remote_model'])
            || trim($configuration['remote_model']) === '' || strlen($configuration['remote_model']) > 256 || !is_array($configuration['config'] ?? null)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft configuration is invalid');
        }
        try {
            $config = (new DeploymentDraftConfigurationPolicy())->normalize($configuration['config']);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft configuration is invalid');
        }
        return ['remote_model' => $configuration['remote_model'], 'config' => $config];
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $model @param array<string,mixed> $deployment @return array<string,mixed> */
    private function summary(array $provider, array $model, array $deployment, string $state, bool $replay, ?int $revision = null): array
    {
        $config = is_array($deployment['config'] ?? null) ? $deployment['config'] : [];
        try {
            $config = (new DeploymentDraftConfigurationPolicy())->normalize($config);
            (new DeploymentDraftConfigurationPolicy())->assertForModel($config, (string) ($model['type'] ?? ''), (string) ($deployment['remote_model'] ?? ''));
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft config is invalid');
        }
        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        if (!array_is_list($capabilities) || array_filter($capabilities, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment draft model is invalid');
        }
        return [
            'deployment_id' => (int) $deployment['id'], 'revision' => $revision ?? (int) $deployment['revision'], 'state' => $state, 'idempotent_replay' => $replay,
            'provider' => ['id' => (int) $provider['id'], 'code' => (string) $provider['code'], 'name' => (string) $provider['name'], 'adapter' => (string) $provider['adapter'], 'timeout_ms' => (int) $provider['timeout_ms'], 'has_encrypted_config' => (bool) ($provider['has_encrypted_config'] ?? false)],
            'model' => ['id' => (int) $model['id'], 'code' => (string) $model['code'], 'name' => (string) $model['name'], 'type' => (string) $model['type'], 'capabilities' => $capabilities],
            'deployment' => ['remote_model' => (string) $deployment['remote_model'], 'config' => $config],
        ];
    }
}
