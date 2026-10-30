<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

/** Keeps a provider's idempotency key bound to one canonical import body. */
final class BillingImportIdempotencyPolicy
{
    public function isReplay(string $existingFingerprint, string $incomingFingerprint): bool
    {
        if (hash_equals($existingFingerprint, $incomingFingerprint)) {
            return true;
        }

        throw new ApiProblem('SAND_AI_BILLING_IDEMPOTENCY_CONFLICT', 'idempotency_key was already used with different billing content');
    }
}
