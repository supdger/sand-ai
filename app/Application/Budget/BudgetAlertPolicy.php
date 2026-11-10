<?php

declare(strict_types=1);

namespace app\Application\Budget;

/** In-app warning based on the current reserved, settled and held window balance. */
final class BudgetAlertPolicy
{
    public const DEFAULT_REMINDER_PERCENT = 80;

    /**
     * Levels follow the policy's reminder percentage: notice at reminder,
     * warning ten points higher (never below the reminder itself), exhausted
     * at a fully consumed limit. The 80/90/100 defaults reproduce the
     * historical fixed thresholds exactly.
     *
     * @param array{limit_amount:mixed,available_amount:mixed} $window
     * @return array{level:string,threshold_percent:?int}
     */
    public static function forWindow(array $window, int $reminderPercent = self::DEFAULT_REMINDER_PERCENT): array
    {
        $limitRaw = $window['limit_amount'] ?? null;
        $availableRaw = $window['available_amount'] ?? null;
        if ($limitRaw === null || $limitRaw === '' || $availableRaw === null || $availableRaw === '') {
            return ['level' => 'normal', 'threshold_percent' => null];
        }
        $limit = BudgetDecimal::normalize($limitRaw, false);
        $available = BudgetDecimal::normalize($availableRaw);
        if (BudgetDecimal::compare($available, '0.000000') <= 0) {
            return ['level' => 'exhausted', 'threshold_percent' => 100];
        }
        $reminderPercent = max(1, min(100, $reminderPercent));
        $warningPercent = min($reminderPercent + 10, 99);
        if ($warningPercent < $reminderPercent) {
            $warningPercent = $reminderPercent;
        }
        $spent = BudgetDecimal::subtract($limit, $available);
        if (!BudgetDecimal::isPositive($spent)) {
            return ['level' => 'normal', 'threshold_percent' => null];
        }
        if (self::thresholdReached($spent, $limit, $warningPercent)) {
            return ['level' => 'warning', 'threshold_percent' => $warningPercent];
        }
        if (self::thresholdReached($spent, $limit, $reminderPercent)) {
            return ['level' => 'notice', 'threshold_percent' => $reminderPercent];
        }

        return ['level' => 'normal', 'threshold_percent' => null];
    }

    /**
     * Exact integer comparison spent*100 >= limit*percent on canonical
     * decimal(20,6) strings; micro digits stay strings so no precision is
     * lost to floats or 64-bit integers.
     */
    private static function thresholdReached(string $spent, string $limit, int $percent): bool
    {
        return self::compareScaled($spent, 100, $limit, $percent) >= 0;
    }

    private static function compareScaled(string $left, int $leftFactor, string $right, int $rightFactor): int
    {
        return self::compareDigits(self::scaledMicros($left, $leftFactor), self::scaledMicros($right, $rightFactor));
    }

    /** Canonical decimal(20,6) string times a small integer, in micro digits. */
    private static function scaledMicros(string $decimal, int $factor): string
    {
        $normalized = BudgetDecimal::normalize($decimal, false);
        $unsigned = str_starts_with($normalized, '-') ? substr($normalized, 1) : $normalized;
        [$integer, $fraction] = explode('.', $unsigned, 2);
        $digits = ltrim($integer . $fraction, '0') ?: '0';
        $result = '';
        $carry = 0;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $product = $carry + (ord($digits[$index]) - 48) * $factor;
            $result = chr(($product % 10) + 48) . $result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = chr(($carry % 10) + 48) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }
}
