<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronLegalValidationNode extends Node
{
    public function __invoke(
        NeuronLegalCompletionReady $event,
        WorkflowState $state,
        NeuronLegalAnalysisResources $resources,
    ): StopEvent {
        $input = $resources->input;
        try {
        $manifest = $resources->outputValidator->validate(
            $event->content,
            $input['profile'],
            $event->prepared->prompt['source_block_ids'],
            $input['sources'],
            $input['facts'],
            $resources->outputContract,
            $input['complaint'] ?? null,
            $input['remedy'] ?? null,
        );
        } catch (\app\Api\Support\ApiProblem $failure) {
            if (!in_array($failure->errorCode, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'], true)) throw $failure;
            throw new LegalOutputRejected($failure->errorCode, contentSha256: hash('sha256', $event->content));
        }
        $state->set('legal_analysis_result', $manifest);
        return new StopEvent();
    }
}
