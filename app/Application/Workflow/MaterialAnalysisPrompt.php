<?php

declare(strict_types=1);

namespace app\Application\Workflow;

final readonly class MaterialAnalysisPrompt
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param list<int> $sourceBlockIds
     */
    public function __construct(public array $messages, public array $sourceBlockIds)
    {
    }
}
