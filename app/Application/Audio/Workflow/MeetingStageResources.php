<?php

declare(strict_types=1);

namespace app\Application\Audio\Workflow;

use app\Application\Audio\MeetingSummaryRunner;
use app\Application\Audio\PostgresMeetingLeafMaterialization;
use app\Application\Audio\PostgresMeetingWorkerLedger;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\TaskRun;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StopEvent;

/** Live collaborators are never serialized into SDK workflow state. */
final class MeetingStageResources extends WorkflowResources
{
    /** @param array<string,mixed> $meeting @param array<string,mixed> $input
     * @param \Closure(list<string>):array<string,array<string,mixed>> $readSources
     * @param \Closure(int):void $authorize
     */
    public function __construct(
        public readonly TaskRun $task,
        public readonly TaskExecutionLease $lease,
        public readonly array $meeting,
        public readonly array $input,
        public readonly UsageAttribution $attribution,
        public readonly \Closure $authorize,
        public readonly \Closure $readSources,
        public readonly MeetingSummaryRunner $runner,
        public readonly PostgresMeetingWorkerLedger $ledger,
        public readonly PostgresMeetingLeafMaterialization $materializer,
    ) { parent::__construct(); }

    /** Preserve the old post-stage authorization and continuation shape. */
    public function continueAfter(WorkflowState $state, string $phase): StopEvent
    {
        ($this->authorize)(0);
        $state->set('result', ['_meeting_durable_continue' => true, 'phase' => $phase]);
        return new StopEvent();
    }
}
