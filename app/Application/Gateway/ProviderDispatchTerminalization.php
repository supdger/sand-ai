<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/**
 * DB-free finalizer input for one actual provider attempt.
 *
 * The gateway passes this immutable decision to the dispatch/ledger finalizer;
 * tests can exercise the same classification with a real prepared Provider
 * call without constructing PostgreSQL records.
 */
final readonly class ProviderDispatchTerminalization
{
    public string $budgetOutcome;

    public function __construct(
        public string $ledgerState,
        public string $budgetAction,
        public string $invocationState,
    ) {
        $expected = [
            'succeeded' => ['settle', 'succeeded'],
            'failed' => ['release', 'failed'],
            'ambiguous' => ['hold', 'failed'],
            'not_dispatched' => ['release', 'failed'],
        ];
        if (($expected[$this->ledgerState] ?? null) !== [$this->budgetAction, $this->invocationState]) {
            throw new \InvalidArgumentException('Provider terminalization outcome is invalid');
        }
        // Keep the old read-only name for already-frozen test probes while all
        // production finalizers consume budgetAction explicitly.
        $this->budgetOutcome = $this->budgetAction;
    }

    public function releasesBudget(): bool
    {
        return $this->budgetAction === 'release';
    }

    public function holdsBudget(): bool
    {
        return $this->budgetAction === 'hold';
    }

    public function isSucceeded(): bool
    {
        return $this->ledgerState === 'succeeded';
    }

    public function isAmbiguous(): bool
    {
        return $this->ledgerState === 'ambiguous';
    }
}
