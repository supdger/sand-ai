<?php

declare(strict_types=1);
namespace app\Application\Composition;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
final class CompositionSelectNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state, CompositionResources $resources): CompositionNodeReady|StopEvent
    {
        $action = ($resources->selectReady)();
        return $action === null ? new StopEvent() : new CompositionNodeReady($action);
    }
}
