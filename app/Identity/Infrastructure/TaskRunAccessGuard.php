<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;

/** Authorizes a persisted AI or agent task before queueing and before dispatch. */
final class TaskRunAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?QueuedSandIamContextCipher $cipher = null,
    ) {
    }

    /** @return array<string,mixed> Encrypted task-local authorization, never an API result. */
    public function authorize(Request $request, IdentityContext $identity, int $taskId, string $taskType, string $requestId): array
    {
        $headerRequestId = trim((string) $request->header('x-request-id', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $headerRequestId) === 1
            && !hash_equals($headerRequestId, $requestId)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Task request id changed');
        }
        return $this->authorizeSigned(
            trim((string) $request->header('authorization', '')), $request->getRemoteIp(),
            $identity, $taskId, $taskType, $requestId,
        );
    }

    /** @return array<string,mixed> Encrypted task-local authorization, never an API result. */
    public function authorizeSigned(string $authorizationHeader, string $sourceIp, IdentityContext $identity, int $taskId, string $taskType, string $requestId): array
    {
        $action = self::action($taskType);
        $cipher = $this->cipher();
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeSigned(
            $authorizationHeader, $sourceIp, $identity, $action, 'sand_ai.task_run', (string) $taskId,
            'task-run:' . bin2hex(random_bytes(16)), $requestId,
        );
        $binding = new QueuedSandIamTaskBinding(
            $taskId, $taskType, $decision['authorization_id'], $sourceIp,
            $requestId, $identity->expiresAt ?? 0,
        );
        return [
            'version' => 1,
            'task_type' => $taskType,
            'authorization_id' => $binding->authorizationId,
            'source_ip' => $binding->sourceIp,
            'request_id' => $binding->requestId,
            'expires_at' => $binding->expiresAt,
            'context' => $cipher->seal(trim(substr($authorizationHeader, 7)), $binding),
        ];
    }

    /**
     * @param array<string,mixed> $authorization
     * @return array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string}
     *         Request-local secret; never persist, log, or return through an API.
     */
    public function revalidateQueued(array $authorization, TaskRun $task): array
    {
        $taskType = (string) $task->task_type;
        $action = self::action($taskType);
        if (($authorization['version'] ?? null) !== 1
            || ($authorization['task_type'] ?? null) !== $taskType
            || !is_int($authorization['authorization_id'] ?? null)
            || !is_string($authorization['source_ip'] ?? null)
            || !is_string($authorization['request_id'] ?? null)
            || !is_int($authorization['expires_at'] ?? null)
            || !is_array($authorization['context'] ?? null)) {
            self::unavailable();
        }
        $binding = new QueuedSandIamTaskBinding(
            (int) $task->id, $taskType, $authorization['authorization_id'],
            $authorization['source_ip'], $authorization['request_id'], $authorization['expires_at'],
        );
        $signedContext = $this->cipher()->open($authorization['context'], $binding);
        try {
            $facts = TaskInvocationFacts::fromTask($task, $action);
        } catch (IdentityContextException) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Queued task resource facts are unavailable');
        }
        $identity = new IdentityContext(
            (string) $facts['organization_id'], (string) $facts['application_id'],
            $facts['environment_id'], (string) $facts['workload_client_id'],
            'sand-ai', [$action], $binding->expiresAt,
        );
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            $binding->authorizationId, $signedContext, $identity,
            $action, 'sand_ai.task_run', (string) $binding->taskId,
            $binding->sourceIp, $binding->requestId,
        );
        return [
            'signed_context' => $signedContext,
            'identity' => $identity,
            'source_ip' => $binding->sourceIp,
            'request_id' => $binding->requestId,
        ];
    }

    public function authorizeRead(Request $request, IdentityContext $identity, TaskRun $task, string $requestId): void
    {
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, self::action((string) $task->task_type),
            'sand_ai.task_run', (string) $task->id,
            'task-read:' . bin2hex(random_bytes(16)), $requestId,
        );
    }

    public function authorizeReadSigned(string $authorizationHeader, string $sourceIp, IdentityContext $identity, TaskRun $task, string $requestId): void
    {
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeSigned(
            $authorizationHeader, $sourceIp, $identity, self::action((string) $task->task_type),
            'sand_ai.task_run', (string) $task->id,
            'task-read:' . bin2hex(random_bytes(16)), $requestId,
        );
    }

    private static function action(string $taskType): string
    {
        return match ($taskType) {
            'ai_completion' => 'sand_ai.chat.complete',
            'agent_run' => 'sand_ai.agent.submit',
            default => self::unavailable(),
        };
    }

    private function cipher(): QueuedSandIamContextCipher
    {
        return $this->cipher ?? new QueuedSandIamContextCipher((array) config('sand_ai_iam_context', []));
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued task identity context is unavailable');
    }
}
