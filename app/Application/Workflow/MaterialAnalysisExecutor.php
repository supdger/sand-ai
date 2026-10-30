<?php

declare(strict_types=1);

namespace app\Application\Workflow;

final class MaterialAnalysisExecutor
{
    public function __construct(
        private readonly MaterialAnalysisCompletionClient $completionClient = new UnavailableMaterialAnalysisCompletionClient(),
        private readonly MaterialAnalysisPromptFactory $promptFactory = new MaterialAnalysisPromptFactory(),
        private readonly MaterialAnalysisOutputValidator $outputValidator = new MaterialAnalysisOutputValidator(),
        private readonly MaterialAnalysisProfilePolicy $profilePolicy = new MaterialAnalysisProfilePolicy(),
    ) {
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function execute(array $profile, array $files): array
    {
        return $this->executeWithPrompt($profile, $files)['manifest'];
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files
     * @return array{prompt:MaterialAnalysisPrompt,manifest:array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}}
     */
    public function executeWithPrompt(array $profile, array $files): array
    {
        return (new NeuronMaterialAnalysisWorkflow(new NeuronMaterialAnalysisResources(
            $profile,
            $files,
            $this->completionClient,
            $this->promptFactory,
            $this->outputValidator,
            $this->profilePolicy,
        )))->analyze();
    }
}
