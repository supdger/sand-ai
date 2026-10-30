<?php

declare(strict_types=1);

namespace app\Application\Usage;

final readonly class ResolvedTokenPrice
{
    /** @param array{unit_price: string, per_quantity: int} $inputRate @param array{unit_price: string, per_quantity: int} $outputRate */
    public function __construct(
        public int $revisionId,
        public string $currency,
        public array $inputRate,
        public array $outputRate,
        public string $effectiveFrom,
        public ?string $effectiveUntil,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'price_revision_id' => $this->revisionId,
            'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom,
            'effective_until' => $this->effectiveUntil,
            'rates' => [
                'input_tokens' => $this->inputRate,
                'output_tokens' => $this->outputRate,
            ],
        ];
    }
}
