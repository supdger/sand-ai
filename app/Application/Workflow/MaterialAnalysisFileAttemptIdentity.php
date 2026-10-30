<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Stable provider and request identity for one frozen file attempt. */
final readonly class MaterialAnalysisFileAttemptIdentity
{
    public function __construct(
        public string $parentLogicalExecutionId,
        public int $workflowRunFileId,
        public int $attemptNo,
    ) {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $parentLogicalExecutionId) !== 1
            || $workflowRunFileId < 1 || $attemptNo < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Material file attempt identity is invalid');
        }
    }

    public function requestId(): string
    {
        return 'structured_' . $this->parentLogicalExecutionId . '_file_'
            . $this->workflowRunFileId . '_attempt_' . $this->attemptNo;
    }

    public function dispatchLogicalExecutionId(): string
    {
        return substr(hash('sha256', 'sand-ai:material-file:v1:' . $this->parentLogicalExecutionId
            . ':' . $this->workflowRunFileId . ':' . $this->attemptNo), 0, 48);
    }
}
