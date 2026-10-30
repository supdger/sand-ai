<?php

declare(strict_types=1);

namespace app\Application\Gateway;

final class CanonicalProviderRequest
{
    /** @param array<string,mixed> $body */
    public static function digest(array $body): string
    {
        return hash('sha256', self::encode($body));
    }

    /** @param array<string,mixed> $body */
    public static function encode(array $body): string
    {
        return json_encode(self::canonical($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(self::canonical(...), $value); }
        ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }
}
