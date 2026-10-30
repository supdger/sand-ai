<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;

/** Pure amount-conservation rules shared by runtime persistence and tests. */
final class BudgetReservationStateMachine
{
    /** @param array<string,mixed> $window @return array<string,string> */
    public function summary(array $window): array
    {
        $normalized = $this->window($window);
        $available = BudgetDecimal::subtract(
            BudgetDecimal::subtract(BudgetDecimal::subtract($normalized['limit_amount'], $normalized['settled_amount']), $normalized['reserved_amount']),
            $normalized['held_amount'],
        );

        if (array_key_exists('available_amount', $window)) {
            try {
                $reported = BudgetDecimal::normalize($window['available_amount']);
            } catch (\InvalidArgumentException) {
                throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'budget window available amount is invalid');
            }
            if (BudgetDecimal::compare($reported, $available) !== 0) {
                throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'budget window totals do not conserve its limit');
            }
        }

        return $normalized + ['available_amount' => $available];
    }

    /** @param array<string,mixed> $window @return array{window:array<string,string>,reservation:array<string,string|null>} */
    public function reserve(array $window, string $amount, string $currency, bool $allowOverrun = false): array
    {
        $summary = $this->summary($window);
        $amount = $this->positive($amount);
        $this->currency($summary['currency'], $currency);
        if (!$allowOverrun && BudgetDecimal::compare($amount, $summary['available_amount']) > 0) {
            throw new ApiProblem('SAND_AI_BUDGET_EXCEEDED', 'budget reservation exceeds available amount');
        }
        $summary['reserved_amount'] = BudgetDecimal::add($summary['reserved_amount'], $amount);
        unset($summary['available_amount']);

        return [
            'window' => $this->summary($summary),
            'reservation' => ['state' => 'reserved', 'estimate_amount' => $amount, 'metered_amount' => null, 'currency' => $summary['currency']],
        ];
    }

    /** @param array<string,mixed> $window @param array<string,mixed> $reservation @return array{window:array<string,string>,reservation:array<string,string|null>,overrun:bool} */
    public function settle(array $window, array $reservation, string $actualAmount, string $currency): array
    {
        return $this->transition($window, $reservation, 'settled', $actualAmount, $currency);
    }

    /** @param array<string,mixed> $window @param array<string,mixed> $reservation @return array{window:array<string,string>,reservation:array<string,string|null>,overrun:bool} */
    public function hold(array $window, array $reservation, string $currency): array
    {
        return $this->transition($window, $reservation, 'held', null, $currency);
    }

    /** @param array<string,mixed> $window @param array<string,mixed> $reservation @return array{window:array<string,string>,reservation:array<string,string|null>,overrun:bool} */
    public function release(array $window, array $reservation, string $currency): array
    {
        return $this->transition($window, $reservation, 'released', null, $currency);
    }

    /** @param array<string,mixed> $window @param array<string,mixed> $reservation @return array{window:array<string,string>,reservation:array<string,string|null>,overrun:bool} */
    private function transition(array $window, array $reservation, string $target, ?string $actualAmount, string $currency): array
    {
        $summary = $this->summary($window);
        $state = $reservation['state'] ?? null;
        $estimate = $this->positive($reservation['estimate_amount'] ?? null);
        $this->currency($summary['currency'], $currency);
        $this->currency($summary['currency'], $reservation['currency'] ?? null);
        $metered = $target === 'settled' ? $this->nonNegative($actualAmount) : null;
        if (in_array($state, ['reserved', 'held'], true)) {
            $this->assertUnmeasuredSnapshot($reservation);
        }
        if ($state === $target) {
            $this->assertTargetSnapshot($summary, $reservation, $target, $estimate, $metered);
            return ['window' => $summary, 'reservation' => $this->reservation($reservation, $estimate, $summary['currency']), 'overrun' => BudgetDecimal::compare($summary['available_amount'], '0.000000') < 0];
        }
        if (!in_array($state, ['reserved', 'held'], true) || ($state === 'held' && !in_array($target, ['settled', 'released'], true))) {
            throw new ApiProblem('SAND_AI_BUDGET_STATE_INVALID', 'budget reservation transition is invalid');
        }
        if ($target === 'held' && $state !== 'reserved') {
            throw new ApiProblem('SAND_AI_BUDGET_STATE_INVALID', 'only a reserved amount can hold');
        }

        if ($state === 'reserved') {
            if (BudgetDecimal::compare($estimate, $summary['reserved_amount']) > 0) {
                throw new ApiProblem('SAND_AI_BUDGET_STATE_INVALID', 'reservation does not match the reserved window amount');
            }
            $summary['reserved_amount'] = BudgetDecimal::subtract($summary['reserved_amount'], $estimate);
        } else {
            if (BudgetDecimal::compare($estimate, $summary['held_amount']) > 0) {
                throw new ApiProblem('SAND_AI_BUDGET_STATE_INVALID', 'reservation does not match the held window amount');
            }
            $summary['held_amount'] = BudgetDecimal::subtract($summary['held_amount'], $estimate);
        }
        if ($target === 'settled') {
            $summary['settled_amount'] = BudgetDecimal::add($summary['settled_amount'], $metered);
        } elseif ($target === 'held') {
            $summary['held_amount'] = BudgetDecimal::add($summary['held_amount'], $estimate);
        }
        unset($summary['available_amount']);
        $summary = $this->summary($summary);
        $out = $this->reservation($reservation, $estimate, $summary['currency']);
        $out['state'] = $target;
        $out['metered_amount'] = $metered;
        $overrun = ($target === 'settled' && BudgetDecimal::compare($metered, $estimate) > 0)
            || BudgetDecimal::compare($summary['available_amount'], '0.000000') < 0;

        return ['window' => $summary, 'reservation' => $out, 'overrun' => $overrun];
    }

    /** @param array<string,mixed> $window @return array<string,string> */
    private function window(array $window): array
    {
        $currency = strtoupper((string) ($window['currency'] ?? ''));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'budget currency is invalid');
        }
        $values = ['limit_amount', 'reserved_amount', 'settled_amount', 'held_amount'];
        $normalized = ['currency' => $currency];
        foreach ($values as $key) {
            $normalized[$key] = $this->nonNegative($window[$key] ?? null);
        }

        return $normalized;
    }

    /** @param array<string,mixed> $reservation @return array<string,string|null> */
    private function reservation(array $reservation, string $estimate, string $currency): array
    {
        $state = (string) ($reservation['state'] ?? '');
        $metered = $state === 'settled'
            ? $this->nonNegative($reservation['metered_amount'] ?? null)
            : null;

        return [
            'state' => $state,
            'estimate_amount' => $estimate,
            'metered_amount' => $metered,
            'currency' => $currency,
        ];
    }

    /** @param array<string,string> $summary @param array<string,mixed> $reservation */
    private function assertTargetSnapshot(array $summary, array $reservation, string $target, string $estimate, ?string $metered): void
    {
        if ($target === 'settled') {
            $stored = $this->nonNegative($reservation['metered_amount'] ?? null);
            if ($metered === null || BudgetDecimal::compare($metered, $stored) !== 0) {
                throw new ApiProblem('SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT', 'settlement replay amount differs from the existing reservation');
            }
            if (BudgetDecimal::compare($summary['settled_amount'], $stored) < 0) {
                throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'settled window amount is below its reservation');
            }

            return;
        }
        if ($target === 'held') {
            if (BudgetDecimal::compare($summary['held_amount'], $estimate) < 0) {
                throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'held window amount is below its reservation');
            }
            $this->assertUnmeasuredSnapshot($reservation);

            return;
        }
        if ($target === 'released') {
            if (($reservation['metered_amount'] ?? null) !== null) {
                throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'released reservations must not retain a metered amount');
            }

            return;
        }
        if ($target === 'reserved' && BudgetDecimal::compare($summary['reserved_amount'], $estimate) < 0) {
            throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'reserved window amount is below its reservation');
        }
    }

    /** @param array<string,mixed> $reservation */
    private function assertUnmeasuredSnapshot(array $reservation): void
    {
        $stored = $reservation['metered_amount'] ?? null;
        if ($stored === null) {
            return;
        }
        // Earlier candidates used zero as a placeholder. Accept only that
        // legacy placeholder; new transitions persist unknown amounts as NULL.
        try {
            $legacyAmount = $this->nonNegative($stored);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'Unmeasured reservations cannot contain a metered amount');
        }
        if (BudgetDecimal::compare($legacyAmount, '0.000000') !== 0) {
            throw new ApiProblem('SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT', 'Unmeasured reservations cannot contain a metered amount');
        }
    }

    private function positive(mixed $value): string
    {
        $amount = $this->nonNegative($value);
        if (!BudgetDecimal::isPositive($amount)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget amount must be positive');
        }

        return $amount;
    }

    private function nonNegative(mixed $value): string
    {
        try {
            return BudgetDecimal::normalize($value, false);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget amount must be a non-negative numeric(20,6) decimal string');
        }
    }

    private function currency(string $expected, mixed $actual): void
    {
        if (!is_string($actual) || strtoupper($actual) !== $expected) {
            throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'budget currency mismatch');
        }
    }
}
