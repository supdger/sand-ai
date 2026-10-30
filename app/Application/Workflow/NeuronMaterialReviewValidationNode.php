<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialReviewValidationNode extends Node
{
    public function __invoke(
        NeuronMaterialReviewCompleted $event,
        WorkflowState $state,
        NeuronMaterialReviewResources $resources,
    ): StopEvent {
        // The original writer revalidates sealed sources, dispatch and paid
        // receipt proof, then uses the existing official JSON extraction path.
        // Typed output rejection and persistence exceptions propagate unchanged.
        ($resources->storeReview)($event->prepared->input, $event->content);
        $state->set('material_review_committed', true);
        return new StopEvent();
    }
}
