<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

/** Public query DTO boundary for the fixed, non-paginated billing administration endpoints. */
final class BillingQueryPolicy
{
    /** @param array<string,mixed> $query */
    public function index(array $query): ?int
    {
        $this->allowOnly($query, ['provider_id'], 'billing import index');

        return array_key_exists('provider_id', $query)
            ? BillingPositiveId::parse($query['provider_id'], 'provider_id', 'SAND_AI_VALIDATION_ERROR')
            : null;
    }

    /** @param array<string,mixed> $query */
    public function read(array $query): int
    {
        $this->allowOnly($query, ['id'], 'billing import read');

        return BillingPositiveId::parse($query['id'] ?? null, 'id', 'SAND_AI_VALIDATION_ERROR');
    }

    /** @param array<string,mixed> $query @param list<string> $allowed */
    private function allowOnly(array $query, array $allowed, string $operation): void
    {
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$operation} query contains unsupported fields");
        }
    }
}
