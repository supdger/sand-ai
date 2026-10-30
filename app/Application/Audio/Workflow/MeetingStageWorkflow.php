<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use app\Api\Support\ApiProblem;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\Executor\ExecutionRequest;

/** SDK routing for one PG-selected stage. PG remains the cross-tick recovery authority. */
final class MeetingStageWorkflow
{
    private const EVENTS = [
        'source' => SourceStageEvent::class,
        'part' => PartStageEvent::class,
        'leaf' => LeafStageEvent::class,
        'execute' => ExecuteStageEvent::class,
        'merge' => MergeStageEvent::class,
        'frontier' => FrontierStageEvent::class,
        'coverage' => CoverageStageEvent::class,
    ];

    /** @param array<string,mixed> $action @return array<string,mixed> */
    public function run(array $action, MeetingStageResources $resources): array
    {
        $kind = $action['kind'] ?? null;
        $eventClass = is_string($kind) ? (self::EVENTS[$kind] ?? null) : null;
        if ($eventClass === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议持久执行阶段与固定计划不一致');
        }
        // A fresh SDK in-memory store is scoped to this one tick, never reused as a second durable ledger.
        $event = new $eventClass($action);
        $workflow = (new Workflow('meeting-stage-' . (int) $resources->task->id))
            ->setStartEvent($event)
            ->setResources(static fn (): WorkflowResources => $resources)
            ->setMaxSteps(1)
            ->addNodes([
                new SourceStageNode(),
                new PartStageNode(),
                new LeafStageNode(),
                new ExecuteStageNode(),
                new MergeStageNode(),
                new FrontierStageNode(),
                new CoverageStageNode(),
            ]);
        $state = $workflow->run(ExecutionRequest::start($event, recoverFailed: false));
        $result = $state->get('result');
        if (!is_array($result)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议阶段未返回持久结果');
        }
        return $result;
    }
}
