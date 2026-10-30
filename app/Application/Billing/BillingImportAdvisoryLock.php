<?php

declare(strict_types=1);

namespace app\Application\Billing;

/** Stable, non-secret PostgreSQL advisory key for one Provider/idempotency claim. */
final class BillingImportAdvisoryLock
{
    public const NAMESPACE = 'sand_ai.billing_import.idempotency.v1';
    public const HASH_SEED = 13020260826;

    public static function sql(): string
    {
        return 'SELECT pg_advisory_xact_lock(hashtextextended(?, ?))';
    }

    public static function canonical(int $providerId, string $idempotencyKey): string
    {
        return self::NAMESPACE
            . '|provider_id=' . (string) $providerId
            . '|idempotency_key_bytes=' . strlen($idempotencyKey)
            . '|idempotency_key=' . $idempotencyKey;
    }

    /** @return array{string,int} */
    public static function parameters(int $providerId, string $idempotencyKey): array
    {
        return [self::canonical($providerId, $idempotencyKey), self::HASH_SEED];
    }
}
