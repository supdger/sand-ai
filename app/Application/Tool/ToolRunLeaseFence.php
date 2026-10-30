<?php

declare(strict_types=1);

namespace app\Application\Tool;

/** Shared token/fence comparison used at every Tool evidence and terminal write boundary. */
final class ToolRunLeaseFence
{
    /** @param array{lease_token?:mixed,lease_fence?:mixed} $lease */
    public static function owns(string $state, mixed $currentToken, int $currentFence, array $lease): bool
    {
        return $state === 'running' && is_string($currentToken) && $currentToken !== ''
            && is_string($lease['lease_token'] ?? null)
            && hash_equals($currentToken, $lease['lease_token'])
            && $currentFence > 0 && $currentFence === (int) ($lease['lease_fence'] ?? 0);
    }
}
