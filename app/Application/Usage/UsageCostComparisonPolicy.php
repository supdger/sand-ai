<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use InvalidArgumentException;

/** Read-only comparison of proved historical facts and a separate price estimate. */
final class UsageCostComparisonPolicy
{
    /**
     * Fingerprints remain private. Their complete equality is deliberately
     * conservative: a different model or missing input evidence is not a baseline.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $currentFacts
     * @param array<string,mixed> $baselineFacts
     * @return array<string,mixed>
     */
    public function comparison(int $currentId, int $baselineId, array $current, array $baseline, array $currentFacts, array $baselineFacts): array
    {
        $reason = $this->reason($currentId, $baselineId, $current, $baseline, $currentFacts, $baselineFacts);
        $currencies = [];
        foreach ([$current, $baseline] as $cost) {
            $currency = $this->currency($cost);
            $currencies[$currency ?? 'unknown'] = $currency;
        }
        ksort($currencies);
        $amounts = [];
        foreach ($currencies as $currency) {
            $currentAmount = $this->currency($current) === $currency ? $this->amount($current) : null;
            $baselineAmount = $this->currency($baseline) === $currency ? $this->amount($baseline) : null;
            $amounts[] = [
                'currency' => $currency,
                'current_metered_amount' => $currentAmount,
                'baseline_metered_amount' => $baselineAmount,
                // Only a comparable same-currency pair can yield a difference.
                'difference_amount' => $reason === null && $currency !== null && $currentAmount !== null && $baselineAmount !== null
                    ? BudgetDecimal::subtract($baselineAmount, $currentAmount) : null,
            ];
        }
        return ['comparable' => $reason === null, 'reason' => $reason, 'kind' => 'metered_not_cash', 'currencies' => $amounts];
    }

    /** @param array<string,mixed> $cost @return array<string,mixed> */
    public function scenario(array $cost, ?ResolvedTokenPrice $published, ?string $unavailableReason = null): array
    {
        $tokens = $this->tokens($cost);
        $response = [
            'state' => 'unavailable',
            'reason' => null,
            'kind' => 'same_tokens_published_price_estimate',
            'price_revision_id' => null,
            'currency' => null,
            'input_tokens' => $tokens['input_tokens'] ?? null,
            'output_tokens' => $tokens['output_tokens'] ?? null,
            'frozen_amount' => $this->amount($cost),
            'published_amount' => null,
            'difference_amount' => null,
        ];
        if ($tokens === null) {
            $response['reason'] = 'usage_unavailable';
            return $response;
        }
        if (!$this->provedPrice($cost)) {
            $response['reason'] = 'frozen_price_unverified';
            return $response;
        }
        if ($published === null) {
            $response['reason'] = $unavailableReason ?? 'published_price_unavailable';
            return $response;
        }
        $response['price_revision_id'] = $published->revisionId;
        $response['currency'] = $published->currency;
        if (($cost['price']['currency'] ?? null) !== $published->currency) {
            $response['reason'] = 'currency_differs';
            return $response;
        }
        try {
            $calculated = TokenPriceCalculator::calculate(
                $tokens['input_tokens'], $tokens['output_tokens'],
                $published->inputRate['unit_price'], $published->inputRate['per_quantity'],
                $published->outputRate['unit_price'], $published->outputRate['per_quantity'],
            );
            $response['published_amount'] = $calculated['amount'];
            $response['difference_amount'] = BudgetDecimal::subtract($response['frozen_amount'], $calculated['amount']);
        } catch (ApiProblem | InvalidArgumentException) {
            $response['published_amount'] = null;
            $response['difference_amount'] = null;
            $response['reason'] = 'amount_out_of_range';
            return $response;
        }
        $response['state'] = 'estimated';
        return $response;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $currentFacts
     * @param array<string,mixed> $baselineFacts
     */
    private function reason(int $currentId, int $baselineId, array $current, array $baseline, array $currentFacts, array $baselineFacts): ?string
    {
        if ($currentId === $baselineId) return 'same_invocation';
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if (!is_int($currentFacts[$field] ?? null) || $currentFacts[$field] < 1
                || ($baselineFacts[$field] ?? null) !== $currentFacts[$field]) return 'ownership_differs';
        }
        foreach (['capability', 'service_action', 'source_kind'] as $field) {
            if (!is_string($current[$field] ?? null) || $current[$field] === ''
                || ($baseline[$field] ?? null) !== $current[$field]) return 'execution_context_differs';
        }
        if (($currentFacts['execution_scope'] ?? null) !== ($baselineFacts['execution_scope'] ?? null)
            || ($currentFacts['data_class'] ?? null) !== ($baselineFacts['data_class'] ?? null)) return 'execution_context_differs';
        // The request fingerprint proves logical input and scope. The actual
        // wire digest and verified frozen route also prove options/configuration.
        foreach (['request_fingerprint', 'request_body_digest', 'route_snapshot_sha256'] as $field) {
            $currentFingerprint = $currentFacts[$field] ?? null;
            $baselineFingerprint = $baselineFacts[$field] ?? null;
            foreach ([$currentFingerprint, $baselineFingerprint] as $fingerprint) {
                if (!is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) return 'input_context_unverified';
            }
            if (!hash_equals($currentFingerprint, $baselineFingerprint)) return 'input_context_differs';
        }
        $currentTokens = $this->tokens($current);
        $baselineTokens = $this->tokens($baseline);
        if ($currentTokens === null || $baselineTokens === null) return 'usage_unavailable';
        if ($currentTokens !== $baselineTokens
            || ($current['usage']['metering_quantity'] ?? null) !== ($baseline['usage']['metering_quantity'] ?? null)) return 'quantity_differs';
        if (!$this->provedPrice($current) || !$this->provedPrice($baseline)) return 'pricing_unverified';
        if ($current['price']['currency'] !== $baseline['price']['currency']) return 'currency_differs';
        return null;
    }

    /** @param array<string,mixed> $cost */
    private function provedPrice(array $cost): bool
    {
        return ($cost['price']['pricing_state'] ?? null) === 'priced'
            && ($cost['price']['breakdown_state'] ?? null) === 'available'
            && is_int($cost['price']['revision_id'] ?? null) && $cost['price']['revision_id'] > 0
            && is_string($cost['price']['currency'] ?? null)
            && preg_match('/\A[A-Z]{3}\z/D', $cost['price']['currency']) === 1
            && $this->amount($cost) !== null;
    }

    /** @param array<string,mixed> $cost */
    private function amount(array $cost): ?string
    {
        $amount = $cost['price']['metered_amount'] ?? null;
        if (!is_string($amount)) return null;
        try {
            return BudgetDecimal::normalize($amount, false);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $cost */
    private function currency(array $cost): ?string
    {
        $currency = $cost['price']['currency'] ?? null;
        return is_string($currency) && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1 ? $currency : null;
    }

    /** @param array<string,mixed> $cost @return array{input_tokens:int,output_tokens:int,total_tokens:int}|null */
    private function tokens(array $cost): ?array
    {
        if (($cost['usage']['state'] ?? null) !== 'measured'
            || ($cost['usage']['metering_unit'] ?? null) !== 'total_tokens') return null;
        $tokens = $cost['tokens'] ?? null;
        if (!is_array($tokens)) return null;
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
            if (!is_int($tokens[$field] ?? null) || $tokens[$field] < 0) return null;
        }
        if ($tokens['input_tokens'] > PHP_INT_MAX - $tokens['output_tokens']
            || $tokens['input_tokens'] + $tokens['output_tokens'] !== $tokens['total_tokens']
            || ($cost['usage']['metering_quantity'] ?? null) !== (string) $tokens['total_tokens']) return null;
        return ['input_tokens' => $tokens['input_tokens'], 'output_tokens' => $tokens['output_tokens'], 'total_tokens' => $tokens['total_tokens']];
    }
}
