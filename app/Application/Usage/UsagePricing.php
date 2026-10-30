<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Application\Budget\BudgetDecimal;
use InvalidArgumentException;

final class UsagePricing
{
    /** @return array<string, mixed> */
    public static function unpriced(string $reason = 'no_published_token_price'): array
    {
        return [
            'price_revision_id' => null,
            'price_snapshot' => ['reason' => $reason],
            'metered_amount' => null,
            'currency' => null,
            'pricing_state' => 'unpriced',
        ];
    }

    /** @return array<string, mixed> */
    public static function priced(ResolvedTokenPrice $price, int $inputTokens, int $outputTokens): array
    {
        $amounts = TokenPriceCalculator::calculate(
            $inputTokens,
            $outputTokens,
            $price->inputRate['unit_price'],
            $price->inputRate['per_quantity'],
            $price->outputRate['unit_price'],
            $price->outputRate['per_quantity'],
        );

        return [
            'price_revision_id' => $price->revisionId,
            'price_snapshot' => $price->snapshot() + ['amounts' => $amounts],
            'metered_amount' => $amounts['amount'],
            'currency' => $price->currency,
            'pricing_state' => 'priced',
        ];
    }

    public static function matchesLegacyCost(?string $expected, mixed $stored): bool
    {
        if ($expected === null) {
            return $stored === null;
        }
        if (!is_string($stored)) {
            return false;
        }

        try {
            return BudgetDecimal::normalize($expected) === self::normalizeLegacyCost($stored);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function normalizeLegacyCost(string $stored): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,8}))?$/', $stored, $matches) !== 1) {
            throw new InvalidArgumentException('Legacy usage cost is not numeric(20,8)');
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > 6) {
            if (trim(substr($fraction, 6), '0') !== '') {
                throw new InvalidArgumentException('Legacy usage cost has non-canonical precision');
            }
            $fraction = substr($fraction, 0, 6);
        }

        return BudgetDecimal::normalize($matches[1] . ($fraction === '' ? '' : '.' . $fraction), false);
    }
}
