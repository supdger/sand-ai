<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final class ChatCompletionOptionsPolicy
{
    public const MAX_OUTPUT_TOKENS = 131072;

    public static function normalize(mixed $maxOutputTokens): ChatCompletionOptions
    {
        if ($maxOutputTokens === null) {
            return new ChatCompletionOptions(null);
        }
        if (!is_int($maxOutputTokens) || $maxOutputTokens < 1 || $maxOutputTokens > self::MAX_OUTPUT_TOKENS) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'max_output_tokens must be an integer from 1 to 131072');
        }

        return new ChatCompletionOptions($maxOutputTokens);
    }
}
