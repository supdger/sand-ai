<?php

declare(strict_types=1);

namespace app\Application\Usage;

use InvalidArgumentException;

/** Trusted completed-audio attribution; this is metering, not an ASR dispatch request. */
final readonly class AudioUsageRequest
{
    public function __construct(
        public int $taskRunId,
        public UsageAttribution $attribution,
        public int $providerId,
        public int $modelId,
        public int $deploymentId,
        public AudioExecutionTarget $target,
        public AudioSeconds $audioSeconds,
        public ?string $executionId = null,
        public ?int $externalDispatchId = null,
    ) {
        if (($externalDispatchId !== null && ($externalDispatchId < 1 || $executionId !== null))
            || ($executionId !== null && ($executionId === '' || strlen($executionId) > 191))) {
            throw new InvalidArgumentException('Audio execution owner is invalid');
        }
        foreach ([$taskRunId, $providerId, $modelId, $deploymentId] as $id) {
            if ($id < 1) { throw new InvalidArgumentException('Audio Usage identifiers must be positive'); }
        }
        if ($target->provider['id'] !== $providerId || $target->model['id'] !== $modelId || $target->deployment['id'] !== $deploymentId) {
            throw new InvalidArgumentException('Audio Usage target identifiers must match the frozen snapshots');
        }
    }
}
