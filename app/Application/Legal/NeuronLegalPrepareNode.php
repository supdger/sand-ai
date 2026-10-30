<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronLegalPrepareNode extends Node
{
    public function __invoke(
        StartEvent $event,
        WorkflowState $state,
        NeuronLegalAnalysisResources $resources,
    ): NeuronLegalPromptReady {
        // Confirm frozen server profile shape before any provider-visible action.
        (new LegalAnalysisProfileRegistry())->validate($resources->input['profile']);
        return new NeuronLegalPromptReady(
            $resources->promptFactory->build($resources->input, $resources->resolved),
        );
    }
}
