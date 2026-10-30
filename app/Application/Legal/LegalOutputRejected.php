<?php

declare(strict_types=1);

namespace app\Application\Legal;

/** Only a completed response rejected by the frozen review output contract. */
final class LegalOutputRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $workflowRunId = null,
        public readonly ?int $invocationId = null,
        public readonly ?int $attemptNo = null,
        public readonly ?string $contentSha256 = null,
    ) {
        if (!in_array($errorCode, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'], true)) {
            throw new \InvalidArgumentException('Unsupported legal analysis output rejection');
        }
        parent::__construct('Legal analysis output violates the frozen contract; paid outcome retained');
    }

    public function atPaidOutcome(int $runId, int $invocationId, int $attemptNo, string $content): self
    {
        if ($runId < 1 || $invocationId < 1 || $attemptNo < 1) {
            throw new \InvalidArgumentException('Material review outcome identity is invalid');
        }
        return new self($this->errorCode, $runId, $invocationId, $attemptNo, hash('sha256', $content));
    }
}
