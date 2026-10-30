<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Application\Audio\AudioSnapshot;

/** Exact published audio_seconds rate selected at audio-call start. */
final readonly class ResolvedAudioPrice
{
    /** @param array{unit_price:string,per_quantity:int} $rate */
    public function __construct(public int $revisionId, public string $currency, public array $rate, public string $effectiveFrom, public ?string $effectiveUntil)
    {
        $values = new PricingValuePolicy();
        if ($revisionId < 1 || $values->currency($currency, PricingValuePolicy::SNAPSHOT_ERROR) !== $currency) throw new \app\Api\Support\ApiProblem(PricingValuePolicy::SNAPSHOT_ERROR, 'Audio price identity is invalid');
        $values->effectiveRange($effectiveFrom, $effectiveUntil, PricingValuePolicy::SNAPSHOT_ERROR);
        if (!AudioSnapshot::hasKeys($rate, ['unit_price','per_quantity']) || $values->decimal20Scale8($rate['unit_price'], 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR) !== $rate['unit_price']) throw new \app\Api\Support\ApiProblem(PricingValuePolicy::SNAPSHOT_ERROR, 'Audio price rate is invalid');
        $values->perQuantity($rate['per_quantity'], PricingValuePolicy::SNAPSHOT_ERROR);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return ['price_revision_id' => $this->revisionId, 'currency' => $this->currency, 'effective_from' => $this->effectiveFrom,
            'effective_until' => $this->effectiveUntil, 'rates' => ['audio_seconds' => ['unit_price' => $this->rate['unit_price'], 'per_quantity' => $this->rate['per_quantity']]]];
    }
}
