<?php

declare(strict_types=1);

namespace Webman;

/**
 * Minimal stand-in for the Webman `Context` inside isolated transport tests:
 * the fiber context pool only reads and restores a shared ArrayObject.
 * The real class ships with the host framework and is never loaded here.
 */
final class Context
{
    private static ?\ArrayObject $current = null;

    public static function get(): \ArrayObject
    {
        return self::$current ??= new \ArrayObject();
    }

    public static function reset(\ArrayObject $context): void
    {
        self::$current = $context;
    }
}
