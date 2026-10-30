<?php

declare(strict_types=1);

namespace app\Application\Audio;

use LogicException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;

/** One already selected durable meeting node; TaskStep remains the recovery authority. */
final class NeuronMeetingSummaryWorkflow extends Workflow
{
    public function __construct(NeuronMeetingSummaryResources $resources)
    {
        parent::__construct('sandai_meeting_summary_local');
        $this->setPersistence(new InMemoryPersistence());
        $this->setResources(static fn (): NeuronMeetingSummaryResources => $resources);
        $this->setLeaseTimeout(null);
        $this->setMaxSteps(3);
        $this->retainCompletionUntilAcknowledged(false);
        $this->addNodes([
            new NeuronMeetingSummaryPrepareNode(),
            new NeuronMeetingSummaryCompletionNode(),
            new NeuronMeetingSummaryValidationNode(),
        ]);
    }

    /** @return array{content:string,summary:array<string,mixed>,invocation_id:int,request_id:string} */
    public function summarize(): array
    {
        $state = $this->run(ExecutionRequest::start(new StartEvent(), recoverFailed: false));
        $result = $state->get('meeting_summary_result');
        if (!is_array($result) || !is_string($result['content'] ?? null)
            || !is_array($result['summary'] ?? null) || !is_int($result['invocation_id'] ?? null)
            || !is_string($result['request_id'] ?? null)) {
            throw new LogicException('The meeting graph did not return its validated original result.');
        }
        return $result;
    }
}
