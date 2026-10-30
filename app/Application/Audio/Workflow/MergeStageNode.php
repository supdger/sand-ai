<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class MergeStageNode extends Node
{
    public function __invoke(MergeStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $a = $event->action;
        $sources = ($r->readSources)([$a['left'], $a['right']]);
        $r->runner->materializePersistedPair($r->task, $r->lease, $a['left'], $a['right'],
            $a['code'], $a['level'], $a['sequence'], $sources, $r->meeting);
        return $r->continueAfter($state, 'merge');
    }
}
