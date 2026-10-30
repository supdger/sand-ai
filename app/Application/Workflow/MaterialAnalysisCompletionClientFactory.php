<?php

declare(strict_types=1);

namespace app\Application\Workflow;

interface MaterialAnalysisCompletionClientFactory
{
    public function create(StructuredCompletionContext $context): MaterialAnalysisCompletionClient;
}
