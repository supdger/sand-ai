<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Usage\PostgresAudioPriceRevisionStore;
use app\Application\Usage\PriceRevisionSelector;
use app\Application\Usage\PricingValuePolicy;
use app\Application\Usage\TokenPriceCalculator;

/** Price decision frozen before the provider call, including the absence of a published price. */
final readonly class SpeechCallStartPrice
{
    private function __construct(
        public string $calledAt,
        public ?int $revisionId,
        public ?string $currency,
        public ?string $unitPrice,
        public ?int $perQuantity,
    ) {
    }

    public static function resolve(int $providerId, int $modelId, string $calledAt): self
    {
        (new PricingValuePolicy())->utcDateTime($calledAt, 'called_at', PricingValuePolicy::SNAPSHOT_ERROR);
        $store = new PostgresAudioPriceRevisionStore();
        $revision = (new PriceRevisionSelector())->select(
            $store->published($providerId, $modelId, 'audio_speech'), $calledAt,
        );
        if ($revision === null) {
            return new self($calledAt, null, null, null, null);
        }
        if (($revision['state'] ?? null) !== 'published' || !is_int($revision['id'] ?? null)
            || !is_string($revision['currency'] ?? null)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Speech price revision is invalid');
        }
        $rates = $store->rates($revision['id']);
        if (count($rates) !== 1 || ($rates[0]['meter_code'] ?? null) !== 'input_characters'
            || !is_string($rates[0]['unit_price'] ?? null) || !is_int($rates[0]['per_quantity'] ?? null)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Speech character price is invalid');
        }
        $values = new PricingValuePolicy();
        $values->currency($revision['currency'], PricingValuePolicy::SNAPSHOT_ERROR);
        $values->decimal20Scale8($rates[0]['unit_price'], 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR);
        $values->perQuantity($rates[0]['per_quantity'], PricingValuePolicy::SNAPSHOT_ERROR);
        return new self($calledAt, $revision['id'], $revision['currency'], $rates[0]['unit_price'], $rates[0]['per_quantity']);
    }

    /** @param array<string,mixed> $run */
    public static function fromRun(array $run): self
    {
        $snapshot = is_array($run['price_snapshot'] ?? null)
            ? $run['price_snapshot']
            : json_decode((string) ($run['price_snapshot'] ?? ''), true);
        $calledAt = $run['called_at'] ?? null;
        if (!is_string($calledAt) || !is_array($snapshot)
            || ($snapshot['snapshot_version'] ?? null) !== 1) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Stored speech price is invalid');
        }
        (new PricingValuePolicy())->utcDateTime($calledAt, 'called_at', PricingValuePolicy::SNAPSHOT_ERROR);
        if (($run['pricing_state'] ?? null) === 'unpriced'
            && ($run['price_revision_id'] ?? null) === null
            && $snapshot === ['snapshot_version' => 1, 'reason' => 'no_published_input_characters_price']) {
            return new self($calledAt, null, null, null, null);
        }
        $rate = $snapshot['rates']['input_characters'] ?? null;
        if (($run['pricing_state'] ?? null) !== 'priced'
            || !is_array($rate) || !is_int($snapshot['price_revision_id'] ?? null)
            || (int) ($run['price_revision_id'] ?? 0) !== $snapshot['price_revision_id']
            || !is_string($snapshot['currency'] ?? null)
            || !is_string($rate['unit_price'] ?? null)
            || !is_int($rate['per_quantity'] ?? null)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Stored speech price is invalid');
        }
        $values = new PricingValuePolicy();
        $values->currency($snapshot['currency'], PricingValuePolicy::SNAPSHOT_ERROR);
        $values->decimal20Scale8($rate['unit_price'], 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR);
        $values->perQuantity($rate['per_quantity'], PricingValuePolicy::SNAPSHOT_ERROR);
        return new self($calledAt, $snapshot['price_revision_id'], $snapshot['currency'], $rate['unit_price'], $rate['per_quantity']);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return $this->revisionId === null
            ? ['snapshot_version' => 1, 'reason' => 'no_published_input_characters_price']
            : ['snapshot_version' => 1, 'price_revision_id' => $this->revisionId,
                'currency' => $this->currency, 'rates' => ['input_characters' => [
                    'unit_price' => $this->unitPrice, 'per_quantity' => $this->perQuantity,
                ]]];
    }

    public function amount(int $characters): ?string
    {
        if ($this->revisionId === null) {
            return null;
        }
        $unitPrice = $this->unitPrice;
        $perQuantity = $this->perQuantity;
        if ($unitPrice === null || $perQuantity === null) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Speech character price is incomplete');
        }
        return TokenPriceCalculator::calculate(
            $characters, 0, $unitPrice, $perQuantity, '0', 1,
        )['amount'];
    }
}
