<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\UsageAttribution;

/** Private worker-only context; it cannot be constructed from a public request. */
final readonly class StructuredCompletionContext
{
    public function __construct(
        public int $environmentId,
        public int $workflowRunId,
        public int $taskId,
        public int $taskStepId,
        public ?int $workflowRunFileId,
        public string $workflowNodeCode,
        public TaskExecutionLease $lease,
        public string $executionSnapshotSha256,
        public string $logicalExecutionId,
        public UsageAttribution $attribution,
        public string $applicationScope,
        public string $modelCode,
        public array $iamAuthorization = [],
        public int $attemptNo = 1,
        public bool $singleFile = false,
        public bool $crossFileReview = false,
        public ?MaterialReviewOutputContract $reviewOutput = null,
        public ?\app\Application\Legal\LegalOutputContract $legalOutput = null,
        public ?string $materialNodeId = null,
        public ?string $materialReviewBatchId = null,
    ) {
        if ($environmentId < 1 || $workflowRunId < 1 || $taskId !== $lease->taskId || $taskStepId < 1
            || ($crossFileReview
                ? ($workflowRunFileId !== null || $singleFile || $workflowNodeCode !== 'cross_file_review')
                : ($workflowRunFileId === null || $workflowRunFileId < 1 || $workflowNodeCode === 'cross_file_review'))
            || ($reviewOutput !== null && !$crossFileReview)
            || ($legalOutput !== null && ($crossFileReview || $singleFile || $workflowNodeCode !== 'analyze_legal_sources'))
            || ($materialReviewBatchId !== null && (!$crossFileReview || preg_match('/\Abatch_[0-9]{6}\z/D', $materialReviewBatchId) !== 1))
            || ($materialNodeId !== null && (!$singleFile || $crossFileReview || preg_match('/\A(?:leaf_[0-9]{6}|merge_[0-9]{6}_review_[0-9]{6})\z/D', $materialNodeId) !== 1))
            || $attemptNo < 1 || (!$singleFile && !$crossFileReview && $attemptNo !== 1)
            || preg_match('/\A[a-z][a-z0-9_]{1,127}\z/', $workflowNodeCode) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $executionSnapshotSha256) !== 1
            || preg_match('/\A[a-f0-9]{48}\z/', $logicalExecutionId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $applicationScope) !== 1 || trim($modelCode) === '') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Structured completion context is invalid');
        }
        if ($attribution->environmentId !== $environmentId || $attribution->capabilityCode !== 'structured_extraction') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Structured completion attribution is invalid');
        }
        if (!hash_equals($applicationScope, $attribution->applicationScope())) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Structured completion application scope is invalid');
        }
    }

    public function requestId(): string
    {
        if ($this->materialReviewBatchId !== null) {
            return (new MaterialCrossFileReviewChunkAttemptIdentity($this->logicalExecutionId, $this->materialReviewBatchId, $this->attemptNo))->requestId();
        }
        if ($this->materialNodeId !== null) return $this->chunkAttemptIdentity()->requestId();
        return $this->crossFileReview
            ? $this->reviewAttemptIdentity()->requestId()
            : ($this->singleFile ? $this->fileAttemptIdentity()->requestId() : 'structured_' . $this->logicalExecutionId);
    }

    public function dispatchLogicalExecutionId(): string
    {
        if ($this->materialReviewBatchId !== null) {
            return (new MaterialCrossFileReviewChunkAttemptIdentity($this->logicalExecutionId, $this->materialReviewBatchId, $this->attemptNo))->dispatchLogicalExecutionId();
        }
        if ($this->materialNodeId !== null) return $this->chunkAttemptIdentity()->dispatchLogicalExecutionId();
        return $this->crossFileReview
            ? $this->reviewAttemptIdentity()->dispatchLogicalExecutionId()
            : ($this->singleFile ? $this->fileAttemptIdentity()->dispatchLogicalExecutionId() : $this->logicalExecutionId);
    }

    private function chunkAttemptIdentity(): MaterialChunkAttemptIdentity
    {
        return new MaterialChunkAttemptIdentity($this->logicalExecutionId, $this->workflowRunFileId, $this->materialNodeId, $this->attemptNo);
    }

    private function fileAttemptIdentity(): MaterialAnalysisFileAttemptIdentity
    {
        return new MaterialAnalysisFileAttemptIdentity(
            $this->logicalExecutionId, $this->workflowRunFileId, $this->attemptNo,
        );
    }

    private function reviewAttemptIdentity(): MaterialCrossFileReviewAttemptIdentity
    {
        return new MaterialCrossFileReviewAttemptIdentity($this->logicalExecutionId, $this->attemptNo);
    }
}
