<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use app\Infrastructure\Persistence\Model\TaskRun;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

final class FrontierStageNode extends Node
{
    public function __invoke(FrontierStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $a = $event->action;
        $r->ledger->runStage($r->task, $r->lease, static function (TaskRun $current) use ($r, $a): array {
            $r->ledger->appendPage($current, $r->lease, 'frontier', $a['round'], $a['sequence'], [$a['entry']]);
            return [];
        });
        return $r->continueAfter($state, 'frontier');
    }
}
