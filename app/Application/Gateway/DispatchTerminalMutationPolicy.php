<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** A stale observer may mutate an internal dispatch only while it is dispatching. */
final class DispatchTerminalMutationPolicy
{
    public static function mayFinalize(?string $state): bool
    {
        return $state === 'dispatching';
    }
}
