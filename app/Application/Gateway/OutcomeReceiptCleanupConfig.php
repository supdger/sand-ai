<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Strict, fail-closed configuration for an otherwise disabled cleanup worker. */
final readonly class OutcomeReceiptCleanupConfig
{
    private function __construct(
        public bool $enabled,
        public int $intervalSeconds,
        public int $batchSize,
        public int $graceSeconds,
        public int $leaseSeconds,
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        $enabled = $config['cleanup_enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt cleanup enabled flag is invalid');
        }
        return new self(
            $enabled,
            self::positive($config['cleanup_interval_seconds'] ?? null, 60, 86400, 'interval'),
            self::positive($config['cleanup_batch_size'] ?? null, 1, 200, 'batch'),
            self::positive($config['cleanup_grace_seconds'] ?? null, 60, 604800, 'grace'),
            self::positive($config['cleanup_lease_seconds'] ?? null, 60, 3600, 'lease'),
        );
    }

    private static function positive(mixed $value, int $minimum, int $maximum, string $name): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', "Outcome receipt cleanup {$name} is invalid");
        }
        return $value;
    }
}
