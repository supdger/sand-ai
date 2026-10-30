<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use InvalidArgumentException;

/** Summarizes every persisted file attempt, including failed attempts with usage. */
final class MaterialWorkflowUsageAggregator
{
    /**
     * @param list<array{invocation_id:int,file_id:int,attempt_no:int,dispatch_state:string,usage:?array<string,mixed>,node?:string}> $attempts
     * @return array<string,mixed>|null
     */
    public function summarize(array $attempts): ?array
    {
        if ($attempts === []) {
            return null;
        }

        $seenInvocations = [];
        $seenAttempts = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $eventCount = 0;
        $missingEventCount = 0;
        $unmeasuredCount = 0;
        $unpricedCount = 0;
        $byCurrency = [];

        foreach ($attempts as $attempt) {
            $invocationId = $attempt['invocation_id'] ?? null;
            $fileId = $attempt['file_id'] ?? null;
            $node = $attempt['node'] ?? 'file';
            $crossFile = $node === 'cross_file_review' || (is_string($node) && preg_match('/\Across_file_review_batch_[0-9]{6}\z/D', $node) === 1);
            $chunkNode = is_string($node) && preg_match('/\A(?:leaf_[0-9]{6}|merge_[0-9]{6}_review_[0-9]{6})\z/D', $node) === 1;
            $attemptNo = $attempt['attempt_no'] ?? null;
            $state = $attempt['dispatch_state'] ?? null;
            if (!is_int($invocationId) || $invocationId < 1 || !is_int($fileId)
                || ($crossFile ? $fileId !== 0 : $fileId < 1)
                || (!in_array($node, ['file', 'cross_file_review'], true) && !$chunkNode && !$crossFile)
                || !is_int($attemptNo) || $attemptNo < 1
                || !in_array($state, ['prepared', 'dispatching', 'succeeded', 'not_dispatched', 'ambiguous', 'failed'], true)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage attempt is invalid');
            }
            $attemptKey = $fileId . ':' . $node . ':' . $attemptNo;
            if (isset($seenInvocations[$invocationId]) || isset($seenAttempts[$attemptKey])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage attempt is duplicated');
            }
            $seenInvocations[$invocationId] = true;
            $seenAttempts[$attemptKey] = true;

            $usage = $attempt['usage'] ?? null;
            if ($usage === null) {
                if (in_array($state, ['dispatching', 'succeeded', 'ambiguous', 'failed'], true)) {
                    $missingEventCount++;
                }
                continue;
            }
            if (!is_array($usage) || in_array($state, ['prepared', 'not_dispatched'], true)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage event is invalid');
            }
            $eventCount++;

            $usageState = $usage['usage_state'] ?? null;
            $pricingState = $usage['pricing_state'] ?? null;
            if (!in_array($usageState, ['measured', 'unmeasured'], true)
                || !in_array($pricingState, ['priced', 'unpriced'], true)
                || ($usageState === 'unmeasured' && $pricingState !== 'unpriced')) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage state is invalid');
            }
            if ($usageState === 'unmeasured') {
                $tokens = $usage['tokens'] ?? null;
                if (!is_array($tokens) || ($tokens['input_tokens'] ?? null) !== null
                    || ($tokens['output_tokens'] ?? null) !== null || ($tokens['total_tokens'] ?? null) !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The unmeasured material tokens are invalid');
                }
                $unmeasuredCount++;
            } else {
                $tokens = $usage['tokens'] ?? null;
                $input = is_array($tokens) ? ($tokens['input_tokens'] ?? null) : null;
                $output = is_array($tokens) ? ($tokens['output_tokens'] ?? null) : null;
                $total = is_array($tokens) ? ($tokens['total_tokens'] ?? null) : null;
                if (!is_int($input) || !is_int($output) || !is_int($total)
                    || $input < 0 || $output < 0 || $input > PHP_INT_MAX - $output
                    || $total !== $input + $output
                    || $inputTokens > PHP_INT_MAX - $input || $outputTokens > PHP_INT_MAX - $output) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material token usage is invalid');
                }
                $inputTokens += $input;
                $outputTokens += $output;
            }

            if ($pricingState === 'unpriced') {
                if (($usage['metered_amount'] ?? null) !== null || ($usage['currency'] ?? null) !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The unpriced material amount is invalid');
                }
                $unpricedCount++;
                continue;
            }
            $currency = $usage['currency'] ?? null;
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage currency is invalid');
            }
            try {
                $amount = BudgetDecimal::normalize($usage['metered_amount'] ?? null, false);
                $byCurrency[$currency] = BudgetDecimal::add($byCurrency[$currency] ?? '0.000000', $amount);
            } catch (InvalidArgumentException $exception) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage amount is invalid');
            }
        }

        if ($inputTokens > PHP_INT_MAX - $outputTokens) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material token total is invalid');
        }
        ksort($byCurrency, SORT_STRING);
        $complete = $missingEventCount === 0 && $unpricedCount === 0 && $eventCount > 0;
        $singleCurrency = $complete && count($byCurrency) === 1;
        $currency = $singleCurrency ? array_key_first($byCurrency) : null;

        return [
            'aggregation' => 'single_file_v1',
            'attempt_count' => count($attempts),
            'event_count' => $eventCount,
            'missing_event_count' => $missingEventCount,
            'unmeasured_count' => $unmeasuredCount,
            'unpriced_count' => $unpricedCount,
            'usage_state' => $missingEventCount > 0 || $eventCount === 0 ? 'unavailable' : ($unmeasuredCount > 0 ? 'unmeasured' : 'measured'),
            'tokens' => $missingEventCount > 0 || $eventCount === 0 || $unmeasuredCount > 0 ? null : [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
            'pricing_state' => !$complete ? 'unavailable' : ($singleCurrency ? 'priced' : 'mixed_currency'),
            'metered_amount' => $singleCurrency ? $byCurrency[$currency] : null,
            'currency' => $currency,
            'by_currency' => $byCurrency,
        ];
    }
}
