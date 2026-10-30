<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioSnapshot;

/** Immutable audio call-start price decision; contract-only does not imply execution. */
final readonly class AudioCallStartPricingSnapshot
{
    private function __construct(public string $calledAt, public ?ResolvedAudioPrice $price, public string $reason)
    {
        (new PricingValuePolicy())->utcDateTime($calledAt, 'called_at', PricingValuePolicy::SNAPSHOT_ERROR);
        if (($price === null) === ($reason === '')) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Audio pricing must be exactly priced or explicitly unpriced');
        }
    }
    public static function priced(string $calledAt, ResolvedAudioPrice $price): self
    {
        if ($calledAt < $price->effectiveFrom || ($price->effectiveUntil !== null && $calledAt >= $price->effectiveUntil)) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Audio price is not effective at call start');
        return new self($calledAt, $price, '');
    }
    public static function resolve(AudioPriceResolver $resolver, int $providerId, int $modelId, string $calledAt): self
    { $price=$resolver->resolve($providerId,$modelId,$calledAt); return $price===null ? self::unpriced($calledAt) : self::priced($calledAt,$price); }
    public static function unpriced(string $calledAt, string $reason = 'no_published_audio_seconds_price'): self
    {
        if ($reason !== 'no_published_audio_seconds_price') { throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Audio unpriced reason is invalid'); }
        return new self($calledAt, null, $reason);
    }
    /** @param array<string,mixed> $value */
    public static function fromPersistence(array $value): self
    {
        if (!AudioSnapshot::hasKeys($value, ['called_at','pricing_state','price_revision_id','price_snapshot']) || !is_string($value['called_at']) || !is_string($value['pricing_state']) || !is_array($value['price_snapshot'])) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Audio pricing snapshot is invalid');
        if ($value['pricing_state'] === 'unpriced' && $value['price_revision_id'] === null && AudioSnapshot::same($value['price_snapshot'], ['snapshot_version'=>1,'reason'=>'no_published_audio_seconds_price'])) return self::unpriced($value['called_at']);
        $snapshot=$value['price_snapshot']; if($value['pricing_state']!=='priced'||!is_int($value['price_revision_id'])||!AudioSnapshot::hasKeys($snapshot, ['snapshot_version','price_revision_id','currency','effective_from','effective_until','rates'])||$snapshot['snapshot_version']!==1||$snapshot['price_revision_id']!==$value['price_revision_id']||!is_string($snapshot['currency'])||!is_string($snapshot['effective_from'])||($snapshot['effective_until']!==null&&!is_string($snapshot['effective_until']))||!is_array($snapshot['rates'])||!AudioSnapshot::hasKeys($snapshot['rates'], ['audio_seconds'])||!is_array($snapshot['rates']['audio_seconds'])) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Audio pricing snapshot is invalid');
        return self::priced($value['called_at'],new ResolvedAudioPrice($value['price_revision_id'],$snapshot['currency'],$snapshot['rates']['audio_seconds'],$snapshot['effective_from'],$snapshot['effective_until']));
    }
    /** @return array<string,mixed> */
    public function usagePricing(AudioSeconds $seconds): array
    {
        if ($this->price === null) {
            return ['price_revision_id' => null, 'price_snapshot' => ['snapshot_version' => 1, 'reason' => $this->reason], 'metered_amount' => null, 'currency' => null, 'pricing_state' => 'unpriced'];
        }
        $amount = AudioPriceCalculator::calculate($seconds, $this->price->rate['unit_price'], $this->price->rate['per_quantity']);
        return ['price_revision_id' => $this->price->revisionId, 'price_snapshot' => ['snapshot_version' => 1] + $this->price->snapshot() + ['audio_seconds' => $seconds->value, 'amount' => $amount], 'metered_amount' => $amount, 'currency' => $this->price->currency, 'pricing_state' => 'priced'];
    }
    /** @return array<string,mixed> */
    public function persistence(): array { return ['called_at'=>$this->calledAt,'pricing_state'=>$this->price===null?'unpriced':'priced','price_revision_id'=>$this->price?->revisionId,'price_snapshot'=>$this->price===null?['snapshot_version'=>1,'reason'=>$this->reason]:['snapshot_version'=>1]+$this->price->snapshot()]; }
    /** @return array<string,mixed> */
    public function dispatchSnapshot(): array { return $this->persistence(); }
}
