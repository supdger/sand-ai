<?php

declare(strict_types=1);

namespace app\Application\Budget;

/** In-app warning based on the current reserved, settled and held window balance. */
final class BudgetAlertPolicy
{
    /** @param array{limit_amount:string,available_amount:string} $window @return array{level:string,threshold_percent:?int} */
    public static function forWindow(array $window): array
    {
        $limit = BudgetDecimal::normalize($window['limit_amount'], false);
        $available = BudgetDecimal::normalize($window['available_amount']);
        if (BudgetDecimal::compare($available, '0.000000') <= 0) {
            return ['level' => 'exhausted', 'threshold_percent' => 100];
        }
        if (BudgetDecimal::compare($available, self::divide($limit, 10)) <= 0) {
            return ['level' => 'warning', 'threshold_percent' => 90];
        }
        if (BudgetDecimal::compare($available, self::divide($limit, 5)) <= 0) {
            return ['level' => 'notice', 'threshold_percent' => 80];
        }
        return ['level' => 'normal', 'threshold_percent' => null];
    }

    /** Exact integer-micro division, rounded down to the database's six places. */
    private static function divide(string $decimal, int $divisor): string
    {
        $digits = str_replace('.', '', $decimal);
        $quotient = '';
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $number = $remainder * 10 + (ord($digit) - 48);
            $quotient .= chr(intdiv($number, $divisor) + 48);
            $remainder = $number % $divisor;
        }
        $micros = str_pad(ltrim($quotient, '0') ?: '0', 7, '0', STR_PAD_LEFT);
        return substr($micros, 0, -6) . '.' . substr($micros, -6);
    }
}
