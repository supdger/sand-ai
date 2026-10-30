<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Application\Gateway\RequestPayloadFingerprint;

/** JSONB objects have unordered keys; arrays and all scalar types retain their original meaning. */
final class AudioSnapshot
{
    /** @param list<string> $keys */
    public static function hasKeys(mixed $value, array $keys): bool
    {
        if (!is_array($value) || count($value) !== count($keys)) return false;
        foreach ($keys as $key) if (!array_key_exists($key, $value)) return false;
        return true;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right) && RequestPayloadFingerprint::same($left, $right, []);
    }
}
