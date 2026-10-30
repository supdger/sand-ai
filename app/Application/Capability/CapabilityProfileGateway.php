<?php

declare(strict_types=1);

namespace app\Application\Capability;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use think\facade\Db;

final class CapabilityProfileGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly EnvironmentCapabilityLock $environmentLock = new EnvironmentCapabilityLock(),
        private readonly CapabilityRouteCompatibilityPolicy $routeCompatibility = new CapabilityRouteCompatibilityPolicy(),
    ) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return ['capabilities' => CapabilityDriverRegistry::metadata(), 'templates' => CapabilityProfilePolicy::templates()];
    }

    /** @return array<string, mixed>|null */
    public function read(int $environmentId): ?array
    {
        $this->requireEnvironment($environmentId);
        $profile = CapabilityProfile::where('environment_id', $environmentId)->order('revision', 'desc')->find();
        return $profile === null ? null : $this->summary($profile, true);
    }

    /** @return array<string, mixed>|null */
    public function readPublished(int $environmentId): ?array
    {
        $this->requireEnvironment($environmentId);
        $profile = CapabilityProfile::where('environment_id', $environmentId)
            ->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)
            ->order('revision', 'desc')->find();
        return $profile === null ? null : $this->summary($profile, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(array $input, string $actorRef): array
    {
        $environmentId = (int) ($input['environment_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $templateCode = trim((string) ($input['template_code'] ?? '')) ?: null;
        $policy = trim((string) ($input['data_egress_policy'] ?? ''));
        $routes = $input['routes'] ?? [];
        $expectedRevision = $input['expected_revision'] ?? null;
        if ($environmentId <= 0 || $name === '' || !is_array($routes)
            || !array_key_exists('expected_revision', $input)
            || ($expectedRevision !== null && (!is_int($expectedRevision) || $expectedRevision < 1))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id, expected_revision, name and routes are required');
        }
        $actorRef = $this->requireActor($actorRef);
        $this->requireEnvironment($environmentId);
        if ($templateCode !== null) {
            $template = CapabilityProfilePolicy::templates()[$templateCode] ?? null;
            if ($template === null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Unknown capability template');
            }
            $policy = $policy ?: (string) $template['data_egress_policy'];
            $routes = $routes === [] ? $template['default_routes'] : $routes;
        }
        return Db::connect('pgsql')->transaction(function ($connection) use ($environmentId, $expectedRevision, $name, $templateCode, $policy, $routes, $actorRef): array {
            $this->environmentLock->acquire($connection, $environmentId);
            $previous = CapabilityProfile::where('environment_id', $environmentId)->order('revision', 'desc')->lock(true)->find();
            if (($previous === null && $expectedRevision !== null)
                || ($previous !== null && (int) $previous->revision !== $expectedRevision)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Capability profile changed; read the latest revision before saving');
            }
            $normalizedRoutes = $this->routeCompatibility->normalizeAndValidate($routes, $policy, true, true);
            if ($normalizedRoutes === []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A capability profile requires at least one route');
            }
            if ($previous !== null) {
                foreach (CapabilityRoute::where('profile_id', (int) $previous->id)->select() as $previousRoute) {
                    if (($previousRoute->getData()['config'] ?? null) !== []) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The current capability profile contains route configuration that cannot be safely preserved');
                    }
                }
            }
            $revision = $previous === null ? 1 : (int) $previous->revision + 1;
            $profile = CapabilityProfile::create([
                'environment_id' => $environmentId,
                'name' => $name,
                'template_code' => $templateCode,
                'data_egress_policy' => $policy,
                'revision' => $revision,
                'state' => CapabilityProfilePolicy::DRAFT,
                'status' => 2,
            ]);
            foreach ($normalizedRoutes as $route) {
                unset($route['route_kind']);
                CapabilityRoute::create(['profile_id' => (int) $profile->id, ...$route]);
            }
            ConfigRevision::create([
                'resource_type' => 'capability_profile',
                'resource_id' => (int) $profile->id,
                'revision' => $revision,
                'payload' => CapabilityProfilePolicy::canonicalConfiguration($name, $templateCode, $policy, $normalizedRoutes),
                'state' => CapabilityProfilePolicy::DRAFT,
                'status' => 1,
            ]);
            $this->audit($environmentId, $actorRef, 'capability_profile.draft', (int) $profile->id, 'Created SandAI capability profile draft', ['revision' => $revision]);
            return $this->summary($profile, true);
        });
    }

    /** @return array<string, mixed> */
    public function publish(int $environmentId, int $profileId, string $actorRef, ?int $expectedRevision = null): array
    {
        if ($expectedRevision !== null && $expectedRevision < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'expected_revision must be a positive integer');
        }
        $this->requireEnvironment($environmentId);
        $actorRef = $this->requireActor($actorRef);
        return Db::connect('pgsql')->transaction(function ($connection) use ($environmentId, $profileId, $actorRef, $expectedRevision): array {
            $this->environmentLock->acquire($connection, $environmentId);
            $profile = CapabilityProfile::where('id', $profileId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($profile === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Capability profile not found');
            }
            if ($expectedRevision !== null && (int) $profile->revision !== $expectedRevision) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The capability profile revision changed after confirmation');
            }
            $revision = ConfigRevision::where('resource_type', 'capability_profile')->where('resource_id', $profileId)->where('revision', (int) $profile->revision)->lock(true)->find();
            if ($revision === null || (string) $revision->state !== CapabilityProfilePolicy::DRAFT) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Capability profile is not publishable');
            }
            $routes = CapabilityRoute::where('profile_id', $profileId)->where('status', 1)->select()
                ->map(static fn (CapabilityRoute $route): array => [
                    'capability_code' => (string) $route->capability_code,
                    'route_kind' => (string) $route->route_kind,
                    'deployment_id' => $route->deployment_id === null ? null : (int) $route->deployment_id,
                    'driver_code' => $route->driver_code,
                    'priority' => (int) $route->priority,
                    'config' => $route->config,
                ])->all();
            $this->routeCompatibility->normalizeAndValidate($routes, (string) $profile->data_egress_policy, true, true);
            CapabilityProfile::where('environment_id', $environmentId)->where('state', CapabilityProfilePolicy::PUBLISHED)->update(['state' => 'archived', 'status' => 2]);
            $profile->save(['state' => CapabilityProfilePolicy::PUBLISHED, 'status' => 1]);
            $revision->save(['state' => CapabilityProfilePolicy::PUBLISHED, 'published_at' => date('Y-m-d H:i:s')]);
            $this->audit($environmentId, $actorRef, 'capability_profile.publish', $profileId, 'Published SandAI capability profile', ['revision' => (int) $profile->revision]);
            return $this->summary($profile, true);
        });
    }

    /** @return array<string, mixed> */
    private function summary(CapabilityProfile $profile, bool $withRoutes): array
    {
        $result = [
            'id' => (int) $profile->id,
            'environment_id' => (int) $profile->environment_id,
            'name' => (string) $profile->getAttr('name'),
            'template_code' => $profile->template_code,
            'data_egress_policy' => (string) $profile->data_egress_policy,
            'revision' => (int) $profile->revision,
            'state' => (string) $profile->state,
            'status' => (int) $profile->status,
        ];
        if ($withRoutes) {
            $result['routes'] = CapabilityRoute::where('profile_id', (int) $profile->id)->order('priority')->select()
                ->map(static fn (CapabilityRoute $route): array => self::routeSummary($route))->all();
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function routeSummary(CapabilityRoute $route): array
    {
        $result = [
            'id' => (int) $route->id,
            'capability_code' => (string) $route->capability_code,
            'route_kind' => (string) $route->route_kind,
            'deployment_id' => $route->deployment_id === null ? null : (int) $route->deployment_id,
            'driver_code' => $route->driver_code,
            'priority' => (int) $route->priority,
            'status' => (int) $route->status,
        ];
        // Only a confirmed empty configuration can be safely round-tripped by the editor.
        // Never expose legacy configuration contents or treat an unknown value as empty.
        if (($route->getData()['config'] ?? null) === []) {
            $result['config'] = new \stdClass();
        }
        return $result;
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $actorRef, string $action, int $profileId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'admin',
            'actor_ref' => $actorRef,
            'action' => $action,
            'resource_type' => 'capability_profile',
            'resource_id' => $profileId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
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
