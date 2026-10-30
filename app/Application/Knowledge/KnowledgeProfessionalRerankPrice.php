<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Usage\PriceRevisionSelector;
use app\Application\Usage\PricingValuePolicy;
use app\Application\Usage\TokenPriceCalculator;
use app\Infrastructure\Persistence\Model\PriceRate;
use app\Infrastructure\Persistence\Model\PriceRevision;

/** One exact professional meter selected before a provider request starts. */
final readonly class KnowledgeProfessionalRerankPrice
{
    private const VERSION = 1;

    /** @param array{unit_price:string,per_quantity:int}|null $rate */
    private function __construct(
        public string $calledAt,
        public string $meteringUnit,
        public ?int $revisionId,
        public ?string $currency,
        public ?string $effectiveFrom,
        public ?string $effectiveUntil,
        public ?array $rate,
    ) {
        $values = new PricingValuePolicy();
        $values->utcDateTime($calledAt, 'called_at', PricingValuePolicy::SNAPSHOT_ERROR);
        self::assertUnit($meteringUnit);
        if ($revisionId === null) {
            if ($currency !== null || $rate !== null || $effectiveFrom !== null || $effectiveUntil !== null) {
                throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Unpriced rerank snapshot contains a price');
            }
            return;
        }
        if ($revisionId < 1 || $rate === null || !self::hasKeys($rate, ['unit_price', 'per_quantity'])) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Professional rerank price is incomplete');
        }
        $values->currency($currency, PricingValuePolicy::SNAPSHOT_ERROR);
        $values->effectiveRange($effectiveFrom, $effectiveUntil, PricingValuePolicy::SNAPSHOT_ERROR);
        if ($values->decimal20Scale8($rate['unit_price'], 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR) !== $rate['unit_price']
            || $values->perQuantity($rate['per_quantity'], PricingValuePolicy::SNAPSHOT_ERROR) !== $rate['per_quantity']
            || $effectiveFrom > $calledAt || ($effectiveUntil !== null && $effectiveUntil <= $calledAt)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Professional rerank price is not effective');
        }
    }

    public static function unitForDriver(string $driver): string
    {
        return match ($driver) {
            'cohere_rerank' => 'rerank_search_units',
            'jina_rerank' => 'rerank_tokens',
            default => throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Professional rerank driver is unsupported'),
        };
    }

    public static function assertUnit(string $unit): void
    {
        if (!in_array($unit, ['rerank_search_units', 'rerank_tokens'], true)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Professional rerank meter is unsupported');
        }
    }

    public static function resolve(int $providerId, int $modelId, string $unit, string $calledAt): self
    {
        self::assertUnit($unit);
        $revisions = PriceRevision::where('provider_id', $providerId)->where('model_id', $modelId)
            ->where('capability_code', 'rerank')->where('state', 'published')->select()->toArray();
        $revision = (new PriceRevisionSelector())->select($revisions, $calledAt);
        if ($revision === null) return new self($calledAt, $unit, null, null, null, null, null);
        $rates = PriceRate::where('price_revision_id', (int) $revision['id'])->select()->toArray();
        if (count($rates) !== 1 || ($rates[0]['meter_code'] ?? null) !== $unit) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Published rerank price has a different meter');
        }
        return new self($calledAt, $unit, (int) $revision['id'], (string) $revision['currency'],
            (string) $revision['effective_from'], $revision['effective_until'] === null ? null : (string) $revision['effective_until'],
            ['unit_price' => (new PricingValuePolicy())->decimal20Scale8((string) $rates[0]['unit_price'], 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR),
                'per_quantity' => (int) $rates[0]['per_quantity']]);
    }

    public static function fromPersistence(mixed $calledAt, mixed $state, mixed $revisionId, mixed $snapshot): self
    {
        $snapshot = is_object($snapshot) ? (array) $snapshot : $snapshot;
        if (is_string($calledAt) && $state === 'unpriced' && $revisionId === null && is_array($snapshot)
            && self::hasKeys($snapshot, ['snapshot_version', 'metering_unit', 'reason'])
            && $snapshot['snapshot_version'] === self::VERSION && $snapshot['reason'] === 'no_published_rerank_price'
            && is_string($snapshot['metering_unit'])) {
            return new self($calledAt, $snapshot['metering_unit'], null, null, null, null, null);
        }
        if (!is_string($calledAt) || !is_array($snapshot)
            || !self::hasKeys($snapshot, ['snapshot_version', 'metering_unit', 'price_revision_id', 'currency', 'effective_from', 'effective_until', 'rates'])
            || ($snapshot['snapshot_version'] ?? null) !== self::VERSION
            || !is_string($snapshot['metering_unit'] ?? null)
            || (($revisionId === null ? 'unpriced' : 'priced') !== $state)
            || ($revisionId !== null && (!is_int($revisionId) && !is_string($revisionId)))
            || $snapshot['price_revision_id'] !== ($revisionId === null ? null : (int) $revisionId)
            || ($snapshot['currency'] !== null && !is_string($snapshot['currency']))
            || ($snapshot['effective_from'] !== null && !is_string($snapshot['effective_from']))
            || ($snapshot['effective_until'] !== null && !is_string($snapshot['effective_until']))
            || !is_array($snapshot['rates'] ?? null)
            || !self::hasKeys($snapshot['rates'], [$snapshot['metering_unit']])) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Persisted professional rerank price is invalid');
        }
        return new self($calledAt, $snapshot['metering_unit'], $revisionId === null ? null : (int) $revisionId,
            $snapshot['currency'], $snapshot['effective_from'], $snapshot['effective_until'], $snapshot['rates'][$snapshot['metering_unit']]);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        if ($this->revisionId === null) {
            return ['snapshot_version' => self::VERSION, 'metering_unit' => $this->meteringUnit,
                'reason' => 'no_published_rerank_price'];
        }
        return ['snapshot_version' => self::VERSION, 'metering_unit' => $this->meteringUnit,
            'price_revision_id' => $this->revisionId, 'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom, 'effective_until' => $this->effectiveUntil, 'rates' => [$this->meteringUnit => $this->rate]];
    }

    /** @return array<string,mixed> */
    public function persistence(): array
    {
        return ['call_started_at' => $this->calledAt, 'call_start_pricing_state' => $this->revisionId === null ? 'unpriced' : 'priced',
            'call_start_price_revision_id' => $this->revisionId, 'call_start_price_snapshot' => $this->snapshot()];
    }

    /** @return array<string,mixed> */
    public function dispatchSnapshot(): array
    {
        return ['called_at' => $this->calledAt, 'pricing_state' => $this->revisionId === null ? 'unpriced' : 'priced',
            'price_revision_id' => $this->revisionId, 'price_snapshot' => $this->snapshot()];
    }

    public function amount(?int $quantity): ?string
    {
        if ($quantity === null || $this->rate === null) return null;
        if ($quantity < 0) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank quantity is invalid');
        // Reuse the established exact decimal integer-quantity arithmetic;
        // the professional meter is persisted separately and is never a token estimate.
        return TokenPriceCalculator::calculate($quantity, 0, $this->rate['unit_price'],
            $this->rate['per_quantity'], '0', 1)['amount'];
    }

    public function reservationAmount(?int $quantity): ?string
    {
        if ($quantity === null || $this->rate === null) return null;
        if ($quantity < 0) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank quantity is invalid');
        return TokenPriceCalculator::calculateReservation($quantity, 0, $this->rate['unit_price'],
            $this->rate['per_quantity'], '0', 1)['amount'];
    }

    private static function hasKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
