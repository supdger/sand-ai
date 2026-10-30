<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/**
 * SAND-119A freezes and verifies the structured result contract before a
 * provider route is introduced.  It must fail closed rather than silently
 * produce a deterministic-looking AI result.
 */
final class UnavailableMaterialAnalysisCompletionClient implements MaterialAnalysisCompletionClient
{
    /** @param list<array{role:string,content:string}> $messages */
    public function complete(array $messages): string
    {
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'No material-analysis completion route is configured');
    }
}
