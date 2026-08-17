<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\capability;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\infrastructure\capability\CapabilityDriverRegistry;

final class CapabilityProfilePolicy
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    /** @return array<string, array<string, mixed>> */
    public static function templates(): array
    {
        return [
            'local_development' => [
                'name' => '本地开发',
                'data_egress_policy' => 'local_only',
                'default_routes' => [['capability_code' => 'document_parse', 'route_kind' => 'parse_driver', 'driver_code' => 'native_document_parse', 'priority' => 100]],
            ],
            'private_data' => [
                'name' => '私有数据优先',
                'data_egress_policy' => 'private_only',
                'default_routes' => [['capability_code' => 'document_parse', 'route_kind' => 'parse_driver', 'driver_code' => 'native_document_parse', 'priority' => 100]],
            ],
            'domestic_cloud' => [
                'name' => '国内云文档',
                'data_egress_policy' => 'approved_cloud',
                'default_routes' => [],
            ],
            'hybrid_best' => [
                'name' => '混合最优',
                'data_egress_policy' => 'approved_cloud',
                'default_routes' => [['capability_code' => 'document_parse', 'route_kind' => 'parse_driver', 'driver_code' => 'native_document_parse', 'priority' => 100]],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function normalizeRoutes(array $routes, string $dataEgressPolicy): array
    {
        if (!in_array($dataEgressPolicy, ['local_only', 'private_only', 'approved_cloud'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Unsupported data egress policy');
        }
        $seen = [];
        $normalized = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Each capability route must be an object');
            }
            $capabilityCode = trim((string) ($route['capability_code'] ?? ''));
            $routeKind = trim((string) ($route['route_kind'] ?? ''));
            $priority = (int) ($route['priority'] ?? 100);
            $metadata = CapabilityDriverRegistry::capability($capabilityCode);
            if ($routeKind !== (string) $metadata['route_kind'] || $priority <= 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route kind or priority is invalid');
            }
            $key = $capabilityCode . ':' . $priority;
            if (isset($seen[$key])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Capability priorities must be unique within a profile');
            }
            $seen[$key] = true;
            $deploymentId = $routeKind === 'model_deployment' ? (int) ($route['deployment_id'] ?? 0) : null;
            $driverCode = $routeKind === 'parse_driver' ? trim((string) ($route['driver_code'] ?? '')) : null;
            if ($routeKind === 'model_deployment' && $deploymentId <= 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A model deployment route requires deployment_id');
            }
            if ($routeKind === 'parse_driver') {
                $driver = CapabilityDriverRegistry::parseDriver($capabilityCode, (string) $driverCode);
                if (self::requiresLocalProcessing($dataEgressPolicy) && (string) $driver['data_egress'] !== 'local_only') {
                    throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The profile only permits local processing');
                }
            }
            $config = $route['config'] ?? [];
            $normalized[] = [
                'capability_code' => $capabilityCode,
                'route_kind' => $routeKind,
                'deployment_id' => $deploymentId,
                'driver_code' => $driverCode,
                'priority' => $priority,
                'status' => (int) ($route['status'] ?? 1),
                'config' => is_array($config) ? $config : [],
            ];
        }

        return $normalized;
    }

    public static function requiresLocalProcessing(string $dataEgressPolicy): bool
    {
        return in_array($dataEgressPolicy, ['local_only', 'private_only'], true);
    }
}
