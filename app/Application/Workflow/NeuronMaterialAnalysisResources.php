<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\WorkflowResources;

/**
 * Services and frozen input for one in-process graph. SandAI owns durable recovery.
 */
final class NeuronMaterialAnalysisResources extends WorkflowResources
{
    /**
     * @param array<string,mixed> $profile
     * @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files
     */
    public function __construct(
        public readonly array $profile,
        public readonly array $files,
        public readonly MaterialAnalysisCompletionClient $completionClient,
        public readonly MaterialAnalysisPromptFactory $promptFactory,
        public readonly MaterialAnalysisOutputValidator $outputValidator,
        public readonly MaterialAnalysisProfilePolicy $profilePolicy,
        public readonly ?MaterialAnalysisPrompt $preparedPrompt = null,
    ) {
        parent::__construct();
    }
}
