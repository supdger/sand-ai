<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialValidationNode extends Node
{
    public function __invoke(
        NeuronMaterialCompletionReady $event,
        WorkflowState $state,
        NeuronMaterialAnalysisResources $resources,
    ): StopEvent {
        $prompt = $event->prepared->prompt;
        $manifest = $resources->outputValidator->validateProviderOutput(
            $event->content,
            $event->prepared->profile,
            $resources->files,
            $prompt->sourceBlockIds,
        );
        $state->set('material_analysis_result', ['prompt' => $prompt, 'manifest' => $manifest]);
        return new StopEvent();
    }
}
