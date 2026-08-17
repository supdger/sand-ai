<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\capability;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\infrastructure\capability\CapabilityDriverRegistry;
use plugin\SandAi\app\model\AiModel;
use plugin\SandAi\app\model\CapabilityProfile;
use plugin\SandAi\app\model\CapabilityRoute;
use plugin\SandAi\app\model\ConfigRevision;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\SandAi\app\model\Provider;

/**
 * Resolves only SandAI-owned published routes for an already-authorized
 * environment. It deliberately never decides whether the caller owns that
 * environment; IdentityContextProvider is the authorization boundary.
 */
final class CapabilityProfileResolver
{
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

                return [
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
            if ($deployment === null || !ConfigRevision::where('resource_type', 'model_deployment')
                ->where('resource_id', (int) $deployment->id)
                ->where('revision', (int) $deployment->revision)
                ->where('state', CapabilityProfilePolicy::PUBLISHED)
                ->where('status', 1)
                ->find()) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'A capability route references an unpublished deployment');
            }
            $model = AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
            $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            if ($model === null || $provider === null) {
                throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'A capability route provider or model is unavailable');
            }
            if (CapabilityProfilePolicy::requiresLocalProcessing($policy) && (string) $provider->adapter !== 'fake') {
                throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The published capability profile forbids this provider egress');
            }

            return [
                'profile_id' => (int) $profile->id,
                'profile_revision' => (int) $profile->revision,
                'route_id' => (int) $route->id,
                'capability_code' => (string) $route->capability_code,
                'route_kind' => $kind,
                'deployment_id' => (int) $deployment->id,
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
