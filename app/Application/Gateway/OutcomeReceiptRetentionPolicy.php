<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final readonly class OutcomeReceiptRetentionPolicy
{
    public function __construct(public int $artifactRetentionSeconds)
    {
        if ($artifactRetentionSeconds < 3600 || $artifactRetentionSeconds > 2592000) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Artifact receipt retention is not configured');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        $seconds = $config['artifact_retain_seconds'] ?? null;
        if (!is_int($seconds)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Artifact receipt retention is not configured');
        }
        return new self($seconds);
    }

    public function retainUntil(int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now + $this->artifactRetentionSeconds);
    }
}
