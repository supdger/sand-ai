<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use NeuronAI\Workflow\Events\Event;

/** A stage selected by the existing fenced PostgreSQL ledger. */
final readonly class MergeStageEvent implements Event
{
    /** @param array<string,mixed> $action */
    public function __construct(public array $action) {}
}
