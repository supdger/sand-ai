<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class LeafStageNode extends Node
{
    public function __invoke(LeafStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $r->materializer->materializeLeaf($r->task, $r->lease, $event->action['leaf']);
        return $r->continueAfter($state, 'leaf');
    }
}
