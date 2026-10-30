<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** One original provider request for one frozen material node attempt. */
final readonly class MaterialChunkAttemptIdentity
{
    public function __construct(
        public string $parentLogicalExecutionId,
        public int $workflowRunFileId,
        public string $nodeId,
        public int $attemptNo,
    ) {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $parentLogicalExecutionId) !== 1
            || $workflowRunFileId < 1 || $attemptNo < 1
            || preg_match('/\A(?:leaf_[0-9]{6}|merge_[0-9]{6}_review_[0-9]{6})\z/D', $nodeId) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Material chunk identity is invalid');
        }
    }

    public function requestId(): string
    {
        return 'structured_chunk_' . $this->dispatchLogicalExecutionId();
    }

    public function dispatchLogicalExecutionId(): string
    {
        return substr(hash('sha256', 'sand-ai:material-chunk:v1:' . $this->parentLogicalExecutionId
            . ':' . $this->workflowRunFileId . ':' . $this->nodeId . ':' . $this->attemptNo), 0, 48);
    }
}
