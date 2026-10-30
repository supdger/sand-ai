<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
/** Immutable price decision made before a Provider request can start. */
final readonly class CallStartPricingSnapshot
{
    private const VERSION = 1;
    private const PRICED = 'priced';
    private const UNPRICED = 'unpriced';

    private function __construct(
        public string $calledAt,
        public string $pricingState,
        public ?int $priceRevisionId,
        private ?ResolvedTokenPrice $price,
        private string $unpricedReason,
    ) {
        $values = new PricingValuePolicy();
        $values->utcDateTime($calledAt, 'called_at', PricingValuePolicy::SNAPSHOT_ERROR);
        if ($pricingState === self::PRICED) {
            if ($priceRevisionId === null || $priceRevisionId <= 0 || $price === null || $price->revisionId !== $priceRevisionId) {
                throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'A priced call-start snapshot requires one exact price revision');
            }
            $validated = $values->persistedSnapshot(self::PRICED, $priceRevisionId, ['snapshot_version' => self::VERSION] + $price->snapshot());
            if ($calledAt < $validated['effective_from']
                || ($validated['effective_until'] !== null && $calledAt >= $validated['effective_until'])) {
                throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'The frozen price is not effective at the call start time');
            }
            return;
        }
        if ($pricingState !== self::UNPRICED || $priceRevisionId !== null || $price !== null || $unpricedReason === '') {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'An unpriced call-start snapshot requires an explicit reason and no revision');
        }
        $values->persistedSnapshot(self::UNPRICED, null, ['snapshot_version' => self::VERSION, 'reason' => $unpricedReason]);
    }

    public static function resolve(PriceResolver $resolver, int $providerId, int $modelId, string $capabilityCode, string $calledAt): self
    {
        $price = $resolver->resolve($providerId, $modelId, $capabilityCode, $calledAt);

        return $price === null
            ? self::unpriced($calledAt)
            : new self($calledAt, self::PRICED, $price->revisionId, $price, '');
    }

    public static function unpriced(string $calledAt, string $reason = 'no_published_token_price'): self
    {
        return new self($calledAt, self::UNPRICED, null, null, $reason);
    }

    public static function fromPersistence(mixed $calledAt, mixed $pricingState, mixed $priceRevisionId, mixed $priceSnapshot): self
    {
        if (!is_string($calledAt) || !is_string($pricingState)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'The persisted call-start pricing decision is incomplete');
        }
        $snapshot = is_object($priceSnapshot) ? (array) $priceSnapshot : $priceSnapshot;
        if (!is_array($snapshot)) {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'The persisted call-start pricing snapshot version is unsupported');
        }
        $values = (new PricingValuePolicy())->persistedSnapshot($pricingState, $priceRevisionId, $snapshot);
        if ($values['pricing_state'] === self::UNPRICED) {
            return self::unpriced($calledAt, $values['reason']);
        }
        $price = new ResolvedTokenPrice(
            $values['price_revision_id'],
            $values['currency'],
            $values['rates']['input_tokens'],
            $values['rates']['output_tokens'],
            $values['effective_from'],
            $values['effective_until'],
        );

        return new self($calledAt, self::PRICED, $values['price_revision_id'], $price, '');
    }

    /** @return array{call_started_at:string,call_start_pricing_state:string,call_start_price_revision_id:?int,call_start_price_snapshot:array<string,mixed>} */
    public function persistence(): array
    {
        return [
            'call_started_at' => $this->calledAt,
            'call_start_pricing_state' => $this->pricingState,
            'call_start_price_revision_id' => $this->priceRevisionId,
            'call_start_price_snapshot' => $this->snapshot(),
        ];
    }

    /** @return array<string,mixed> */
    public function dispatchSnapshot(): array
    {
        return [
            'called_at' => $this->calledAt,
            'pricing_state' => $this->pricingState,
            'price_revision_id' => $this->priceRevisionId,
            'price_snapshot' => $this->snapshot(),
        ];
    }

    public function resolvedPrice(): ?ResolvedTokenPrice
    {
        return $this->price;
    }

    /** @return array<string,mixed> */
    public function usagePricing(int $inputTokens, int $outputTokens, bool $usageMeasured): array
    {
        if (!$usageMeasured) {
            return UsagePricing::unpriced('provider_token_usage_unavailable');
        }
        if ($this->price === null) {
            return UsagePricing::unpriced($this->unpricedReason);
        }

        return UsagePricing::priced($this->price, $inputTokens, $outputTokens);
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return $this->price === null
            ? ['snapshot_version' => self::VERSION, 'reason' => $this->unpricedReason]
            : ['snapshot_version' => self::VERSION] + $this->price->snapshot();
    }

}
