<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\Event;

final class NeuronMaterialReviewPrepared implements Event
{
    /** @param array<string,mixed> $input Complete original input, including its frozen messages and evidence. */
    public function __construct(public readonly array $input)
    {
    }
}
