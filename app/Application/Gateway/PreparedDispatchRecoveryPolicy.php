<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/**
 * Pure comparison for a crash before any provider request is sent.
 * It deliberately accepts no mutable worker identity as evidence: the caller
 * must first prove that it owns the current task lease under a database lock.
 */
final class PreparedDispatchRecoveryPolicy
{
    /** @param array<string,mixed> $ledger @param array<string,mixed> $route @param array<string,mixed> $price */
    public static function matches(array $ledger, array $route, array $price, InternalModelInvocationContext $context, ProviderDispatchContext $providerContext, ProviderIdempotencyCapability $providerCapability, int $providerId, int $deploymentId, int $modelId, string $capabilityCode, ?int $priceRevisionId): bool
    {
        return ($ledger['state'] ?? null) === 'prepared'
            && ($ledger['dispatch_start_time'] ?? null) === null
            && (int) ($ledger['parent_workflow_run_id'] ?? 0) === $context->workflowRunId
            && (int) ($ledger['parent_task_run_id'] ?? 0) === $context->taskRunId
            && (int) ($ledger['parent_task_step_id'] ?? 0) === $context->taskStepId
            && (($ledger['parent_workflow_run_file_id'] ?? null) === null
                ? null : (int) $ledger['parent_workflow_run_file_id']) === $context->workflowRunFileId
            && (string) ($ledger['workflow_node_code'] ?? '') === $context->workflowNodeCode
            && (string) ($ledger['logical_execution_id'] ?? '') === $context->logicalExecutionId
            && (int) ($ledger['attempt_no'] ?? 0) === $context->attemptNo
            && (string) ($ledger['execution_snapshot_sha256'] ?? '') === $context->executionSnapshotSha256
            && (string) ($ledger['request_body_digest'] ?? '') === $providerContext->bodyDigest
            && (string) ($ledger['provider_idempotency_mode'] ?? '') === $providerCapability->mode
            && (string) ($ledger['provider_idempotency_key'] ?? '') === (string) $providerContext->providerKey
            && (int) ($ledger['provider_id'] ?? 0) === $providerId
            && (int) ($ledger['deployment_id'] ?? 0) === $deploymentId
            && (int) ($ledger['model_id'] ?? 0) === $modelId
            && (string) ($ledger['capability_code'] ?? '') === $capabilityCode
            && (int) ($route['deployment_id'] ?? 0) === $deploymentId
            && (int) ($route['model_id'] ?? 0) === $modelId
            && (int) ($route['provider_id'] ?? 0) === $providerId
            && (($price['price_revision_id'] ?? null) === $priceRevisionId);
    }
}
