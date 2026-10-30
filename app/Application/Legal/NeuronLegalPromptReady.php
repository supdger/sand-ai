<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\Workflow\Events\Event;

final class NeuronLegalPromptReady implements Event
{
    /** @param array{messages:list<array{role:string,content:string}>,source_block_ids:list<int>} $prompt */
    public function __construct(public readonly array $prompt)
    {
    }
}
