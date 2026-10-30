<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Keeps provider dispatch outside the short database claim/finalize transactions. */
final class SkillRunStateMachine
{
    public static function claimState(): string
    {
        return 'running';
    }

    public static function terminal(string $current, string $next): void
    {
        if ($current !== 'running' || !in_array($next, ['succeeded', 'failed'], true)) {
            throw new ApiProblem('SAND_AI_SKILL_RUN_STATE_INVALID', 'Skill run cannot be finalized from its current state');
        }
    }

    public static function replayable(string $state): bool
    {
        return in_array($state, ['running', 'succeeded', 'failed'], true);
    }

    /** A durable provider fact must leave the Skill run claimable for recovery. */
    public static function keepsRunningAfterReceiptProblem(string $errorCode): bool
    {
        return in_array($errorCode, [
            'SAND_AI_USAGE_PERSISTENCE_FAILED',
            'SAND_AI_SKILL_RUN_IN_PROGRESS',
            'SAND_AI_RECEIPT_INVALID',
            'SAND_AI_DISPATCH_AMBIGUOUS',
        ], true);
    }
}
