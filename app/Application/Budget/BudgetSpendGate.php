<?php

declare(strict_types=1);

namespace app\Application\Budget;

/**
 * Whether a call may proceed under a published policy. Budgets only judge
 * money already committed to the window (settled + reserved + held), never
 * an estimated capacity: soft policies never block, hard policies block
 * only once the committed amount has reached the monthly limit.
 */
final class BudgetSpendGate
{
    /** User-facing copy required by the product spec for a blocked call. */
    public const EXHAUSTED_MESSAGE = '本月预算已用完，可以去费用页调高预算';

    /**
     * Placeholder amount written on reservations when no verified cost bound
     * exists. Positive so the ledger row stays valid; it never decides whether
     * a call is admitted.
     */
    public const NOMINAL_ESTIMATE = '0.000001';

    public static function allowsCall(string $enforcement, ?string $limitAmount, string $committedAmount): bool
    {
        if ($enforcement !== 'hard' || $limitAmount === null) {
            return true;
        }

        return BudgetDecimal::compare($committedAmount, $limitAmount) < 0;
    }

    /** @param array{limit_amount:?string,reserved_amount:string,settled_amount:string,held_amount:string} $window */
    public static function allowsWindowCall(string $enforcement, array $window): bool
    {
        $limit = $window['limit_amount'] ?? null;
        $committed = BudgetDecimal::add(
            BudgetDecimal::add(
                BudgetDecimal::normalize($window['settled_amount'] ?? '0'),
                BudgetDecimal::normalize($window['reserved_amount'] ?? '0'),
            ),
            BudgetDecimal::normalize($window['held_amount'] ?? '0'),
        );

        return self::allowsCall(
            $enforcement,
            $limit === null || $limit === '' ? null : BudgetDecimal::normalize($limit),
            $committed,
        );
    }
}
