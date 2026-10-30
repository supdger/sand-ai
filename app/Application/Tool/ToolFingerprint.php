<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

final class ToolFingerprint
{
    /** @param array<string,mixed> $payload */
    public static function for(string $domain, array $payload): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $domain) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Tool fingerprint domain is invalid');
        }

        return hash('sha256', self::canonicalJson(['domain' => $domain, 'payload' => $payload]));
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        try {
            return json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Tool request is not valid bounded JSON');
        }
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof \stdClass && get_object_vars($value) === []) return $value;
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Tool request contains an unsupported value');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Tool request object is invalid');
            }
            $value[$key] = self::sort($item);
        }
        return $value;
    }
}
