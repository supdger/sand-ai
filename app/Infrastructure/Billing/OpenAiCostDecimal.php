<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;

final class OpenAiCostDecimal
{
    private const MAX_INTEGER_DIGITS = 14;
    private const MAX_SCALE = 6;
    private const MAX_MANTISSA_DIGITS = 64;
    private const MAX_ABSOLUTE_EXPONENT = 100;

    public function normalize(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/', $value, $matches) !== 1) {
            throw $this->problem();
        }
        $whole = $matches[1];
        $fraction = $matches[2] ?? '';
        $digits = $whole . $fraction;
        if (strlen($digits) > self::MAX_MANTISSA_DIGITS) {
            throw $this->problem();
        }

        $exponent = $this->exponent($matches[3] ?? '0');
        $leadingZeros = strspn($digits, '0');
        if ($leadingZeros === strlen($digits)) {
            return '0';
        }
        $lastNonZero = strlen($digits) - 1;
        while ($digits[$lastNonZero] === '0') {
            $lastNonZero--;
        }
        $decimalPosition = strlen($whole) + $exponent;
        $integerDigits = max(0, $decimalPosition - $leadingZeros);
        $scale = max(0, $lastNonZero - $decimalPosition + 1);
        if ($integerDigits > self::MAX_INTEGER_DIGITS || $scale > self::MAX_SCALE) {
            throw $this->problem();
        }

        if ($decimalPosition <= 0) {
            $normalized = '0.' . str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $normalized = $digits . str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $normalized = substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
        }
        [$normalizedWhole, $normalizedFraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $normalizedWhole = ltrim($normalizedWhole, '0') ?: '0';
        $normalizedFraction = rtrim($normalizedFraction, '0');

        return $normalizedFraction === '' ? $normalizedWhole : $normalizedWhole . '.' . $normalizedFraction;
    }

    private function exponent(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0') ?: '0';
        if (strlen($digits) > 3 || (int) $digits > self::MAX_ABSOLUTE_EXPONENT) {
            throw $this->problem();
        }

        return ($negative ? -1 : 1) * (int) $digits;
    }

    private function problem(): ApiProblem
    {
        return new ApiProblem('SAND_AI_BILLING_NORMALIZATION_FAILED', 'OpenAI Costs decimal invalid');
    }
}
