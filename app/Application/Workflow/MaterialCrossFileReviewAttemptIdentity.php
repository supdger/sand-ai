<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Stable request identity for one review attempt over a frozen material run. */
final readonly class MaterialCrossFileReviewAttemptIdentity
{
    public function __construct(
        public string $parentLogicalExecutionId,
        public int $attemptNo,
    ) {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $parentLogicalExecutionId) !== 1 || $attemptNo < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review attempt identity is invalid');
        }
    }

    public function requestId(): string
    {
        return 'structured_' . $this->parentLogicalExecutionId
            . '_cross_file_review_attempt_' . $this->attemptNo;
    }

    public function dispatchLogicalExecutionId(): string
    {
        return substr(hash('sha256', 'sand-ai:material-cross-file-review:v1:'
            . $this->parentLogicalExecutionId . ':' . $this->attemptNo), 0, 48);
    }
}
