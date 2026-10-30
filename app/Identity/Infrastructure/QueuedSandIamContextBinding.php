<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;

/** Immutable non-secret metadata bound to one queued SandIAM authorization. */
final readonly class QueuedSandIamContextBinding implements QueuedSandIamContextAad
{
    public function __construct(
        public int $workflowRunId,
        public int $authorizationId,
        public string $sourceIp,
        public string $requestId,
        public int $expiresAt,
    ) {
        if ($workflowRunId < 1 || $authorizationId < 1
            || filter_var($sourceIp, FILTER_VALIDATE_IP) === false
            || $sourceIp === '0.0.0.0' || $sourceIp === '::'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1
            || $expiresAt <= 0) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is invalid');
        }
    }

    public function aad(): string
    {
        return json_encode([
            'version' => 1,
            'service_code' => 'sand_ai',
            'audience' => 'sand-ai',
            'action_code' => 'sand_ai.workflow.run',
            'resolver_code' => 'sand_ai.workflow_run',
            'workflow_run_id' => $this->workflowRunId,
            'authorization_id' => $this->authorizationId,
            'source_ip' => $this->sourceIp,
            'request_id' => $this->requestId,
            'expires_at' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
