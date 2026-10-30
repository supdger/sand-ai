<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Application\Workflow\MaterialAnalysisCompletionClient;
use NeuronAI\Workflow\WorkflowResources;

/** Frozen request-local inputs; durable execution and receipts remain SandAI-owned. */
final class NeuronLegalAnalysisResources extends WorkflowResources
{
    /** @param array<string,mixed> $input @param array{sources:list<array<string,mixed>>} $resolved */
    public function __construct(
        public readonly array $input,
        public readonly array $resolved,
        public readonly MaterialAnalysisCompletionClient $completionClient,
        public readonly LegalAnalysisPromptFactory $promptFactory,
        public readonly LegalAnalysisOutputValidator $outputValidator,
        public readonly ?LegalOutputContract $outputContract = null,
    ) {
        parent::__construct();
    }
}
