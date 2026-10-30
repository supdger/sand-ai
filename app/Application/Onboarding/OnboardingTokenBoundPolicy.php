<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Validates administrator-confirmed JSON token bounds without coercion. */
final class OnboardingTokenBoundPolicy
{
    public const MAX_TOKENS = 131072;

    /** @param array<string,mixed> $input */
    public static function required(array $input, string $field): int
    {
        if (!array_key_exists($field, $input)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' is required and must be an integer from 1 to 131072');
        }

        return self::value($input[$field], $field);
    }

    /** @param array<string,mixed> $input */
    public static function optional(array $input, string $field): ?int
    {
        if (!array_key_exists($field, $input)) {
            return null;
        }

        return self::value($input[$field], $field);
    }

    private static function value(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_TOKENS) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' must be an integer from 1 to 131072');
        }

        return $value;
    }
}
