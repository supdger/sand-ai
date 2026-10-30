<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use DateTimeImmutable;
use DateTimeZone;

/** Canonical validation shared by price administration and persisted call-start evidence. */
final class PricingValuePolicy
{
    public const VALIDATION_ERROR = 'SAND_AI_VALIDATION_ERROR';
    public const SNAPSHOT_ERROR = 'SAND_AI_PRICING_SNAPSHOT_INVALID';

    /** @var list<string> */
    private const TOKEN_METERS = ['input_tokens', 'output_tokens'];
    /** @var list<string> */
    private const UNPRICED_REASONS = ['no_published_token_price'];
    private const MAX_PER_QUANTITY = 2147483647;

    public function currency(mixed $value, string $errorCode = self::VALIDATION_ERROR): string
    {
        if (!is_string($value) || preg_match('/\A[A-Z]{3}\z/D', $value) !== 1) {
            $this->fail($errorCode, 'currency must be exactly three uppercase ASCII letters');
        }

        return $value;
    }

    public function utcDateTime(mixed $value, string $field, string $errorCode = self::VALIDATION_ERROR): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
            $this->fail($errorCode, "{$field} must use the exact UTC Y-m-d H:i:s format");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->fail($errorCode, "{$field} is not a valid UTC calendar time");
        }

        return $value;
    }

    /** @return array{effective_from:string,effective_until:?string} */
    public function effectiveRange(mixed $from, mixed $until, string $errorCode = self::VALIDATION_ERROR): array
    {
        $effectiveFrom = $this->utcDateTime($from, 'effective_from', $errorCode);
        $effectiveUntil = $until === null ? null : $this->utcDateTime($until, 'effective_until', $errorCode);
        if ($effectiveUntil !== null && $effectiveUntil <= $effectiveFrom) {
            $this->fail($errorCode, 'effective_until must be later than effective_from');
        }

        return ['effective_from' => $effectiveFrom, 'effective_until' => $effectiveUntil];
    }

    public function decimal20Scale8(mixed $value, string $field, string $errorCode = self::VALIDATION_ERROR): string
    {
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?\z/D', $value, $matches) !== 1) {
            $this->fail($errorCode, "{$field} must be a non-negative decimal(20,8) string");
        }
        $fraction = str_pad($matches[2] ?? '', 8, '0');

        return $matches[1] . '.' . $fraction;
    }

    public function perQuantity(mixed $value, string $errorCode = self::VALIDATION_ERROR): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_PER_QUANTITY) {
            $this->fail($errorCode, 'per_quantity must be a positive signed 32-bit integer');
        }

        return $value;
    }

    /** @return list<array{meter_code:string,unit_price:string,per_quantity:int}> */
    public function draftRates(mixed $rates, ?string $capabilityCode = null, ?string $providerAdapter = null): array
    {
        if (!is_array($rates)) {
            $this->fail(self::VALIDATION_ERROR, 'rates is required');
        }
        // The optional capability parameter preserves the original DTO helper
        // for older callers while every administration write passes a code.
        $meters = $capabilityCode === null ? self::TOKEN_METERS : $this->pricingMeters($capabilityCode, $providerAdapter);
        if ($capabilityCode === 'rerank' && $providerAdapter === null) {
            // Draft DTO normalization precedes the provider row lock. Require
            // one real unit here; persistence rechecks it against that provider.
            if (!array_is_list($rates) || count($rates) !== 1 || !is_array($rates[0])
                || !in_array($rates[0]['meter_code'] ?? null, $meters, true)) {
                $this->fail(self::VALIDATION_ERROR, 'professional rerank requires exactly one provider pricing meter');
            }
            $meters = [$rates[0]['meter_code']];
        }
        $normalized = [];
        foreach ($rates as $rate) {
            if (!is_array($rate) || !$this->hasExactKeys($rate, ['meter_code', 'unit_price', 'per_quantity'])) {
                $this->fail(self::VALIDATION_ERROR, 'each rate must contain only meter_code, unit_price and per_quantity');
            }
            $meter = $rate['meter_code'];
            if (!is_string($meter) || !in_array($meter, $meters, true) || isset($normalized[$meter])) {
                $this->fail(self::VALIDATION_ERROR, 'rate meters are not valid for the capability');
            }
            $normalized[$meter] = [
                'meter_code' => $meter,
                'unit_price' => $this->decimal20Scale8($rate['unit_price'], 'unit_price'),
                'per_quantity' => $this->perQuantity($rate['per_quantity']),
            ];
        }
        if (!$this->hasExactKeys($normalized, $meters)) {
            $this->fail(self::VALIDATION_ERROR, 'the exact capability pricing meters are required');
        }

        return array_map(static fn (string $meter): array => $normalized[$meter], $meters);
    }

    /** @return list<string> */
    public function pricingMeters(string $capabilityCode, ?string $providerAdapter = null): array
    {
        try {
            // A few hermetic policy tests load this value object without Composer.
            // Keep their code-owned known-capability projection fail-closed; normal
            // application execution always resolves the authoritative registry.
            $meters = class_exists(CapabilityDriverRegistry::class)
                ? (CapabilityDriverRegistry::capability($capabilityCode)['pricing_meters'] ?? null)
                : match ($capabilityCode) {
                    'chat', 'structured_extraction', 'vision', 'embedding' => self::TOKEN_METERS,
                    'rerank' => ['rerank_search_units', 'rerank_tokens'],
                    'audio_transcription' => ['audio_seconds'],
                    'audio_speech' => ['input_characters'],
                    'image_ocr' => ['external_request'],
                    default => null,
                };
        } catch (ApiProblem) {
            $meters = null;
        }
        if ($capabilityCode === 'rerank' && $providerAdapter !== null) {
            $meters = match ($providerAdapter) {
                'cohere_rerank' => ['rerank_search_units'],
                'jina_rerank' => ['rerank_tokens'],
                default => null,
            };
        }
        if (!is_array($meters) || !array_is_list($meters) || $meters === []
            || count(array_unique($meters)) !== count($meters)
            || array_filter($meters, static fn (mixed $meter): bool => !is_string($meter) || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $meter) !== 1) !== []) {
            $this->fail(self::VALIDATION_ERROR, 'capability pricing meters are not registered');
        }

        return $meters;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{pricing_state:string,price_revision_id:?int,reason:string,currency:?string,effective_from:?string,effective_until:?string,rates:?array<string,array{unit_price:string,per_quantity:int}>}
     */
    public function persistedSnapshot(string $pricingState, mixed $priceRevisionId, array $snapshot): array
    {
        if ($pricingState === 'unpriced') {
            if (!$this->hasExactKeys($snapshot, ['snapshot_version', 'reason'])
                || ($snapshot['snapshot_version'] ?? null) !== 1
                || $priceRevisionId !== null
                || !is_string($snapshot['reason'] ?? null)
                || !in_array($snapshot['reason'], self::UNPRICED_REASONS, true)) {
                $this->fail(self::SNAPSHOT_ERROR, 'The persisted unpriced call-start snapshot is invalid');
            }

            return [
                'pricing_state' => 'unpriced', 'price_revision_id' => null, 'reason' => $snapshot['reason'],
                'currency' => null, 'effective_from' => null, 'effective_until' => null, 'rates' => null,
            ];
        }
        if ($pricingState !== 'priced'
            || !$this->hasExactKeys($snapshot, ['snapshot_version', 'price_revision_id', 'currency', 'effective_from', 'effective_until', 'rates'])
            || ($snapshot['snapshot_version'] ?? null) !== 1) {
            $this->fail(self::SNAPSHOT_ERROR, 'The persisted priced call-start snapshot fields are invalid');
        }
        $revisionId = $this->positiveId($priceRevisionId, 'price_revision_id', self::SNAPSHOT_ERROR);
        if (($snapshot['price_revision_id'] ?? null) !== $revisionId || !is_array($snapshot['rates'] ?? null)
            || !$this->hasExactKeys($snapshot['rates'], self::TOKEN_METERS)) {
            $this->fail(self::SNAPSHOT_ERROR, 'The persisted priced call-start snapshot identity is invalid');
        }
        $range = $this->effectiveRange($snapshot['effective_from'] ?? null, $snapshot['effective_until'] ?? null, self::SNAPSHOT_ERROR);
        $rates = [];
        foreach (self::TOKEN_METERS as $meter) {
            $rate = $snapshot['rates'][$meter] ?? null;
            if (!is_array($rate) || !$this->hasExactKeys($rate, ['unit_price', 'per_quantity'])) {
                $this->fail(self::SNAPSHOT_ERROR, 'The persisted call-start rate fields are invalid');
            }
            $unitPrice = $this->decimal20Scale8($rate['unit_price'], 'unit_price', self::SNAPSHOT_ERROR);
            if ($unitPrice !== $rate['unit_price']) {
                $this->fail(self::SNAPSHOT_ERROR, 'The persisted call-start unit price is not canonical decimal(20,8)');
            }
            $rates[$meter] = [
                'unit_price' => $unitPrice,
                'per_quantity' => $this->perQuantity($rate['per_quantity'], self::SNAPSHOT_ERROR),
            ];
        }

        return [
            'pricing_state' => 'priced', 'price_revision_id' => $revisionId, 'reason' => '',
            'currency' => $this->currency($snapshot['currency'] ?? null, self::SNAPSHOT_ERROR),
            'effective_from' => $range['effective_from'], 'effective_until' => $range['effective_until'], 'rates' => $rates,
        ];
    }

    public function positiveId(mixed $value, string $field, string $errorCode = self::VALIDATION_ERROR): int
    {
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }
            $this->fail($errorCode, "{$field} must be a positive integer");
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            $this->fail($errorCode, "{$field} must be a positive integer");
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            $this->fail($errorCode, "{$field} must be a positive integer");
        }

        return (int) $value;
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function fail(string $errorCode, string $message): never
    {
        throw new ApiProblem($errorCode, $message);
    }
}
