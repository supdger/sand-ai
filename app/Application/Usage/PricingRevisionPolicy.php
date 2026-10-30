<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
/** Validates the write DTO used by the price-directory administration API. */
final class PricingRevisionPolicy
{
    public function __construct(private readonly PricingValuePolicy $values = new PricingValuePolicy()) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalizeDraft(array $input): array
    {
        $expectedKeys = [
            'provider_id', 'model_id', 'capability_code', 'name', 'currency', 'effective_from', 'rates',
        ];
        if (array_key_exists('effective_until', $input)) {
            $expectedKeys[] = 'effective_until';
        }
        if (array_key_exists('id', $input)) {
            $expectedKeys[] = 'id';
        }
        if (!$this->hasExactKeys($input, $expectedKeys)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Price draft contains unknown or missing fields');
        }
        $providerId = $this->positiveId($input['provider_id'] ?? null, 'provider_id');
        $modelId = $this->positiveId($input['model_id'] ?? null, 'model_id');
        $capabilityCode = trim((string) ($input['capability_code'] ?? ''));
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $currency = $this->values->currency($input['currency'] ?? null);
        $range = $this->values->effectiveRange($input['effective_from'] ?? null, $input['effective_until'] ?? null);

        if ($capabilityCode === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $capabilityCode) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'capability_code is invalid');
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > 128) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'name is required and must not exceed 128 characters');
        }
        return [
            'id' => array_key_exists('id', $input) ? $this->positiveId($input['id'], 'id') : null,
            'provider_id' => $providerId,
            'model_id' => $modelId,
            'capability_code' => $capabilityCode,
            'name' => $name,
            'currency' => $currency,
            'effective_from' => $range['effective_from'],
            'effective_until' => $range['effective_until'],
            'rates' => $this->values->draftRates($input['rates'] ?? null, $capabilityCode),
        ];
    }

    private function positiveId(mixed $value, string $field): int
    {
        return $this->values->positiveId($value, $field);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
