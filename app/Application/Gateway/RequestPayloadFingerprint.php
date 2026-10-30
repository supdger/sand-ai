<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use InvalidArgumentException;

/** Canonical, prompt-safe equality for idempotency payloads. */
final class RequestPayloadFingerprint
{
    /** @param array<string,mixed> $payload @param list<string> $excludedKeys */
    public static function fingerprint(array $payload, array $excludedKeys = ['_usage_attribution']): string
    {
        return hash('sha256', json_encode(self::canonical($payload, $excludedKeys), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right @param list<string> $excludedKeys */
    public static function same(array $left, array $right, array $excludedKeys = ['_usage_attribution']): bool
    {
        try {
            return hash_equals(self::fingerprint($left, $excludedKeys), self::fingerprint($right, $excludedKeys));
        } catch (\JsonException|InvalidArgumentException) {
            return false;
        }
    }

    private static function canonical(mixed $value, array $excludedKeys): mixed
    {
        if (is_float($value) || is_resource($value) || is_object($value)) {
            throw new InvalidArgumentException('Idempotency payload contains an unsupported value');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item, $excludedKeys), $value);
        }
        $canonical = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('Idempotency payload has an invalid key');
            }
            if (is_string($key) && in_array($key, $excludedKeys, true)) {
                continue;
            }
            $canonical[(string) $key] = self::canonical($item, $excludedKeys);
        }
        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
