<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Infrastructure\Persistence\Model\UsageEvent;
use InvalidArgumentException;

/** Safe projection of the immutable token event, never of current prices. */
final class InvocationCostBreakdown
{
    /**
     * @return array{metering_unit:?string,metering_quantity:?string,breakdown_state:string,breakdown:?array}
     */
    public function present(UsageEvent $event): array
    {
        $result = [
            'metering_unit' => null, 'metering_quantity' => null,
            'breakdown_state' => 'unavailable', 'breakdown' => null,
        ];
        if ((string) $event->capability_code === 'rerank'
            && in_array($event->metering_unit, ['rerank_search_units', 'rerank_tokens'], true)) {
            $result['metering_unit'] = (string) $event->metering_unit;
            if ((string) $event->usage_state !== 'measured') return $result;
            try {
                $quantity = BudgetDecimal::normalize((string) $event->metering_quantity, false);
                if (preg_match('/\A(0|[1-9][0-9]*)\.000000\z/D', $quantity) !== 1) return $result;
                $result['metering_quantity'] = substr($quantity, 0, -7);
                $digits = $result['metering_quantity']; $maximum = (string) PHP_INT_MAX;
                if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                    $result['breakdown_state'] = 'held'; return $result;
                }
                if ((string) $event->pricing_state !== 'priced') return $result;
                $snapshot = $event->price_snapshot;
                if (!is_array($snapshot)) return $result;
                $amount = $snapshot['metered_amount'] ?? null;
                $calledAt = $snapshot['called_at'] ?? null;
                unset($snapshot['called_at'], $snapshot['quantity'], $snapshot['metered_amount']);
                $professional = \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::fromPersistence(
                    $calledAt, 'priced', $event->price_revision_id, $snapshot,
                );
                $expected = $professional->amount((int) $result['metering_quantity']);
                if ($expected === null || !is_string($amount) || $amount !== $expected
                    || BudgetDecimal::compare((string) $event->metered_amount, $expected) !== 0
                    || (string) $event->currency !== $professional->currency) {
                    $result['breakdown_state'] = 'held'; return $result;
                }
                $result['breakdown_state'] = 'available';
                $result['breakdown'] = [['meter_code' => $professional->meteringUnit,
                    'quantity' => $result['metering_quantity'], 'unit_price' => $professional->rate['unit_price'],
                    'per_quantity' => $professional->rate['per_quantity'], 'metered_amount' => $expected]];
                return $result;
            } catch (ApiProblem | InvalidArgumentException) {
                $result['breakdown_state'] = 'held'; return $result;
            }
        }
        if ((string) $event->usage_state !== 'measured' || $event->metering_unit !== null) {
            return $result;
        }
        $input = $this->tokenQuantity($event->input_tokens);
        $output = $this->tokenQuantity($event->output_tokens);
        $total = $this->tokenQuantity($event->total_tokens);
        if ($input === null || $output === null || $total === null) {
            return $result;
        }
        if ($input > PHP_INT_MAX - $output || $input + $output !== $total) {
            $result['breakdown_state'] = 'held';
            return $result;
        }
        $result['metering_unit'] = 'total_tokens';
        $result['metering_quantity'] = (string) $total;
        if ((string) $event->pricing_state !== 'priced'
            || $event->metered_amount === null || $event->currency === null
            || $event->price_revision_id === null) {
            return $result;
        }
        $snapshot = $event->price_snapshot;
        if ($snapshot === null) {
            return $result;
        }
        if (!is_array($snapshot)) {
            $result['breakdown_state'] = 'held';
            return $result;
        }
        foreach (['price_revision_id', 'currency', 'effective_from', 'effective_until', 'amounts', 'rates'] as $field) {
            if (!array_key_exists($field, $snapshot)
                || ($snapshot[$field] === null && $field !== 'effective_until')) {
                return $result;
            }
        }
        // A present NULL effective_until means an open price window. Missing
        // evidence is unavailable; present malformed containers are held.
        if (!is_int($snapshot['price_revision_id']) || !is_string($snapshot['currency'])
            || !is_string($snapshot['effective_from'])
            || ($snapshot['effective_until'] !== null && !is_string($snapshot['effective_until']))
            || !is_array($snapshot['amounts']) || !is_array($snapshot['rates'])) {
            $result['breakdown_state'] = 'held';
            return $result;
        }
        $amounts = $snapshot['amounts'];
        foreach (['amount', 'input_amount', 'output_amount'] as $field) {
            if (!array_key_exists($field, $amounts) || $amounts[$field] === null) {
                return $result;
            }
        }
        foreach (['amount', 'input_amount', 'output_amount'] as $field) {
            if (!is_string($amounts[$field])) {
                $result['breakdown_state'] = 'held';
                return $result;
            }
        }
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            if (!array_key_exists($meter, $snapshot['rates']) || $snapshot['rates'][$meter] === null) {
                return $result;
            }
        }
        foreach (['input_tokens', 'output_tokens'] as $meter) {
            $rate = $snapshot['rates'][$meter];
            if (!is_array($rate)) {
                $result['breakdown_state'] = 'held';
                return $result;
            }
            if (!isset($rate['unit_price'], $rate['per_quantity'])) {
                return $result;
            }
            if (!is_string($rate['unit_price']) || !is_int($rate['per_quantity'])) {
                $result['breakdown_state'] = 'held';
                return $result;
            }
        }
        // UsagePricing's event snapshot predates snapshot_version and contains
        // amounts. Validate its same frozen schema with the call-start policy.
        unset($snapshot['amounts']);
        $snapshot += ['snapshot_version' => 1];
        try {
            $price = (new PricingValuePolicy())->persistedSnapshot('priced', $event->price_revision_id, $snapshot);
            $currency = (new PricingValuePolicy())->currency($event->currency, PricingValuePolicy::SNAPSHOT_ERROR);
            if ($price['currency'] !== $currency) {
                $result['breakdown_state'] = 'held';
                return $result;
            }
            $rates = $price['rates'];
            $expected = TokenPriceCalculator::calculate(
                $input, $output,
                $rates['input_tokens']['unit_price'], $rates['input_tokens']['per_quantity'],
                $rates['output_tokens']['unit_price'], $rates['output_tokens']['per_quantity'],
            );
            foreach ($expected as $field => $amount) {
                if (BudgetDecimal::normalize($amounts[$field], false) !== $amount) {
                    $result['breakdown_state'] = 'held';
                    return $result;
                }
            }
            if (BudgetDecimal::normalize((string) $event->metered_amount, false) !== $expected['amount']) {
                $result['breakdown_state'] = 'held';
                return $result;
            }
        } catch (ApiProblem | InvalidArgumentException) {
            // Only decomposition is held. Preserve the original price,
            // metering and reconciliation facts for investigation.
            $result['breakdown_state'] = 'held';
            return $result;
        }
        $result['breakdown_state'] = 'available';
        $result['breakdown'] = [
            [
                'meter_code' => 'input_tokens', 'quantity' => (string) $input,
                'unit_price' => $rates['input_tokens']['unit_price'],
                'per_quantity' => $rates['input_tokens']['per_quantity'],
                'metered_amount' => $amounts['input_amount'],
            ],
            [
                'meter_code' => 'output_tokens', 'quantity' => (string) $output,
                'unit_price' => $rates['output_tokens']['unit_price'],
                'per_quantity' => $rates['output_tokens']['per_quantity'],
                'metered_amount' => $amounts['output_amount'],
            ],
        ];
        return $result;
    }

    private function tokenQuantity(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $digits = (string) $value;
        $maximum = (string) PHP_INT_MAX;
        if (preg_match('/\A(0|[1-9][0-9]*)\z/D', $digits) !== 1
            || strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }
        return (int) $digits;
    }
}
