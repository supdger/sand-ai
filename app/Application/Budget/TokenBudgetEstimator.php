<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Usage\ResolvedTokenPrice;
use app\Application\Usage\TokenPriceCalculator;

/** Uses a verified deployment ceiling for reservations; the byte count is advisory only. */
final class TokenBudgetEstimator
{
    private const PROTOCOL_OVERHEAD = 256;
    private const MESSAGE_OVERHEAD = 64;
    private const MAX_OUTPUT_TOKENS = 131072;

    /** @param list<array<string,mixed>> $messages @return array{advisory_input_estimate:int,reserved_input_tokens:int,output_token_upper_bound:int,total_reserved_tokens:int,estimated_amount:string,currency:string,price_revision_id:int} */
    public function estimate(array $messages, mixed $maxOutputTokens, ?BudgetTokenProfile $profile, ?ResolvedTokenPrice $price, ?string $expectedCurrency = null, ?BudgetInputTokenProof $inputProof = null): array
    {
        $output = $this->outputTokens($maxOutputTokens);
        $normalized = $this->messages($messages);
        try {
            $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'messages cannot be encoded safely');
        }
        $advisory = $this->safeAdd(strlen($json), self::PROTOCOL_OVERHEAD);
        foreach ($normalized as $_) {
            $advisory = $this->safeAdd($advisory, self::MESSAGE_OVERHEAD);
        }
        if ($profile === null) {
            throw new ApiProblem('SAND_AI_BUDGET_TOKEN_BOUND_REQUIRED', 'a verified input_token_ceiling is required before budget reservation');
        }
        if ($inputProof === null) {
            throw new ApiProblem('SAND_AI_BUDGET_INPUT_PROOF_REQUIRED', 'An official input-token proof is required before reserving a budget upper bound');
        }
        $inputProof->assertInput($normalized, $profile, $output);
        $total = $this->safeAdd($profile->inputTokenCeiling, $output);
        if ($price === null) {
            throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'a published token price is required for hard-budget estimation');
        }
        if ($expectedCurrency !== null && strtoupper($expectedCurrency) !== $price->currency) {
            throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'budget and price currencies differ');
        }
        $amount = TokenPriceCalculator::calculate($profile->inputTokenCeiling, $output, $price->inputRate['unit_price'], $price->inputRate['per_quantity'], $price->outputRate['unit_price'], $price->outputRate['per_quantity']);

        return [
            'advisory_input_estimate' => $advisory,
            'reserved_input_tokens' => $profile->inputTokenCeiling,
            'output_token_upper_bound' => $output,
            'total_reserved_tokens' => $total,
            'estimated_amount' => $amount['amount'],
            'currency' => $price->currency,
            'price_revision_id' => $price->revisionId,
        ];
    }

    private function outputTokens(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_OUTPUT_TOKENS) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'max_output_tokens must be an integer from 1 to 131072');
        }

        return $value;
    }

    /** @param list<array<string,mixed>> $messages @return list<array{role:string,content:string}> */
    private function messages(array $messages): array
    {
        if ($messages === [] || !array_is_list($messages) || count($messages) > 100) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'messages must be a list of 1 to 100 items');
        }
        $normalized = [];
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content']) !== [] || !is_string($message['role'] ?? null) || !is_string($message['content'] ?? null)) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'each budget message must contain only string role and content');
            }
            $role = $message['role'];
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'message role is invalid');
            }
            if (trim($message['content']) === '' || preg_match('//u', $message['content']) !== 1 || mb_strlen($message['content'], 'UTF-8') > 32000) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'message content must be valid UTF-8 and no longer than 32000 characters');
            }
            $normalized[] = ['role' => $role, 'content' => $message['content']];
        }

        return $normalized;
    }

    private function safeAdd(int ...$values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if ($value < 0 || $total > PHP_INT_MAX - $value) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'token budget bound exceeds supported integer range');
            }
            $total += $value;
        }

        return $total;
    }
}
