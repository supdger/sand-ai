<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMaterialCompletionNode extends Node
{
    public function __invoke(
        NeuronMaterialPromptReady $event,
        WorkflowState $state,
        NeuronMaterialAnalysisResources $resources,
    ): NeuronMaterialCompletionReady {
        // The existing client owns IAM, stable request identity, once-send and receipts.
        // Do not use SDK memoize/resume to reissue a provider-visible operation.
        return new NeuronMaterialCompletionReady(
            $event,
            $resources->completionClient->complete($event->prompt->messages),
        );
    }
}
