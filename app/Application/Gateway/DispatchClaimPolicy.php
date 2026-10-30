<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Pure guards used by the production coordinator before any terminal mutation. */
final class DispatchClaimPolicy
{
    /** @param array<string,mixed> $ledger */
    public static function currentLeaseOwnsPrepared(array $ledger, InternalModelInvocationContext $context): bool
    {
        return ($ledger['state'] ?? null) === 'prepared'
            && (string) ($ledger['lease_owner'] ?? '') === $context->lease->owner
            && (string) ($ledger['lease_token'] ?? '') === $context->lease->token
            && (int) ($ledger['lease_fence'] ?? 0) === $context->lease->fence;
    }

    /** @param array<string,mixed> $reservation */
    public static function reservationDispatchable(array $reservation, int $invocationId, string $now): bool
    {
        return (int) ($reservation['invocation_id'] ?? 0) === $invocationId
            && ($reservation['state'] ?? null) === 'reserved'
            && is_string($reservation['expires_at'] ?? null)
            && $reservation['expires_at'] > $now;
    }
}
