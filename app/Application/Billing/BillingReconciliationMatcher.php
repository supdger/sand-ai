<?php

declare(strict_types=1);

namespace app\Application\Billing;

/** Applies only strict, provider-scoped unique matching; it never invents an environment. */
final class BillingReconciliationMatcher
{
    /** @param array<string, string> $line @param list<array{id: int, meter_code: string, quantity: ?string, amount: ?string}> $candidates @return array{state: string, reason: string, usage_event_id: int|null, quantity_difference: string|null, amount_difference: string|null} */
    public function match(array $line, array $candidates): array
    {
        if ($candidates === []) {
            return ['state' => 'unmatched', 'reason' => 'no_candidate', 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null];
        }
        if (count($candidates) !== 1) {
            return ['state' => 'ambiguous', 'reason' => 'multiple_candidates', 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null];
        }
        $candidate = $candidates[0];
        if ($candidate['quantity'] === null || $candidate['amount'] === null) {
            $reason = $candidate['quantity'] === null
                ? 'usage_unmeasured' : 'usage_unpriced';
            return ['state' => 'unmatched', 'reason' => $reason, 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null];
        }
        $quantityDifference = BillingDecimal::difference($line['quantity'], $candidate['quantity']);
        $amountDifference = BillingDecimal::difference($line['amount'], $candidate['amount']);
        if ($line['meter_code'] !== $candidate['meter_code'] || $quantityDifference !== '0.000000' || $amountDifference !== '0.000000') {
            return ['state' => 'discrepancy', 'reason' => 'meter_or_amount_difference', 'usage_event_id' => (int) $candidate['id'], 'quantity_difference' => $quantityDifference, 'amount_difference' => $amountDifference];
        }
        return ['state' => 'reconciled', 'reason' => 'exact_match', 'usage_event_id' => (int) $candidate['id'], 'quantity_difference' => '0.000000', 'amount_difference' => '0.000000'];
    }
}
