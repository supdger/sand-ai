<?php

declare(strict_types=1);

namespace app\Application\Capability;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentProcessingPolicy;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

final class CapabilityProfileResolver
{
    public function __construct(private readonly DeploymentProcessingPolicy $deploymentProcessing = new DeploymentProcessingPolicy())
    {
    }
    /** @return list<array<string, mixed>> */
    public function routes(int $environmentId, string $capabilityCode): array
    {
        $profile = $this->publishedProfile($environmentId);
        if ($profile === null) {
            return [];
        }
        $policy = (string) $profile->data_egress_policy;
        $routes = CapabilityRoute::where('profile_id', (int) $profile->id)
            ->where('capability_code', $capabilityCode)
            ->where('status', 1)
            ->order('priority')
            ->select();

        return $routes->map(function (CapabilityRoute $route) use ($profile, $policy): array {
            $kind = (string) $route->route_kind;
            if ($kind === 'parse_driver') {
                $driver = CapabilityDriverRegistry::parseDriver((string) $route->capability_code, (string) $route->driver_code);
                if (CapabilityProfilePolicy::requiresLocalProcessing($policy) && (string) $driver['data_egress'] !== 'local_only') {
                    throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The published capability profile forbids this parse driver');
                }
                $ocrTarget = [];
                if ((string) $route->capability_code === 'image_ocr' && $route->deployment_id !== null) {
                    $ocrTarget = \app\Application\File\LocalOcrDeploymentPolicy::publishedTarget((int) $route->deployment_id);
                }
                return $ocrTarget + [
                    'profile_id' => (int) $profile->id,
                    'profile_revision' => (int) $profile->revision,
                    'route_id' => (int) $route->id,
                    'capability_code' => (string) $route->capability_code,
                    'route_kind' => $kind,
                    'driver_code' => (string) $route->driver_code,
                    'priority' => (int) $route->priority,
                    'config' => $this->arrayValue($route->config),
                ];
            }

            $deployment = ModelDeployment::where('id', (int) $route->deployment_id)->where('status', 1)->find();
            $deploymentRevision = $deployment === null ? null : ConfigRevision::where('resource_type', 'model_deployment')
                ->where('resource_id', (int) $deployment->id)
                ->where('revision', (int) $deployment->revision)
                ->where('state', CapabilityProfilePolicy::PUBLISHED)
                ->where('status', 1)
                ->find();
            if ($deployment === null || $deploymentRevision === null) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'A capability route references an unpublished deployment');
            }
            $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            $model = \app\Infrastructure\Persistence\Model\AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
            if ($provider === null || $model === null || !in_array((string) $model->type, CapabilityDriverRegistry::capability((string) $route->capability_code)['model_types'] ?? [], true) || !in_array((string) $route->capability_code, is_array($model->capabilities) ? $model->capabilities : [], true)) {
                throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'A capability route provider is unavailable');
            }
            $this->deploymentProcessing->requirePublishedCompatible(is_array($deploymentRevision->payload) ? $deploymentRevision->payload : [], $policy);
            if ($kind === 'audio_driver') {
                $driver = CapabilityDriverRegistry::parseDriver((string) $route->capability_code, (string) $route->driver_code);
                if (CapabilityProfilePolicy::requiresLocalProcessing($policy) && (string) $driver['data_egress'] !== 'local_only') {
                    throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The audio route does not match the published data egress policy');
                }
                \app\Application\Audio\LocalAudioDeploymentPolicy::assertDriverTarget((string) $route->driver_code, $provider, $model, $deployment);
                if ((string) $route->driver_code === 'local_whisper_cpp') {
                    \app\Application\Audio\LocalAudioDeploymentPolicy::assertPublishedRuntime($provider, $model, $deployment, $deploymentRevision);
                }
            }
            return [
                'profile_id' => (int) $profile->id,
                'profile_revision' => (int) $profile->revision,
                'route_id' => (int) $route->id,
                'capability_code' => (string) $route->capability_code,
                'route_kind' => $kind,
                'deployment_id' => (int) $deployment->id,
                'provider_id' => (int) $provider->id,
                'model_id' => (int) $model->id,
                'driver_code' => $kind === 'audio_driver' ? (string) $route->driver_code : null,
                'priority' => (int) $route->priority,
                'config' => $this->arrayValue($route->config),
            ];
        })->all();
    }

    /** @return array<string, mixed>|null */
    public function primary(int $environmentId, string $capabilityCode): ?array
    {
        return $this->routes($environmentId, $capabilityCode)[0] ?? null;
    }

    public function hasPublishedProfile(int $environmentId): bool
    {
        return $this->publishedProfile($environmentId) !== null;
    }

    private function publishedProfile(int $environmentId): ?CapabilityProfile
    {
        return CapabilityProfile::where('environment_id', $environmentId)
            ->where('state', CapabilityProfilePolicy::PUBLISHED)
            ->where('status', 1)
            ->order('revision', 'desc')
            ->find();
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        $value = is_object($value) ? (array) $value : $value;
        return is_array($value) ? $value : [];
    }
}
