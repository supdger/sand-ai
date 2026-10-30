<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Application\Workflow\MaterialAnalysisCompletionClient;

final class LegalAnalysisExecutor
{
    public function __construct(
        private readonly MaterialAnalysisCompletionClient $completionClient,
        private readonly LegalAnalysisPromptFactory $promptFactory = new LegalAnalysisPromptFactory(),
        private readonly LegalAnalysisOutputValidator $outputValidator = new LegalAnalysisOutputValidator(),
        private readonly ?LegalOutputContract $outputContract = null,
    ) {
    }

    /**
     * @param array{profile:array<string,mixed>,context:array<string,mixed>,facts:list<array<string,mixed>>,sources:list<array<string,mixed>>} $input
     * @param array{sources:list<array<string,mixed>>} $resolved
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function execute(array $input, array $resolved): array
    {
        return (new NeuronLegalAnalysisWorkflow(new NeuronLegalAnalysisResources(
            $input,
            $resolved,
            $this->completionClient,
            $this->promptFactory,
            $this->outputValidator,
            $this->outputContract,
        )))->analyze();
    }
}
