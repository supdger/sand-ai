<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use app\Application\Audio\PostgresMeetingPreparationStage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class SourceStageNode extends Node
{
    public function __invoke(SourceStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        (new PostgresMeetingPreparationStage())->prepareSourcePage($r->task, $r->lease, $event->action['page_no']);
        return $r->continueAfter($state, 'source');
    }
}
