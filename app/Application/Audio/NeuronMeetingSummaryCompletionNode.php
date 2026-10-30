<?php

declare(strict_types=1);

namespace app\Application\Audio;

use LogicException;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMeetingSummaryCompletionNode extends Node
{
    public function __invoke(
        NeuronMeetingSummaryPrepared $event,
        WorkflowState $state,
        NeuronMeetingSummaryResources $resources,
    ): NeuronMeetingSummaryCompleted {
        // Recovery supplies the original receipt before this graph starts; it has no send callback.
        if ($resources->recoveredOutcome !== null) {
            return new NeuronMeetingSummaryCompleted($event, $resources->recoveredOutcome);
        }
        $completion = $resources->completion;
        if ($completion === null) {
            throw new LogicException('The meeting graph has no original completion path.');
        }
        return new NeuronMeetingSummaryCompleted($event, $completion($event->messages));
    }
}
