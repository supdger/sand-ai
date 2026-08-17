<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\admin\validate\CapabilityProfileValidate;
use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\EnvironmentReferenceVerifier;
use plugin\SandAi\app\domain\capability\CapabilityProfilePolicy;
use plugin\SandAi\app\infrastructure\capability\CapabilityDriverRegistry;
use plugin\SandAi\app\model\AiModel;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\CapabilityProfile;
use plugin\SandAi\app\model\CapabilityRoute;
use plugin\SandAi\app\model\ConfigRevision;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\SandAi\app\model\Provider;
use think\facade\Db;

/** Package-local capability-profile administration with SandIAM references. */
final class CapabilityProfileLogic
{
    public function __construct(
        private readonly CapabilityProfileValidate $validate,
        private readonly EnvironmentReferenceVerifier $environments,
    ) {
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return ['capabilities' => CapabilityDriverRegistry::metadata(), 'templates' => CapabilityProfilePolicy::templates()];
    }

    /** @return array<string, mixed>|null */
    public function read(int $environmentId): ?array
    {
        if ($environmentId <= 0) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id is required');
        }
        $this->environments->requireEnvironment($environmentId);
        $profile = CapabilityProfile::where('environment_id', $environmentId)->order('revision', 'desc')->find();
        return $profile === null ? null : $this->summary($profile, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(array $input): array
    {
        $data = $this->validate->save($input);
        $this->environments->requireEnvironment($data['environment_id']);
        $this->validateDeployments($data['routes'], $data['data_egress_policy'], false);

        return Db::connect('pgsql')->transaction(function () use ($data): array {
            $previous = CapabilityProfile::where('environment_id', $data['environment_id'])->order('revision', 'desc')->find();
            $revision = $previous === null ? 1 : (int) $previous->revision + 1;
            $profile = CapabilityProfile::create([
                'environment_id' => $data['environment_id'],
                'name' => $data['name'],
                'template_code' => $data['template_code'],
                'data_egress_policy' => $data['data_egress_policy'],
                'revision' => $revision,
                'state' => CapabilityProfilePolicy::DRAFT,
                'status' => 2,
            ]);
            foreach ($data['routes'] as $route) {
                CapabilityRoute::create(['profile_id' => (int) $profile->id, ...$route]);
            }
            ConfigRevision::create([
                'resource_type' => 'capability_profile',
                'resource_id' => (int) $profile->id,
                'revision' => $revision,
                'payload' => ['template_code' => $data['template_code'], 'data_egress_policy' => $data['data_egress_policy'], 'routes' => $data['routes']],
                'state' => CapabilityProfilePolicy::DRAFT,
                'status' => 1,
            ]);
            $this->audit($data['environment_id'], 'capability_profile.draft', (int) $profile->id, 'Created SandAI capability profile draft', ['revision' => $revision]);
            return $this->summary($profile, true);
        });
    }

    /** @return array<string, mixed> */
    public function publish(int $environmentId, int $profileId): array
    {
        if ($environmentId <= 0 || $profileId <= 0) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id and id are required');
        }
        $this->environments->requireEnvironment($environmentId);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $profileId): array {
            $profile = CapabilityProfile::where('id', $profileId)->where('environment_id', $environmentId)->find();
            if ($profile === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Capability profile not found');
            }
            $revision = ConfigRevision::where('resource_type', 'capability_profile')->where('resource_id', $profileId)->where('revision', (int) $profile->revision)->find();
            if ($revision === null || (string) $revision->state !== CapabilityProfilePolicy::DRAFT) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Capability profile is not publishable');
            }
            $routes = CapabilityRoute::where('profile_id', $profileId)->where('status', 1)->select()->toArray();
            $normalized = CapabilityProfilePolicy::normalizeRoutes($routes, (string) $profile->data_egress_policy);
            $this->validateDeployments($normalized, (string) $profile->data_egress_policy, true);
            CapabilityProfile::where('environment_id', $environmentId)->where('state', CapabilityProfilePolicy::PUBLISHED)->update(['state' => 'archived', 'status' => 2]);
            $profile->save(['state' => CapabilityProfilePolicy::PUBLISHED, 'status' => 1]);
            $revision->save(['state' => CapabilityProfilePolicy::PUBLISHED, 'published_at' => date('Y-m-d H:i:s')]);
            $this->audit($environmentId, 'capability_profile.publish', $profileId, 'Published SandAI capability profile', ['revision' => (int) $profile->revision]);
            return $this->summary($profile, true);
        });
    }

    /** @param list<array<string, mixed>> $routes */
    private function validateDeployments(array $routes, string $policy, bool $requirePublished): void
    {
        foreach ($routes as $route) {
            if ($route['route_kind'] !== 'model_deployment') {
                continue;
            }
            $query = ModelDeployment::where('id', (int) $route['deployment_id']);
            if ($requirePublished) {
                $query->where('status', 1);
            } else {
                $query->whereIn('status', [1, 2]);
            }
            $deployment = $query->find();
            if ($deployment === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Capability route deployment not found');
            }
            $model = AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
            $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            $metadata = CapabilityDriverRegistry::capability((string) $route['capability_code']);
            if ($model === null || $provider === null || !in_array((string) $model->type, $metadata['model_types'] ?? [], true)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route deployment does not support this capability');
            }
            if (CapabilityProfilePolicy::requiresLocalProcessing($policy) && (string) $provider->adapter !== 'fake') {
                throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The profile does not permit this provider egress');
            }
            if ($requirePublished && !ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->find()) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Capability route deployment is not published');
            }
        }
    }

    /** @return array<string, mixed> */
    private function summary(CapabilityProfile $profile, bool $withRoutes): array
    {
        $result = ['id' => (int) $profile->id, 'environment_id' => (int) $profile->environment_id, 'name' => (string) $profile->name, 'template_code' => $profile->template_code, 'data_egress_policy' => (string) $profile->data_egress_policy, 'revision' => (int) $profile->revision, 'state' => (string) $profile->state, 'status' => (int) $profile->status];
        if ($withRoutes) {
            $result['routes'] = CapabilityRoute::where('profile_id', (int) $profile->id)->order('priority')->select()->map(static fn (CapabilityRoute $route): array => ['id' => (int) $route->id, 'capability_code' => (string) $route->capability_code, 'route_kind' => (string) $route->route_kind, 'deployment_id' => $route->deployment_id === null ? null : (int) $route->deployment_id, 'driver_code' => $route->driver_code, 'priority' => (int) $route->priority, 'config' => is_array($route->config) ? $route->config : [], 'status' => (int) $route->status])->all();
        }
        return $result;
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $profileId, string $summary, array $context): void
    {
        AuditLog::create(['actor_type' => 'admin', 'actor_ref' => (string) $environmentId, 'action' => $action, 'resource_type' => 'capability_profile', 'resource_id' => $profileId, 'summary' => $summary, 'context' => $context]);
    }
}
