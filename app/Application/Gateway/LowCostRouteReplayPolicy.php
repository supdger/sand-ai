<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Resolves a retry from the immutable dispatch, never from the mutable model catalog. */
final class LowCostRouteReplayPolicy
{
    /** @param array<string,mixed> $route @return array{model_code:string,deployment_id:int} */
    public static function selected(array $route, int $invocationModelId, int $invocationDeploymentId, int $dispatchModelId, int $dispatchDeploymentId): array
    {
        DispatchRouteSnapshot::assert($route, false);
        if (($route['selection_kind'] ?? null) !== 'lowest_cost'
            || $invocationModelId < 1 || $invocationDeploymentId < 1
            || $invocationModelId !== $dispatchModelId || $invocationDeploymentId !== $dispatchDeploymentId
            || $invocationModelId !== $route['model_id'] || $invocationDeploymentId !== $route['deployment_id']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen low-cost route differs from the invocation');
        }
        return ['model_code' => $route['model_code'], 'deployment_id' => $route['deployment_id']];
    }
}
