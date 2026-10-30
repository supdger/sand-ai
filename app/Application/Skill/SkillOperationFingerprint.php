<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Canonical, operation-scoped idempotency fingerprints for Skill writes/runs. */
final class SkillOperationFingerprint
{
    /** @param array<string,mixed> $payload */
    public static function for(string $operation, array $payload): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $operation) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill operation is invalid');
        }

        return hash('sha256', self::canonicalJson(['operation' => $operation, 'payload' => $payload]));
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_resource($value) || is_object($value)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request value is invalid');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request object is invalid');
            }
            $value[$key] = self::sort($item);
        }
        return $value;
    }
}
