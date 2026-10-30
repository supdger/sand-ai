<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfilePolicy;
use app\Application\Capability\CapabilityRouteCompatibilityPolicy;
use app\Application\Capability\EnvironmentCapabilityLock;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use think\db\ConnectionInterface;
use think\facade\Db;

/**
 * Explicitly turns a tested onboarding draft into a runtime configuration.
 * Creating a provider/model is deliberately not sufficient to enable calls.
 */
final class OnboardingPublicationService
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly EnvironmentCapabilityLock $environmentLock = new EnvironmentCapabilityLock(),
        private readonly CapabilityRouteCompatibilityPolicy $routeCompatibility = new CapabilityRouteCompatibilityPolicy(),
        private readonly DeploymentExecutionSnapshot $executionSnapshot = new DeploymentExecutionSnapshot(),
        private readonly DeploymentPublicationChainLoader $publicationChain = new DeploymentPublicationChainLoader(),
        private readonly DeploymentPublicationChainPolicy $publicationChainPolicy = new DeploymentPublicationChainPolicy(),
        private readonly DeploymentPublicationLifecyclePolicy $publicationLifecycle = new DeploymentPublicationLifecyclePolicy(),
        private readonly DeploymentProcessingPolicy $processingPolicy = new DeploymentProcessingPolicy(),
        private readonly DeploymentValidationEvidence $validationEvidence = new DeploymentValidationEvidence(),
        private readonly LegacyTestedDeploymentPolicy $legacyTestedPolicy = new LegacyTestedDeploymentPolicy(),
    ) {}

    /** @return array{deployment_id:int,state:string,idempotent_replay:bool} */
    public function publishTestedDeployment(int $deploymentId, string $actorRef, ?int $expectedRevision = null): array
    {
        if ($deploymentId < 1 || ($expectedRevision !== null && $expectedRevision < 1)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A tested deployment id is required');
        }

        $actorRef = $this->requireActor($actorRef);
        return Db::connect('pgsql')->transaction(function ($connection) use ($deploymentId, $actorRef, $expectedRevision): array {
            $chain = $this->publicationChain->lockCurrent($connection, $deploymentId);
            $this->publicationChainPolicy->assertConsistent($chain, $deploymentId);
            $provider = $chain['provider'];
            $model = $chain['model'];
            $deployment = $chain['deployment'];
            $revision = $chain['revision'];
            if (!is_array($provider) || !is_array($model) || !is_array($deployment) || !is_array($revision)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested deployment chain changed after it was identified');
            }
            if ($expectedRevision !== null && ((int) ($deployment['revision'] ?? 0) !== $expectedRevision
                || (int) ($revision['revision'] ?? 0) !== $expectedRevision)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The selected deployment revision changed before publication');
            }
            $payload = is_array($revision['payload'] ?? null) ? $revision['payload'] : [];
            $lifecycle = $this->publicationLifecycle->select(
                $payload,
                is_string($revision['state'] ?? null) ? $revision['state'] : '',
                (int) ($revision['status'] ?? 0),
                (int) ($deployment['status'] ?? 0),
            );
            $this->executionSnapshot->assertArrayMatches(
                $lifecycle['snapshot'], $lifecycle['digest'], $provider, $model, $deployment,
            );
            $modelType = (string) ($model['type'] ?? '');
            if ((int) ($provider['status'] ?? 0) !== 1 || (int) ($model['status'] ?? 0) !== 1
                || !in_array($modelType, ['chat', 'embedding', 'rerank', 'audio_speech', 'audio_transcription', 'image_ocr'], true)
                || !in_array($modelType, is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [], true)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested provider, model, or deployment availability has changed');
            }
            try {
                $settings = (new DeploymentDraftConfigurationPolicy())->normalize(
                    is_array($deployment['config'] ?? null) ? $deployment['config'] : [],
                );
                (new DeploymentDraftConfigurationPolicy())->assertForModel($settings, $modelType, (string) ($deployment['remote_model'] ?? ''));
                if ($modelType === 'image_ocr' || ($provider['adapter'] ?? null) === 'local_ocr_runtime') {
                    \app\Application\File\LocalOcrDeploymentPolicy::assertTarget((string) $provider['adapter'], $modelType, (string) ($deployment['remote_model'] ?? ''));
                } elseif (($provider['adapter'] ?? null) === 'local_runtime') {
                    \app\Application\Audio\LocalAudioDeploymentPolicy::assertTarget('local_runtime', $modelType, (string) ($deployment['remote_model'] ?? ''));
                } elseif ($modelType === 'audio_transcription'
                    && !in_array((string) ($deployment['remote_model'] ?? ''), ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'], true)) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested transcription model is unsupported');
                }
            } catch (ApiProblem) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested deployment settings have changed');
            }
            if ($lifecycle['published']) {
                $this->processingPolicy->fromPublishedPayload($payload);
                if (!$this->processingPolicy->isLegacyProtocolPayload($payload) && $this->validationEvidence->fromPayload(
                    $payload,
                    $deploymentId,
                    (int) ($revision['revision'] ?? 0),
                ) === null) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Published deployment validation evidence is incomplete');
                }
                return ['deployment_id' => $deploymentId, 'state' => 'published', 'idempotent_replay' => true];
            }
            $processing = null;
            if ($this->processingPolicy->isApi142Payload($payload)) {
                $processing = $this->processingPolicy->fromPayload($payload);
                if ($processing['policy'] === null || $this->validationEvidence->fromPayload(
                    $payload,
                    $deploymentId,
                    (int) ($revision['revision'] ?? 0),
                ) === null) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested deployment processing policy or validation evidence is incomplete');
                }
            } else {
                $processing = $this->legacyTestedProcessingPolicy($connection, $payload, $deploymentId, (int) ($revision['revision'] ?? 0));
            }

            $deployment = (new ModelDeployment($deployment))->exists(true);
            $revision = (new ConfigRevision($revision))->exists(true);
            $deployment->save(['status' => 1]);
            $publishedDeployment = [...$chain['deployment'], 'status' => 1];
            $publishedExecution = $this->executionSnapshot->captureArrays($provider, $model, $publishedDeployment);
            $payload['published_execution_snapshot'] = $publishedExecution['snapshot'];
            $payload['published_execution_snapshot_digest'] = $publishedExecution['sha256'];
            $payload['processing_policy'] = $processing['policy'];
            $payload['published_processing_policy'] = $processing['policy'];
            $revision->save(['payload' => $payload, 'state' => CapabilityProfilePolicy::PUBLISHED, 'published_at' => date('Y-m-d H:i:s')]);
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => 'onboarding.deployment.published',
                'resource_type' => 'model_deployment', 'resource_id' => $deploymentId,
                'summary' => 'Published tested SandAI onboarding deployment', 'context' => ['outcome' => 'published'],
            ]);
            return ['deployment_id' => $deploymentId, 'state' => 'published', 'idempotent_replay' => false];
        });
    }

    /** @return array{profile:array<string,mixed>,idempotent_replay:bool} */
    public function bindDefaultChatRoute(int $environmentId, int $deploymentId, string $actorRef, ?int $expectedRevision = null): array
    {
        if ($environmentId < 1 || $deploymentId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id and deployment_id are required');
        }
        if ($expectedRevision !== null && $expectedRevision < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'expected_revision must be a positive integer');
        }
        $actorRef = $this->requireActor($actorRef);
        $this->requireEnvironment($environmentId);

        return Db::connect('pgsql')->transaction(function ($connection) use ($environmentId, $deploymentId, $actorRef, $expectedRevision): array {
            $this->environmentLock->acquire($connection, $environmentId);
            if ($expectedRevision !== null) {
                $chain = $this->publicationChain->lockCurrent($connection, $deploymentId);
                $deployment = $chain['deployment'];
                $revision = $chain['revision'];
                if (!is_array($deployment) || !is_array($revision)
                    || (int) ($deployment['revision'] ?? 0) !== $expectedRevision
                    || (int) ($revision['revision'] ?? 0) !== $expectedRevision
                    || (int) ($deployment['status'] ?? 0) !== 1
                    || (int) ($revision['status'] ?? 0) !== 1
                    || ($revision['state'] ?? null) !== CapabilityProfilePolicy::PUBLISHED) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The published deployment revision changed after confirmation');
                }
            }
            $profileName = '首次开通默认聊天能力';
            $dataEgressPolicy = 'approved_cloud';
            $desiredRoutes = $this->routeCompatibility->normalizeAndValidate([[
                'capability_code' => 'chat', 'route_kind' => 'model_deployment', 'deployment_id' => $deploymentId,
                'driver_code' => null, 'priority' => 100, 'config' => [],
            ]], $dataEgressPolicy, true, true);
            $desiredConfiguration = CapabilityProfilePolicy::canonicalConfiguration($profileName, null, $dataEgressPolicy, $desiredRoutes);
            $published = CapabilityProfile::where('environment_id', $environmentId)
                ->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->lock(true)->find();
            if ($published !== null) {
                $publishedRevision = ConfigRevision::where('resource_type', 'capability_profile')
                    ->where('resource_id', (int) $published->id)->where('revision', (int) $published->revision)
                    ->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->lock(true)->find();
                $existingRoutes = CapabilityRoute::where('profile_id', (int) $published->id)->order('priority')->lock(true)->select()->toArray();
                $existingRouteRequests = [];
                foreach ($existingRoutes as $existingRoute) {
                    if ((int) ($existingRoute['status'] ?? 0) !== 1) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The published profile contains an inactive route');
                    }
                    // Persisted rows include identity/status fields. Rebuild
                    // only the public route input; normalization supplies status.
                    $existingRouteRequests[] = [
                        'capability_code' => (string) $existingRoute['capability_code'],
                        'route_kind' => (string) $existingRoute['route_kind'],
                        'deployment_id' => $existingRoute['deployment_id'] === null ? null : (int) $existingRoute['deployment_id'],
                        'driver_code' => $existingRoute['driver_code'],
                        'priority' => (int) $existingRoute['priority'],
                        'config' => $existingRoute['config'],
                    ];
                }
                $existingNormalized = CapabilityProfilePolicy::normalizeRoutes($existingRouteRequests, (string) $published->data_egress_policy);
                $existingConfiguration = CapabilityProfilePolicy::canonicalConfiguration(
                    (string) $published->getAttr('name'),
                    $published->template_code === null ? null : (string) $published->template_code,
                    (string) $published->data_egress_policy,
                    $existingNormalized,
                );
                $revisionPayload = is_array($publishedRevision?->payload) ? $publishedRevision->payload : [];
                if ($publishedRevision === null || !hash_equals(
                    CapabilityProfilePolicy::canonicalDigest($existingConfiguration),
                    CapabilityProfilePolicy::canonicalDigest($revisionPayload),
                )) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The published profile revision differs from its current routes');
                }
                if ($existingConfiguration === $desiredConfiguration) {
                    return ['profile' => [
                        'id' => (int) $published->id, 'environment_id' => $environmentId, 'name' => (string) $published->getAttr('name'),
                        'revision' => (int) $published->revision, 'state' => (string) $published->state,
                        'routes' => $desiredRoutes,
                    ], 'idempotent_replay' => true];
                }
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The environment profile differs from the exact default chat binding');
            }
            // SandIAM was verified before acquiring this local transaction.
            // Do not make an HTTP identity call while profile/route rows are
            // locked; write the same minimal published shape atomically.
            $profile = CapabilityProfile::create([
                'environment_id' => $environmentId,
                'name' => $profileName,
                'template_code' => null,
                'data_egress_policy' => $dataEgressPolicy,
                'revision' => 1,
                'state' => CapabilityProfilePolicy::PUBLISHED,
                'status' => 1,
            ]);
            $route = $desiredRoutes[0];
            unset($route['route_kind']);
            CapabilityRoute::create(['profile_id' => (int) $profile->id, ...$route]);
            ConfigRevision::create([
                'resource_type' => 'capability_profile', 'resource_id' => (int) $profile->id, 'revision' => 1,
                'payload' => $desiredConfiguration,
                'state' => CapabilityProfilePolicy::PUBLISHED, 'published_at' => date('Y-m-d H:i:s'), 'status' => 1,
            ]);
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => 'onboarding.default_chat_bound',
                'resource_type' => 'capability_profile', 'resource_id' => (int) $profile->id,
                'summary' => 'Bound default SandAI chat capability for onboarding',
                'context' => ['environment_id' => $environmentId, 'deployment_id' => $deploymentId],
            ]);
            return ['profile' => [
                'id' => (int) $profile->id, 'environment_id' => $environmentId, 'name' => (string) $profile->getAttr('name'),
                'revision' => 1, 'state' => CapabilityProfilePolicy::PUBLISHED,
                'routes' => $desiredRoutes,
            ], 'idempotent_replay' => false];
        });
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    /** @param array<string,mixed> $payload @return array{policy:array<string,mixed>} */
    private function legacyTestedProcessingPolicy(ConnectionInterface $connection, array $payload, int $deploymentId, int $revision): array
    {
        $audit = $connection->table('sand_ai_audit_log')
            ->json(['context'], true)
            ->where('action', 'onboarding.model.verified')->where('resource_type', 'model_deployment')
            ->where('resource_id', $deploymentId)->whereNull('delete_time')->order('id', 'desc')->find();
        $this->legacyTestedPolicy->assertUpgradeable($payload, is_array($audit) ? $audit : null, $deploymentId, $revision);
        return ['policy' => $this->processingPolicy->legacyProtocolDefault()];
    }

    private function requireActor(string $actorRef): string
    {
        $actorRef = trim($actorRef);
        if ($actorRef === '') {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        return $actorRef;
    }
}
