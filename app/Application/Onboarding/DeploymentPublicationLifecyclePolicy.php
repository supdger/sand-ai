<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfilePolicy;

/** Selects the only snapshot valid for the locked publication lifecycle. */
final class DeploymentPublicationLifecyclePolicy
{
    /**
     * @param array<string,mixed> $payload
     * @return array{snapshot:array<string,mixed>,digest:string,published:bool}
     */
    public function select(array $payload, string $revisionState, int $revisionStatus, int $deploymentStatus): array
    {
        if ($revisionStatus !== 1) {
            throw $this->drifted();
        }
        if ($revisionState === 'tested' && $deploymentStatus === 2) {
            return [
                'snapshot' => is_array($payload['execution_snapshot'] ?? null) ? $payload['execution_snapshot'] : [],
                'digest' => is_string($payload['execution_snapshot_digest'] ?? null) ? $payload['execution_snapshot_digest'] : '',
                'published' => false,
            ];
        }
        if ($revisionState === CapabilityProfilePolicy::PUBLISHED && $deploymentStatus === 1) {
            return [
                'snapshot' => is_array($payload['published_execution_snapshot'] ?? null) ? $payload['published_execution_snapshot'] : [],
                'digest' => is_string($payload['published_execution_snapshot_digest'] ?? null) ? $payload['published_execution_snapshot_digest'] : '',
                'published' => true,
            ];
        }

        throw $this->drifted();
    }

    private function drifted(): ApiProblem
    {
        return new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested provider, model, deployment, or revision configuration has changed');
    }
}
