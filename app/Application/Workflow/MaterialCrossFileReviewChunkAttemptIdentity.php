<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Original request identity of one frozen cross-file batch attempt. */
final readonly class MaterialCrossFileReviewChunkAttemptIdentity
{
    public function __construct(public string $parentLogicalExecutionId, public string $batchId, public int $attemptNo)
    {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $parentLogicalExecutionId) !== 1
            || preg_match('/\Abatch_[0-9]{6}\z/D', $batchId) !== 1 || $batchId === 'batch_000000' || $attemptNo < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file batch identity is invalid');
        }
    }

    public function requestId(): string
    {
        return 'structured_' . $this->parentLogicalExecutionId . '_cross_file_review_' . $this->batchId . '_attempt_' . $this->attemptNo;
    }

    public function dispatchLogicalExecutionId(): string
    {
        return substr(hash('sha256', 'sand-ai:material-cross-file-batch:v1:' . $this->parentLogicalExecutionId
            . ':' . $this->batchId . ':' . $this->attemptNo), 0, 48);
    }

    public static function fromRequestId(string $logicalExecutionId, string $requestId): ?self
    {
        $prefix = 'structured_' . $logicalExecutionId . '_cross_file_review_';
        if (!str_starts_with($requestId, $prefix)
            || preg_match('/\A(batch_[0-9]{6})_attempt_([1-9][0-9]*)\z/D', substr($requestId, strlen($prefix)), $match) !== 1) return null;
        $identity = new self($logicalExecutionId, $match[1], (int) $match[2]);
        return hash_equals($identity->requestId(), $requestId) ? $identity : null;
    }
}
