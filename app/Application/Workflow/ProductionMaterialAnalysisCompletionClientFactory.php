<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/** Default worker wiring: real ChatGateway, SandIAM authorization and provider adapter. */
final class ProductionMaterialAnalysisCompletionClientFactory implements MaterialAnalysisCompletionClientFactory
{
    public function create(StructuredCompletionContext $context): MaterialAnalysisCompletionClient
    {
        return new ChatGatewayMaterialAnalysisCompletionClient($context);
    }
}
