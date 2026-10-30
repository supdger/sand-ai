<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** A draft is publishable only after every active case's latest review passes. */
final class SkillCasePublicationPolicy
{
    /** @param list<array{verdict:?string,run_state:?string}> $cases */
    public static function assertReady(array $cases): void
    {
        if ($cases === []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill draft needs at least one active evaluation case');
        }
        foreach ($cases as $case) {
            if ($case['verdict'] !== 'pass' || $case['run_state'] !== 'succeeded') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Every active Skill case needs a passing latest review');
            }
        }
    }
}
