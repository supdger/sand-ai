<?php

declare(strict_types=1);

namespace app\Application\Audio;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMeetingSummaryPrepareNode extends Node
{
    public function __invoke(
        StartEvent $event,
        WorkflowState $state,
        NeuronMeetingSummaryResources $resources,
    ): NeuronMeetingSummaryPrepared {
        $allowed = [];
        foreach ($resources->nodeInput['segment_ids'] as $id) {
            if (isset($resources->sources[$id])) {
                $allowed[$id] = $resources->sources[$id];
            }
        }
        return new NeuronMeetingSummaryPrepared($resources->nodeInput['messages'], $allowed);
    }
}
