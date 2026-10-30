<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use InvalidArgumentException;

/** Decimal-only audio charge calculation: seconds x unit price / per quantity. */
final class AudioPriceCalculator
{
    private const MAX_MICROS = '99999999999999999999';

    public static function calculate(AudioSeconds $seconds, string $unitPrice, int $perQuantity): string
    {
        if ($perQuantity < 1) {
            throw new InvalidArgumentException('per_quantity must be positive');
        }
        if (preg_match('/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?\z/D', $unitPrice, $matches) !== 1) {
            throw new InvalidArgumentException('unit_price must be a decimal(20,8) string');
        }
        $quantityMicros = str_replace('.', '', $seconds->value);
        $priceUnits = $matches[1] . str_pad($matches[2] ?? '', 8, '0');
        $micros = self::divideRoundHalfUp(self::multiply($quantityMicros, $priceUnits), (string) $perQuantity . '00000000');
        if (self::compare($micros, self::MAX_MICROS) > 0) {
            throw new ApiProblem('SAND_AI_USAGE_AMOUNT_OUT_OF_RANGE', 'Metered amount exceeds the supported database precision');
        }

        return self::formatMicros($micros);
    }

    private static function multiply(string $left, string $right): string
    {
        $result = '0';
        $shift = 0;
        for ($index = strlen($right) - 1; $index >= 0; $index--) {
            $partial = self::multiplyDigit($left, ord($right[$index]) - 48) . str_repeat('0', $shift++);
            $result = self::add($result, $partial);
        }
        return self::trim($result);
    }

    private static function divideRoundHalfUp(string $numerator, string $denominator): string
    {
        $quotient = '';
        $remainder = '0';
        foreach (str_split($numerator) as $digit) {
            $remainder = self::trim($remainder . $digit);
            $quotientDigit = 0;
            while (self::compare($remainder, $denominator) >= 0) {
                $remainder = self::subtract($remainder, $denominator);
                $quotientDigit++;
            }
            $quotient .= (string) $quotientDigit;
        }
        $quotient = self::trim($quotient);
        return self::compare(self::multiplyDigit($remainder, 2), $denominator) >= 0 ? self::add($quotient, '1') : $quotient;
    }

    private static function multiplyDigit(string $value, int $digit): string
    {
        if ($digit === 0 || $value === '0') { return '0'; }
        $carry = 0; $result = '';
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $product = ((ord($value[$index]) - 48) * $digit) + $carry;
            $result = chr(($product % 10) + 48) . $result;
            $carry = intdiv($product, 10);
        }
        return ($carry > 0 ? (string) $carry : '') . $result;
    }

    private static function add(string $left, string $right): string
    {
        $carry = 0; $result = ''; $leftIndex = strlen($left) - 1; $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry + ($leftIndex >= 0 ? ord($left[$leftIndex--]) - 48 : 0) + ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0);
            $result = chr(($sum % 10) + 48) . $result; $carry = intdiv($sum, 10);
        }
        return self::trim($result);
    }

    private static function subtract(string $left, string $right): string
    {
        $borrow = 0; $result = ''; $leftIndex = strlen($left) - 1; $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0) {
            $difference = (ord($left[$leftIndex--]) - 48) - $borrow - ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0);
            if ($difference < 0) { $difference += 10; $borrow = 1; } else { $borrow = 0; }
            $result = chr($difference + 48) . $result;
        }
        return self::trim($result);
    }

    private static function compare(string $left, string $right): int
    {
        $left = self::trim($left); $right = self::trim($right);
        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right);
    }
    private static function trim(string $value): string { $value = ltrim($value, '0'); return $value === '' ? '0' : $value; }
    private static function formatMicros(string $micros): string
    {
        $micros = self::trim($micros); $length = strlen($micros);
        return $length <= 6 ? '0.' . str_pad($micros, 6, '0', STR_PAD_LEFT) : substr($micros, 0, $length - 6) . '.' . substr($micros, -6);
    }
}
