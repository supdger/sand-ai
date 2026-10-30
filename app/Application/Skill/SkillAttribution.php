<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Application\Gateway\DispatchRouteSnapshot;

/** Trusted server-only attribution and immutable execution snapshot. */
final readonly class SkillAttribution
{
    /** @param array<string,mixed> $executionSnapshot */
    public function __construct(
        public int $environmentId,
        public int $skillId,
        public int $skillVersionId,
        public int $skillRunId,
        public int $deploymentId,
        public array $executionSnapshot,
        public ?string $dataClass = null,
        public ?SkillOutputContract $outputContract = null,
    ) {
        foreach (['environmentId' => $environmentId, 'skillId' => $skillId, 'skillVersionId' => $skillVersionId, 'skillRunId' => $skillRunId, 'deploymentId' => $deploymentId] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("{$name} must be a positive integer");
            }
        }
        try {
            $executionSnapshot = DispatchRouteSnapshot::assert($executionSnapshot, true);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Skill execution snapshot is invalid');
        }
        if ($executionSnapshot['capability_code'] !== 'chat' || $executionSnapshot['route_kind'] !== 'model_deployment' || $executionSnapshot['deployment_id'] !== $deploymentId) {
            throw new \InvalidArgumentException('Skill execution snapshot is inconsistent');
        }
        if (($executionSnapshot['skill_output_strategy'] ?? null) !== $outputContract?->strategy) {
            throw new \InvalidArgumentException('Skill output contract differs from its immutable strategy');
        }
        if ($dataClass !== null && preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1) {
            throw new \InvalidArgumentException('Skill data class is invalid');
        }
    }
}
