<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;

/** Verified deployment limit required before a hard budget can reserve model input. */
final readonly class BudgetTokenProfile
{
    public const MAX_TOKEN_BOUND = 131072;

    public function __construct(public int $inputTokenCeiling, public int $maxOutputTokens)
    {
        if ($inputTokenCeiling < 1 || $inputTokenCeiling > self::MAX_TOKEN_BOUND) {
            throw new ApiProblem('SAND_AI_BUDGET_TOKEN_BOUND_REQUIRED', 'input_token_ceiling must be a verified integer from 1 to 131072');
        }
        if ($maxOutputTokens < 1 || $maxOutputTokens > self::MAX_TOKEN_BOUND) {
            throw new ApiProblem('SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED', 'max_output_tokens must be a verified integer from 1 to 131072');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromDeploymentConfig(array $config): self
    {
        if (!is_int($config['input_token_ceiling'] ?? null)) {
            throw new ApiProblem('SAND_AI_BUDGET_TOKEN_BOUND_REQUIRED', 'input_token_ceiling must be a verified integer in the published deployment');
        }
        if (!is_int($config['max_output_tokens'] ?? null)) {
            throw new ApiProblem('SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED', 'max_output_tokens must be a verified integer in the published deployment');
        }

        return new self($config['input_token_ceiling'], $config['max_output_tokens']);
    }

    public function assertRequestedOutputTokens(int $maxOutputTokens): void
    {
        if ($maxOutputTokens > $this->maxOutputTokens) {
            throw new ApiProblem('SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED', 'max_output_tokens exceeds the verified published deployment bound');
        }
    }
}
