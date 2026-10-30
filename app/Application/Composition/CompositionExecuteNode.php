<?php

declare(strict_types=1);
namespace app\Application\Composition;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
final class CompositionExecuteNode extends Node
{
    public function __invoke(CompositionNodeReady $event, WorkflowState $state, CompositionResources $resources): StartEvent
    {
        ($resources->execute)($event->action);
        return new StartEvent();
    }
}
