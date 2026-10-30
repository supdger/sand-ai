<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class PartStageNode extends Node
{
    public function __invoke(PartStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $r->materializer->prepareLeafPartPage($r->task, $r->lease, $event->action['leaf'], $event->action['page']);
        return $r->continueAfter($state, 'part');
    }
}
