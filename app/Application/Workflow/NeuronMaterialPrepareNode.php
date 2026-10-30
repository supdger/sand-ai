<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialPrepareNode extends Node
{
    public function __invoke(
        StartEvent $event,
        WorkflowState $state,
        NeuronMaterialAnalysisResources $resources,
    ): NeuronMaterialPromptReady {
        $profile = $resources->profilePolicy->validate($resources->profile);
        return new NeuronMaterialPromptReady(
            $profile,
            $resources->preparedPrompt ?? $resources->promptFactory->build($profile, $resources->files),
        );
    }
}
