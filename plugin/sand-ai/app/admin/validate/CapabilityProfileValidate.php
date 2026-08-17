<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\validate;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\domain\capability\CapabilityProfilePolicy;

final class CapabilityProfileValidate
{
    /** @param array<string, mixed> $input @return array{environment_id:int,name:string,template_code:?string,data_egress_policy:string,routes:list<array<string,mixed>>} */
    public function save(array $input): array
    {
        $environmentId = (int) ($input['environment_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $templateCode = trim((string) ($input['template_code'] ?? '')) ?: null;
        $policy = trim((string) ($input['data_egress_policy'] ?? ''));
        $routes = $input['routes'] ?? [];
        if ($environmentId <= 0 || $name === '' || !is_array($routes)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id, name and routes are required');
        }
        if ($templateCode !== null) {
            $template = CapabilityProfilePolicy::templates()[$templateCode] ?? null;
            if ($template === null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Unknown capability template');
            }
            $policy = $policy ?: (string) $template['data_egress_policy'];
            $routes = $routes === [] ? $template['default_routes'] : $routes;
        }
        $normalizedRoutes = CapabilityProfilePolicy::normalizeRoutes($routes, $policy);
        if ($normalizedRoutes === []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A capability profile requires at least one route');
        }
        return [
            'environment_id' => $environmentId,
            'name' => $name,
            'template_code' => $templateCode,
            'data_egress_policy' => $policy,
            'routes' => $normalizedRoutes,
        ];
    }
}
