<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Distinguishes a missing target from drift inside an identified target chain. */
final class DeploymentPublicationChainPolicy
{
    /**
     * @param array{reference:?array<string,mixed>,provider:?array<string,mixed>,model:?array<string,mixed>,deployment:?array<string,mixed>,revision:?array<string,mixed>} $chain
     */
    public function assertConsistent(array $chain, int $deploymentId): void
    {
        $reference = $chain['reference'];
        $deployment = $chain['deployment'];
        if ($reference === null || $deployment === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Tested deployment not found');
        }

        $provider = $chain['provider'];
        $model = $chain['model'];
        $revision = $chain['revision'];
        if ($provider === null || $model === null || $revision === null
            || (int) ($reference['id'] ?? 0) !== $deploymentId
            || (int) ($deployment['id'] ?? 0) !== $deploymentId
            || (int) ($provider['id'] ?? 0) !== (int) ($reference['provider_id'] ?? 0)
            || (int) ($model['id'] ?? 0) !== (int) ($reference['model_id'] ?? 0)
            || (int) ($deployment['provider_id'] ?? 0) !== (int) ($reference['provider_id'] ?? 0)
            || (int) ($deployment['model_id'] ?? 0) !== (int) ($reference['model_id'] ?? 0)
            || (int) ($deployment['revision'] ?? 0) !== (int) ($reference['revision'] ?? 0)
            || (string) ($revision['resource_type'] ?? '') !== 'model_deployment'
            || (int) ($revision['resource_id'] ?? 0) !== $deploymentId
            || (int) ($revision['revision'] ?? 0) !== (int) ($reference['revision'] ?? 0)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested provider, model, deployment, or revision association has changed');
        }
    }
}
