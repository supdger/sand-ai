<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use app\Application\Audio\PostgresMeetingDurableCoverage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class CoverageStageNode extends Node
{
    public function __invoke(CoverageStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $coverage = new PostgresMeetingDurableCoverage();
        $verified = $coverage->verifyNextPage($r->task, $r->lease);
        if ($verified['done'] === true) {
            // The old terminal branch returns immediately; publication authorization stays with its caller.
            $state->set('result', $coverage->candidate($r->task, $r->lease));
            return new StopEvent();
        }
        return $r->continueAfter($state, 'coverage');
    }
}
