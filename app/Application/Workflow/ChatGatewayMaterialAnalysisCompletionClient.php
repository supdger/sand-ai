<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\InternalModelInvocationContext;
use app\Application\Gateway\OutcomeReceiptRequest;

/** Trusted structured bridge; public Chat cannot construct this context. */
final class ChatGatewayMaterialAnalysisCompletionClient implements MaterialAnalysisCompletionClient
{
    public function __construct(private readonly StructuredCompletionContext $context, private readonly ChatGateway $gateway = new ChatGateway())
    {
    }

    public function complete(array $messages): string
    {
        $config = config('sand_ai_material_analysis_dispatch');
        if (!is_array($config) || ($config['material_analysis_dispatch_enabled'] ?? false) !== true) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Material-analysis dispatch is disabled');
        }
        $maxOutputTokens = $config['max_output_tokens'] ?? null;
        if (!is_int($maxOutputTokens) || $maxOutputTokens < 1) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Material-analysis output token bound is invalid');
        }
        $receiptConfig = config('sand_ai_outcome_receipt');
        $receiptLimit = is_array($receiptConfig) ? ($receiptConfig['max_plaintext_bytes'] ?? null) : null;
        if (!is_int($receiptLimit) || $receiptLimit < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Material-analysis outcome receipt retention is not configured');
        }
        $result = $this->gateway->complete(
            $this->context->environmentId,
            $this->context->modelCode,
            $messages,
            $this->context->requestId(),
            $this->context->attribution,
            $maxOutputTokens,
            'structured_extraction',
            new OutcomeReceiptRequest($receiptLimit),
            new InternalModelInvocationContext(
                $this->context->workflowRunId,
                $this->context->taskId,
                $this->context->taskStepId,
                $this->context->workflowRunFileId,
                $this->context->workflowNodeCode,
                $this->context->dispatchLogicalExecutionId(),
                $this->context->executionSnapshotSha256,
                $this->context->applicationScope,
                $this->context->lease,
                $this->context->iamAuthorization,
                $this->context->attemptNo,
                $this->context->singleFile,
                $this->context->crossFileReview,
            ),
            materialReviewOutput: $this->context->reviewOutput,
            legalOutput: $this->context->legalOutput,
        );
        if (($result['content_purged'] ?? false) === true) {
            throw new ApiProblem('SAND_AI_WORKFLOW_CONTENT_PURGED', 'Workflow content has been purged');
        }
        if (!is_string($result['content'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Structured completion returned no content', true);
        }
        return $result['content'];
    }
}
