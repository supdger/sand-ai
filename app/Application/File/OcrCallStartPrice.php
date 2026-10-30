<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Usage\PriceRevisionSelector;
use app\Application\Usage\PricingValuePolicy;
use app\Application\Usage\TokenPriceCalculator;
use think\facade\Db;

/** One request attempt, using the formally published OCR service-fee rate. */
final readonly class OcrCallStartPrice
{
    public function __construct(public ?int $revisionId, public ?string $currency, public ?string $amount,
        public ?string $reservationAmount, public array $snapshot) {}

    public static function resolve(?array $target, string $at): self
    {
        if ($target === null) return self::unpriced();
        $rows = Db::connect('pgsql')->table('sand_ai_price_revision')->where('provider_id', $target['provider_id'])
            ->where('model_id', $target['model_id'])->where('capability_code', 'image_ocr')->where('state', 'published')
            ->whereNull('delete_time')->order('id')->lock(true)->select()->toArray();
        $values = new PricingValuePolicy();
        foreach ($rows as $row) {
            $values->positiveId($row['id'] ?? null, 'price_revision_id', PricingValuePolicy::SNAPSHOT_ERROR);
            $values->currency($row['currency'] ?? null, PricingValuePolicy::SNAPSHOT_ERROR);
            $values->effectiveRange($row['effective_from'] ?? null, $row['effective_until'] ?? null, PricingValuePolicy::SNAPSHOT_ERROR);
        }
        $revision = (new PriceRevisionSelector())->select($rows, $at);
        if ($revision === null) return self::unpriced();
        $rates = Db::connect('pgsql')->table('sand_ai_price_rate')->where('price_revision_id', (int) $revision['id'])->lock(true)->select()->toArray();
        if (count($rates) !== 1 || ($rates[0]['meter_code'] ?? null) !== 'external_request') {
            throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Published OCR request price is invalid');
        }
        $price = $values->decimal20Scale8($rates[0]['unit_price'] ?? null, 'unit_price', PricingValuePolicy::SNAPSHOT_ERROR);
        $quantity = $values->perQuantity($rates[0]['per_quantity'] ?? null, PricingValuePolicy::SNAPSHOT_ERROR);
        // Reuse the exact decimal integer-unit calculator; no token quantity is recorded.
        $amount = TokenPriceCalculator::calculate(1, 0, $price, $quantity, '0', 1)['amount'];
        $estimate = TokenPriceCalculator::calculateReservation(1, 0, $price, $quantity, '0', 1)['amount'];
        return new self((int) $revision['id'], $revision['currency'], $amount, $estimate,
            ['snapshot_version' => 'ocr_request_price@1', 'unit' => 'external_request', 'quantity' => 1,
                'price_revision_id' => (int) $revision['id'], 'currency' => $revision['currency'],
                'effective_from' => $revision['effective_from'], 'effective_until' => $revision['effective_until'],
                'rate' => ['unit_price' => $price, 'per_quantity' => $quantity], 'fee_scope' => 'configured_service_fee']);
    }

    private static function unpriced(): self
    {
        return new self(null, null, null, null, ['unit' => 'external_request', 'quantity' => 1, 'rate' => null]);
    }
}
