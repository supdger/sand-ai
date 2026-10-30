<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialReviewPrepareNode extends Node
{
    public function __invoke(
        StartEvent $event,
        WorkflowState $state,
        NeuronMaterialReviewResources $resources,
    ): NeuronMaterialReviewPrepared {
        return new NeuronMaterialReviewPrepared(
            $resources->preparedInput ?? $resources->inputLoader->load($resources->workflowRunId, $resources->lease),
        );
    }
}
