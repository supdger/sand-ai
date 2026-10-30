<?php

declare(strict_types=1);

namespace app\Application\Usage;

use InvalidArgumentException;

/** Preserves fixed-point SQL bind values as strings; never lets ORM schema casts see them. */
final class NumericSql
{
    /** @return list<string|null> */
    public static function binds(mixed ...$values): array
    {
        foreach ($values as $value) {
            if ($value !== null && !is_string($value)) { throw new InvalidArgumentException('Numeric SQL bind values must be strings'); }
        }
        return $values;
    }
}
