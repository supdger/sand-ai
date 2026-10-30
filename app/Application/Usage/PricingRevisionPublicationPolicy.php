<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;

final class PricingRevisionPublicationPolicy
{
    /** @param list<string> $meters */
    public function assertPublishable(string $state, array $meters, ?string $capabilityCode = null, ?string $providerAdapter = null): void
    {
        if ($state !== 'draft') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only a draft price revision can be published');
        }
        $expected = (new PricingValuePolicy())->pricingMeters($capabilityCode ?? 'chat', $providerAdapter);
        sort($meters);
        $actual = $meters;
        sort($expected);
        if ($actual !== $expected) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A published price requires the exact capability rates');
        }
    }
}
