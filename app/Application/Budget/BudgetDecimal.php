<?php

declare(strict_types=1);

namespace app\Application\Budget;

use InvalidArgumentException;

/** Fixed numeric(20,6) arithmetic using strings only. */
final class BudgetDecimal
{
    private const SCALE = 6;
    private const MAX_INTEGER_DIGITS = 14;

    public static function normalize(mixed $value, bool $allowNegative = true): string
    {
        if (!is_string($value)
            || preg_match('/^(-?)(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,6}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Budget amounts must be numeric(20,6) decimal strings');
        }
        if (!$allowNegative && $matches[1] === '-') {
            throw new InvalidArgumentException('Budget amount must not be negative');
        }

        $micros = ltrim($matches[2] . str_pad($matches[3] ?? '', self::SCALE, '0'), '0');
        $micros = $micros === '' ? '0' : $micros;
        if ($micros === '0') {
            return '0.000000';
        }

        return ($matches[1] === '-' ? '-' : '') . self::format($micros);
    }

    public static function add(string $left, string $right): string
    {
        [$leftSign, $leftAbs] = self::parts($left);
        [$rightSign, $rightAbs] = self::parts($right);
        if ($leftSign === $rightSign) {
            return self::signed($leftSign, self::addAbs($leftAbs, $rightAbs));
        }

        $comparison = self::compareAbs($leftAbs, $rightAbs);
        if ($comparison === 0) {
            return '0.000000';
        }

        return $comparison > 0
            ? self::signed($leftSign, self::subtractAbs($leftAbs, $rightAbs))
            : self::signed($rightSign, self::subtractAbs($rightAbs, $leftAbs));
    }

    public static function subtract(string $left, string $right): string
    {
        [, $rightAbs] = self::parts($right);
        $rightSign = str_starts_with(self::normalize($right), '-') ? -1 : 1;

        return self::add($left, self::signed(-$rightSign, $rightAbs));
    }

    public static function compare(string $left, string $right): int
    {
        [$leftSign, $leftAbs] = self::parts($left);
        [$rightSign, $rightAbs] = self::parts($right);
        if ($leftSign !== $rightSign) {
            return $leftSign <=> $rightSign;
        }

        $comparison = self::compareAbs($leftAbs, $rightAbs);

        return $leftSign === 1 ? $comparison : -$comparison;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0.000000') > 0;
    }

    /** @return array{0:int,1:string} */
    private static function parts(string $value): array
    {
        $normalized = self::normalize($value);
        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;

        return [$negative ? -1 : 1, ltrim(str_replace('.', '', $unsigned), '0') ?: '0'];
    }

    private static function signed(int $sign, string $micros): string
    {
        $micros = ltrim($micros, '0') ?: '0';
        if ($micros === '0') {
            return '0.000000';
        }
        if (strlen($micros) > self::MAX_INTEGER_DIGITS + self::SCALE) {
            throw new InvalidArgumentException('Budget amount exceeds numeric(20,6) precision');
        }

        return ($sign < 0 ? '-' : '') . self::format($micros);
    }

    private static function format(string $micros): string
    {
        $micros = ltrim($micros, '0') ?: '0';
        if (strlen($micros) <= self::SCALE) {
            return '0.' . str_pad($micros, self::SCALE, '0', STR_PAD_LEFT);
        }

        return substr($micros, 0, -self::SCALE) . '.' . substr($micros, -self::SCALE);
    }

    private static function compareAbs(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right);
    }

    private static function addAbs(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        for ($leftIndex = strlen($left) - 1, $rightIndex = strlen($right) - 1; $leftIndex >= 0 || $rightIndex >= 0 || $carry > 0; $leftIndex--, $rightIndex--) {
            $sum = $carry + ($leftIndex >= 0 ? ord($left[$leftIndex]) - 48 : 0) + ($rightIndex >= 0 ? ord($right[$rightIndex]) - 48 : 0);
            $result = chr(($sum % 10) + 48) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    /** Requires left >= right. */
    private static function subtractAbs(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        for ($leftIndex = strlen($left) - 1, $rightIndex = strlen($right) - 1; $leftIndex >= 0; $leftIndex--, $rightIndex--) {
            $difference = (ord($left[$leftIndex]) - 48) - $borrow - ($rightIndex >= 0 ? ord($right[$rightIndex]) - 48 : 0);
            if ($difference < 0) {
                $difference += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = chr($difference + 48) . $result;
        }

        return ltrim($result, '0') ?: '0';
    }
}
