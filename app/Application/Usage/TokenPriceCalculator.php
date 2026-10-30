<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use InvalidArgumentException;

/** Performs token pricing using decimal strings and no native integer products. */
final class TokenPriceCalculator
{
    public const RESERVATION_ROUNDING = 'ceil_each_meter_decimal6@1';

    private const PRICE_SCALE = 8;
    private const MONEY_SCALE = 6;
    private const MAX_MICROS = '99999999999999999999'; // numeric(20, 6)

    /** @return array{amount: string, input_amount: string, output_amount: string} */
    public static function calculate(int $inputTokens, int $outputTokens, string $inputUnitPrice, int $inputPerQuantity, string $outputUnitPrice, int $outputPerQuantity): array
    {
        $input = self::meterAmount($inputTokens, $inputUnitPrice, $inputPerQuantity);
        $output = self::meterAmount($outputTokens, $outputUnitPrice, $outputPerQuantity);
        $total = self::add($input, $output);
        self::assertWithinDatabaseRange($total);

        return [
            'amount' => self::formatMicros($total),
            'input_amount' => self::formatMicros($input),
            'output_amount' => self::formatMicros($output),
        ];
    }

    /**
     * Budget holds round each non-negative meter upward; usage billing keeps calculate().
     * @return array{amount: string, input_amount: string, output_amount: string}
     */
    public static function calculateReservation(int $inputTokens, int $outputTokens, string $inputUnitPrice, int $inputPerQuantity, string $outputUnitPrice, int $outputPerQuantity): array
    {
        $input = self::meterAmount($inputTokens, $inputUnitPrice, $inputPerQuantity, true);
        $output = self::meterAmount($outputTokens, $outputUnitPrice, $outputPerQuantity, true);
        $total = self::add($input, $output);
        self::assertWithinDatabaseRange($total);

        return [
            'amount' => self::formatMicros($total),
            'input_amount' => self::formatMicros($input),
            'output_amount' => self::formatMicros($output),
        ];
    }

    private static function meterAmount(int $quantity, string $unitPrice, int $perQuantity, bool $roundUp = false): string
    {
        if ($quantity < 0 || $perQuantity <= 0) {
            throw new InvalidArgumentException('Token quantity and pricing quantity must be non-negative and positive respectively');
        }

        $numerator = self::multiply((string) $quantity, self::parsePrice($unitPrice));
        // 10^(8 - 6) is represented as decimal digits rather than an integer product.
        $denominator = self::trim((string) $perQuantity . '00');

        return self::divide($numerator, $denominator, $roundUp);
    }

    private static function parsePrice(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]{1,8}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Unit price must be a non-negative decimal with at most eight fraction digits');
        }

        return self::trim($matches[1] . str_pad($matches[2] ?? '', self::PRICE_SCALE, '0'));
    }

    private static function assertWithinDatabaseRange(string $micros): void
    {
        if (self::compare($micros, self::MAX_MICROS) > 0) {
            throw new ApiProblem('SAND_AI_USAGE_AMOUNT_OUT_OF_RANGE', 'Metered amount exceeds the supported database precision');
        }
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

    private static function multiplyDigit(string $value, int $digit): string
    {
        if ($digit === 0 || $value === '0') {
            return '0';
        }
        $carry = 0;
        $result = '';
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $product = ((ord($value[$index]) - 48) * $digit) + $carry;
            $result = chr(($product % 10) + 48) . $result;
            $carry = intdiv($product, 10);
        }

        return ($carry > 0 ? (string) $carry : '') . $result;
    }

    private static function divide(string $numerator, string $denominator, bool $roundUp): string
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

        if ($roundUp) {
            return $remainder === '0' ? $quotient : self::add($quotient, '1');
        }

        return self::compare(self::multiplyDigit($remainder, 2), $denominator) >= 0
            ? self::add($quotient, '1')
            : $quotient;
    }

    private static function add(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += ord($left[$leftIndex--]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += ord($right[$rightIndex--]) - 48;
            }
            $result = chr(($sum % 10) + 48) . $result;
            $carry = intdiv($sum, 10);
        }

        return self::trim($result);
    }

    private static function subtract(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0) {
            $difference = (ord($left[$leftIndex--]) - 48) - $borrow - ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0);
            if ($difference < 0) {
                $difference += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = chr($difference + 48) . $result;
        }

        return self::trim($result);
    }

    private static function compare(string $left, string $right): int
    {
        $left = self::trim($left);
        $right = self::trim($right);

        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right);
    }

    private static function trim(string $value): string
    {
        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function formatMicros(string $micros): string
    {
        $micros = self::trim($micros);
        $length = strlen($micros);
        if ($length <= self::MONEY_SCALE) {
            return '0.' . str_pad($micros, self::MONEY_SCALE, '0', STR_PAD_LEFT);
        }

        return substr($micros, 0, $length - self::MONEY_SCALE) . '.' . substr($micros, -self::MONEY_SCALE);
    }
}
