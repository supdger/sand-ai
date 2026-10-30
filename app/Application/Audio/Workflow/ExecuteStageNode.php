<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use think\facade\Db;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;
use app\Application\Audio\MeetingOutputCorrection;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;

final class ExecuteStageNode extends Node
{
    public function __invoke(ExecuteStageEvent $event, WorkflowState $state, MeetingStageResources $r): StopEvent
    {
        $code = $event->action['node_code'];
        $sources = ($r->readSources)([$code]);
        $dispatch = Db::connect('pgsql')->query(
            "SELECT d.state FROM sand_ai_invocation_dispatch d JOIN sand_ai_meeting_summary_node n
                ON n.task_step_id=d.parent_task_step_id AND n.task_id=d.parent_task_run_id AND n.node_code=d.workflow_node_code
             WHERE n.task_id=? AND n.node_code=? ORDER BY d.id DESC LIMIT 1", [(int) $r->task->id, $code]);
        $recover = $dispatch !== [] && in_array($dispatch[0]['state'], ['dispatching', 'ambiguous', 'succeeded', 'failed'], true);
        if ($recover) {
            $node = MeetingSummaryNode::where('task_id', (int)$r->task->id)->where('node_code', $code)->find();
            if ($node !== null && MeetingOutputCorrection::startsNextAttempt($r->task, $node)) $recover = false;
        }
        $r->runner->executePersistedNode($r->task, $r->lease, $code, $sources, $r->meeting, $r->input,
            $r->attribution, $r->authorize, $recover);
        return $r->continueAfter($state, 'execute');
    }
}
