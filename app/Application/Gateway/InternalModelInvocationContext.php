<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;

/** Trusted worker-only fields for a structured model invocation. */
final readonly class InternalModelInvocationContext
{
    public function __construct(
        public int $workflowRunId,
        public int $taskRunId,
        public int $taskStepId,
        public ?int $workflowRunFileId,
        public string $workflowNodeCode,
        public string $logicalExecutionId,
        public string $executionSnapshotSha256,
        public string $applicationScope,
        public TaskExecutionLease $lease,
        public array $iamAuthorization = [],
        public int $attemptNo = 1,
        public bool $singleFile = false,
        public bool $crossFileReview = false,
    ) {
        if ($workflowRunId < 1 || $taskRunId !== $lease->taskId || $taskStepId < 1
            || ($crossFileReview
                ? ($workflowRunFileId !== null || $singleFile || $workflowNodeCode !== 'cross_file_review')
                : ($workflowRunFileId === null || $workflowRunFileId < 1 || $workflowNodeCode === 'cross_file_review'))
            || $attemptNo < 1 || (!$singleFile && !$crossFileReview && $attemptNo !== 1)
            || preg_match('/\A[a-f0-9]{48}\z/', $logicalExecutionId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $executionSnapshotSha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $applicationScope) !== 1
            || preg_match('/\A[a-z][a-z0-9_]{1,127}\z/', $workflowNodeCode) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Internal model invocation context is invalid');
        }
    }
}
