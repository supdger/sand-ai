<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialReviewCompletionNode extends Node
{
    public function __invoke(
        NeuronMaterialReviewPrepared $event,
        WorkflowState $state,
        NeuronMaterialReviewResources $resources,
    ): NeuronMaterialReviewCompleted {
        // The existing business operation owns IAM, the stable attempt,
        // once-send, receipt and actual usage. This node never retries it.
        return new NeuronMaterialReviewCompleted(
            $event,
            ($resources->completeReview)($event->input),
        );
    }
}
