<?php

declare(strict_types=1);

namespace app\Application\Capability;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentProcessingPolicy;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

final class CapabilityRouteCompatibilityPolicy
{
    public function __construct(private readonly DeploymentProcessingPolicy $deploymentProcessing = new DeploymentProcessingPolicy())
    {
    }

    /** @param list<array<string,mixed>> $routes @return list<array<string,mixed>> */
    public function normalizeAndValidate(array $routes, string $dataEgressPolicy, bool $requirePublished, bool $lockRows): array
    {
        $normalized = CapabilityProfilePolicy::normalizeRoutes($routes, $dataEgressPolicy);
        foreach ($normalized as $route) {
            if (!in_array($route['route_kind'], ['model_deployment', 'audio_driver'], true) && !($route['capability_code'] === 'image_ocr' && $route['deployment_id'] !== null)) {
                continue;
            }
            $reference = ModelDeployment::where('id', (int) $route['deployment_id'])->find();
            if ($reference === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Capability route deployment not found');
            }
            $providerQuery = Provider::where('id', (int) $reference->provider_id)->where('status', 1);
            $modelQuery = AiModel::where('id', (int) $reference->model_id)->where('status', 1);
            $deploymentQuery = ModelDeployment::where('id', (int) $reference->id)->where('status', $requirePublished ? 1 : (int) $reference->status);
            if ($lockRows) {
                $providerQuery->lock(true);
                $modelQuery->lock(true);
                $deploymentQuery->lock(true);
            }
            $provider = $providerQuery->find();
            $model = $modelQuery->find();
            $deployment = $deploymentQuery->find();
            $metadata = CapabilityDriverRegistry::capability((string) $route['capability_code']);
            $capabilities = is_array($model?->capabilities) ? $model->capabilities : [];
            if ($provider === null || $model === null || $deployment === null
                || (int) $deployment->provider_id !== (int) $provider->id
                || (int) $deployment->model_id !== (int) $model->id
                || !in_array((string) $model->type, $metadata['model_types'] ?? [], true)
                || !in_array((string) $route['capability_code'], $capabilities, true)
                || ((string) $route['capability_code'] === 'rerank' && $capabilities !== ['rerank'])
                || (isset($metadata['provider_adapters']) && !in_array((string) $provider->adapter, $metadata['provider_adapters'], true))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route deployment does not support this capability');
            }
            $revisionQuery = ConfigRevision::where('resource_type', 'model_deployment')
                ->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)
                ->where('status', 1);
            if ($requirePublished) {
                $revisionQuery->where('state', CapabilityProfilePolicy::PUBLISHED);
            }
            if ($lockRows) {
                $revisionQuery->lock(true);
            }
            $revision = $revisionQuery->find();
            if ($revision === null) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Capability route deployment is not published');
            }
            if ($route['capability_code'] === 'image_ocr') {
                \app\Application\File\LocalOcrDeploymentPolicy::assertPublishedRuntime($provider, $model, $deployment, $revision);
            }
            if ($route['route_kind'] === 'audio_driver') {
                \app\Application\Audio\LocalAudioDeploymentPolicy::assertDriverTarget((string) $route['driver_code'], $provider, $model, $deployment);
                if ($requirePublished && $route['driver_code'] === 'local_whisper_cpp') {
                    \app\Application\Audio\LocalAudioDeploymentPolicy::assertPublishedRuntime($provider, $model, $deployment, $revision);
                }
            }
            $payload = is_array($revision->payload) ? $revision->payload : [];
            if ($requirePublished) {
                $this->deploymentProcessing->requirePublishedCompatible($payload, $dataEgressPolicy);
            } else {
                $this->deploymentProcessing->requireCompatible($payload, $dataEgressPolicy);
            }
        }
        return $normalized;
    }
}
