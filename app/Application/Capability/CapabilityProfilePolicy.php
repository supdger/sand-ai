<?php

declare(strict_types=1);

namespace app\Application\Capability;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;

final class CapabilityProfilePolicy
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    /** @var list<string> */
    private const ROUTE_FIELDS = ['capability_code', 'route_kind', 'deployment_id', 'driver_code', 'priority', 'config'];

    /** @return array<string, array<string, mixed>> */
    public static function templates(): array
    {
        return [
            'local_development' => [
                'name' => '本地开发',
                'data_egress_policy' => 'local_only',
                'default_routes' => [
                    ['capability_code' => 'document_parse', 'route_kind' => 'parse_driver', 'driver_code' => 'native_document_parse', 'priority' => 100],
                    ['capability_code' => 'image_ocr', 'route_kind' => 'parse_driver', 'driver_code' => 'local_paddle_ocr', 'priority' => 100],
                ],
            ],
            'private_data' => [
                'name' => '私有数据优先',
                'data_egress_policy' => 'private_only',
                'default_routes' => [
                    ['capability_code' => 'document_parse', 'route_kind' => 'parse_driver', 'driver_code' => 'native_document_parse', 'priority' => 100],
                    ['capability_code' => 'image_ocr', 'route_kind' => 'parse_driver', 'driver_code' => 'local_paddle_ocr', 'priority' => 100],
                ],
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
        self::assertRouteRequestShape($routes);
        $seen = [];
        $normalized = [];
        foreach ($routes as $route) {
            $capabilityCode = trim((string) ($route['capability_code'] ?? ''));
            $routeKind = trim((string) ($route['route_kind'] ?? ''));
            $priority = $route['priority'];
            $metadata = CapabilityDriverRegistry::capability($capabilityCode);
            if ($routeKind !== (string) $metadata['route_kind'] || $priority <= 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route kind or priority is invalid');
            }
            if (!in_array($routeKind, ['model_deployment', 'parse_driver', 'audio_driver'], true)) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The capability has no executable route registered');
            }
            $key = $capabilityCode . ':' . $priority;
            if (isset($seen[$key])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Capability priorities must be unique within a profile');
            }
            $seen[$key] = true;
            $deploymentId = in_array($routeKind, ['model_deployment', 'audio_driver'], true) ? (int) ($route['deployment_id'] ?? 0)
                : ($capabilityCode === 'image_ocr' && ($route['driver_code'] ?? null) === 'local_paddle_ocr' ? ($route['deployment_id'] ?? null) : null);
            $driverCode = in_array($routeKind, ['parse_driver', 'audio_driver'], true) ? trim((string) ($route['driver_code'] ?? '')) : null;
            if (in_array($routeKind, ['model_deployment', 'audio_driver'], true) && $deploymentId <= 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A deployed capability route requires deployment_id');
            }
            if (in_array($routeKind, ['parse_driver', 'audio_driver'], true)) {
                $driver = CapabilityDriverRegistry::parseDriver($capabilityCode, (string) $driverCode);
                if (self::requiresLocalProcessing($dataEgressPolicy) && (string) $driver['data_egress'] !== 'local_only') {
                    throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The profile only permits local processing');
                }
            }
            $normalized[] = [
                'capability_code' => $capabilityCode,
                'route_kind' => $routeKind,
                'deployment_id' => $deploymentId,
                'driver_code' => $driverCode,
                'priority' => $priority,
                'status' => 1,
                'config' => [],
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $routes */
    public static function assertRouteRequestShape(array $routes): void
    {
        foreach ($routes as $route) {
            if (!is_array($route) || array_is_list($route)
                || array_diff(array_keys($route), self::ROUTE_FIELDS) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route contains unsupported fields');
            }
            if (!is_string($route['capability_code'] ?? null) || trim($route['capability_code']) === ''
                || !is_string($route['route_kind'] ?? null) || trim($route['route_kind']) === ''
                || !is_int($route['priority'] ?? null) || $route['priority'] <= 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route requires capability_code, route_kind and a positive integer priority');
            }
            if (array_key_exists('deployment_id', $route)
                && $route['deployment_id'] !== null
                && (!is_int($route['deployment_id']) || $route['deployment_id'] <= 0)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route deployment_id must be a positive integer or null');
            }
            if (array_key_exists('driver_code', $route)
                && $route['driver_code'] !== null
                && !is_string($route['driver_code'])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route driver_code must be a string or null');
            }
            if (array_key_exists('config', $route)
                && (!is_array($route['config']) || $route['config'] !== [])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability route config must be an empty object');
            }
        }
    }

    public static function requiresLocalProcessing(string $dataEgressPolicy): bool
    {
        return in_array($dataEgressPolicy, ['local_only', 'private_only'], true);
    }

    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    public static function canonicalConfiguration(string $name, ?string $templateCode, string $dataEgressPolicy, array $routes): array
    {
        $canonicalRoutes = array_map(static fn (array $route): array => self::canonical($route), $routes);
        usort($canonicalRoutes, static fn (array $left, array $right): int => [
            (string) ($left['capability_code'] ?? ''), (int) ($left['priority'] ?? 0), (string) ($left['route_kind'] ?? ''),
        ] <=> [
            (string) ($right['capability_code'] ?? ''), (int) ($right['priority'] ?? 0), (string) ($right['route_kind'] ?? ''),
        ]);
        return self::canonical([
            'name' => $name,
            'template_code' => $templateCode,
            'data_egress_policy' => $dataEgressPolicy,
            'routes' => $canonicalRoutes,
        ]);
    }

    /** @param array<string,mixed> $value */
    public static function canonicalDigest(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }
}
