<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

final class BillingDecimal
{
    private const SCALE = 6;

    public static function normalize(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,6}))?$/', $value, $matches) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} must be a non-negative decimal string with at most six fraction digits");
        }

        $fraction = isset($matches[2]) ? rtrim($matches[2], '0') : '';

        return $fraction === '' ? $matches[1] : $matches[1] . '.' . $fraction;
    }

    public static function difference(string $left, string $right): string
    {
        $leftScaled = self::scaled($left);
        $rightScaled = self::scaled($right);
        $negative = self::compare($leftScaled, $rightScaled) < 0;
        $amount = $negative ? self::subtract($rightScaled, $leftScaled) : self::subtract($leftScaled, $rightScaled);

        return ($negative && $amount !== '0' ? '-' : '') . self::format($amount);
    }

    private static function scaled(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole . str_pad($fraction, self::SCALE, '0'), '0') ?: '0';
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right);
    }

    private static function subtract(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        for ($leftIndex = strlen($left) - 1, $rightIndex = strlen($right) - 1; $leftIndex >= 0; $leftIndex--) {
            $value = (ord($left[$leftIndex]) - 48) - $borrow - ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0);
            if ($value < 0) {
                $value += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = chr($value + 48) . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function format(string $scaled): string
    {
        $scaled = ltrim($scaled, '0') ?: '0';
        if (strlen($scaled) <= self::SCALE) {
            return '0.' . str_pad($scaled, self::SCALE, '0', STR_PAD_LEFT);
        }

        return substr($scaled, 0, -self::SCALE) . '.' . substr($scaled, -self::SCALE);
    }
}
