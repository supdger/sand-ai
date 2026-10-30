<?php

declare(strict_types=1);

namespace app\Application\Workflow;

interface MaterialAnalysisCompletionClient
{
    /** @param list<array{role:string,content:string}> $messages */
    public function complete(array $messages): string;
}
