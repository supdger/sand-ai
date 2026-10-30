<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;

/**
 * Immutable execution identity written beside every provider dispatch.
 * Public chat has a synthetic lease solely for the durable ledger; task work
 * always supplies the worker lease that already fenced the task claim.
 */
final readonly class DispatchExecutionScope
{
    public function __construct(
        public string $scope,
        public string $logicalExecutionId,
        public string $executionSnapshotSha256,
        public ?int $taskRunId,
        public ?int $taskStepId,
        public ?string $logicalNode,
        public int $attemptNo,
        public string $leaseOwner,
        public string $leaseToken,
        public int $leaseFence,
        public ?int $workflowRunId = null,
        public ?int $workflowRunFileId = null,
    ) {
        if (!in_array($scope, ['chat', 'ai_task', 'agent', 'structured_workflow', 'skill', 'embedding', 'rerank'], true)
            || preg_match('/\A[a-f0-9]{48}\z/', $logicalExecutionId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $executionSnapshotSha256) !== 1
            || $attemptNo < 1 || $leaseFence < 1 || preg_match('/\A[a-f0-9]{64}\z/', $leaseToken) !== 1
            || ($taskRunId === null) !== ($taskStepId === null)
            || ($taskRunId !== null && ($taskRunId < 1 || $taskStepId < 1 || $logicalNode === null || $logicalNode === ''))
        ) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Dispatch execution scope is invalid');
        }
    }

    public static function knowledgeRerank(int $environmentId, string $requestId, string $fingerprint): self
    {
        if ($environmentId < 1 || $requestId === '' || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank execution identity is invalid');
        }
        $seed = 'rerank:' . $environmentId . ':' . $requestId;
        return new self('rerank', substr(hash('sha256', $seed), 0, 48), hash('sha256', $fingerprint),
            null, null, null, 1, 'rerank:' . substr(hash('sha256', $seed), 0, 32), hash('sha256', 'lease:' . $seed), 1);
    }

    public static function publicChat(int $environmentId, string $requestId, string $requestFingerprint): self
    {
        $seed = 'chat:' . $environmentId . ':' . $requestId;
        return new self(
            'chat', substr(hash('sha256', $seed), 0, 48), hash('sha256', $requestFingerprint),
            null, null, null, 1, 'api:' . substr(hash('sha256', $seed), 0, 32), hash('sha256', 'lease:' . $seed), 1,
        );
    }

    public static function task(string $scope, int $taskId, int $stepId, string $node, int $attemptNo, TaskExecutionLease $lease): self
    {
        if (!in_array($scope, ['ai_task', 'agent'], true) || $lease->taskId !== $taskId) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task execution scope does not match the current lease');
        }
        $seed = $scope . ':' . $taskId . ':' . $stepId . ':' . $node . ':' . $attemptNo;
        return new self($scope, substr(hash('sha256', $seed), 0, 48), hash('sha256', 'snapshot:' . $seed), $taskId, $stepId, $node, $attemptNo, $lease->owner, $lease->token, $lease->fence);
    }

    public static function internal(InternalModelInvocationContext $context): self
    {
        return new self('structured_workflow', $context->logicalExecutionId, $context->executionSnapshotSha256, $context->taskRunId, $context->taskStepId, $context->workflowNodeCode, $context->attemptNo, $context->lease->owner, $context->lease->token, $context->lease->fence, $context->workflowRunId, $context->workflowRunFileId);
    }

    public static function skill(int $environmentId, int $runId, string $requestFingerprint): self
    {
        $seed = 'skill:' . $environmentId . ':' . $runId;
        return new self('skill', substr(hash('sha256', $seed), 0, 48), hash('sha256', $requestFingerprint), null, null, null, 1, 'skill:' . $runId, hash('sha256', 'lease:' . $seed), 1);
    }

    public static function embedding(int $environmentId, int $indexId, int $sourceBlockId, string $requestFingerprint): self
    {
        if ($environmentId < 1 || $indexId < 1 || $sourceBlockId < 1) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding execution identity is invalid');
        }
        $seed = 'embedding:' . $environmentId . ':' . $indexId . ':' . $sourceBlockId;
        return new self(
            'embedding', substr(hash('sha256', $seed), 0, 48), hash('sha256', $requestFingerprint),
            null, null, null, 1, 'embedding:' . $indexId, hash('sha256', 'lease:' . $seed), 1,
        );
    }

    public static function knowledgeQueryEmbedding(
        int $consumerEnvironmentId, int $publicationId, int $indexId,
        string $requestId, string $requestFingerprint,
    ): self {
        if ($consumerEnvironmentId < 1 || $publicationId < 1 || $indexId < 1
            || $requestId === '' || preg_match('/\A[a-f0-9]{64}\z/D', $requestFingerprint) !== 1) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Knowledge query embedding identity is invalid');
        }
        $seed = 'knowledge-query-embedding:' . $consumerEnvironmentId . ':' . $publicationId
            . ':' . $indexId . ':' . $requestId;
        return new self(
            'embedding', substr(hash('sha256', $seed), 0, 48), hash('sha256', $requestFingerprint),
            null, null, null, 1, 'knowledge-query:' . $publicationId,
            hash('sha256', 'lease:' . $seed), 1,
        );
    }
}
