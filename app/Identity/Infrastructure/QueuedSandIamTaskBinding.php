<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;

/** Binds one queued authorization to an AI completion or agent task. */
final readonly class QueuedSandIamTaskBinding implements QueuedSandIamContextAad
{
    public function __construct(
        public int $taskId,
        public string $taskType,
        public int $authorizationId,
        public string $sourceIp,
        public string $requestId,
        public int $expiresAt,
    ) {
        if ($taskId < 1
            || !in_array($taskType, ['ai_completion', 'agent_run'], true)
            || $authorizationId < 1
            || filter_var($sourceIp, FILTER_VALIDATE_IP) === false
            || $sourceIp === '0.0.0.0' || $sourceIp === '::'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1
            || $expiresAt <= 0) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued task identity context is invalid');
        }
    }

    public function aad(): string
    {
        return json_encode([
            'version' => 1,
            'service_code' => 'sand_ai',
            'audience' => 'sand-ai',
            'action_code' => $this->taskType === 'ai_completion'
                ? 'sand_ai.chat.complete' : 'sand_ai.agent.submit',
            'resolver_code' => 'sand_ai.task_run',
            'task_id' => $this->taskId,
            'task_type' => $this->taskType,
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
