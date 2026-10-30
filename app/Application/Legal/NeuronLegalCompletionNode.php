<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronLegalCompletionNode extends Node
{
    public function __invoke(
        NeuronLegalPromptReady $event,
        WorkflowState $state,
        NeuronLegalAnalysisResources $resources,
    ): NeuronLegalCompletionReady {
        // Keep IAM revalidation, once-send identities, receipts and cost accounting
        // in the canonical completion client. Never resume an SDK journal to resend.
        return new NeuronLegalCompletionReady(
            $event,
            $resources->completionClient->complete($event->prompt['messages']),
        );
    }
}
