<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final class DispatchStatePolicy
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'prepared' => ['dispatching', 'not_dispatched'],
        'dispatching' => ['succeeded', 'failed', 'ambiguous'],
        'ambiguous' => [],
    ];

    public static function assertTransition(string $from, string $to, string $idempotencyMode = 'none', bool $sameContext = true): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Dispatch state transition is not allowed');
        }
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, ['succeeded', 'failed', 'not_dispatched', 'ambiguous'], true);
    }
}
