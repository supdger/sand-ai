<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;

/** Formal SandIAM authorization for a persisted file and its queued parse. */
final class FileParseAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?QueuedSandIamContextCipher $cipher = null,
    ) {
    }

    /** @return array<string,mixed> Encrypted task input, never an API field. */
    public function authorize(Request $request, IdentityContext $identity, int $fileId, string $requestId): array
    {
        $cipher = $this->cipher();
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, 'sand_ai.document_parse', 'sand_ai.file', (string) $fileId,
            'file-parse:' . bin2hex(random_bytes(16)), $requestId,
        );
        $binding = new QueuedSandIamFileBinding(
            $fileId, $decision['authorization_id'], $request->getRemoteIp(), $requestId,
            $identity->expiresAt ?? 0,
        );
        $header = trim((string) $request->header('authorization', ''));
        return [
            'version' => 1,
            'authorization_id' => $binding->authorizationId,
            'source_ip' => $binding->sourceIp,
            'request_id' => $binding->requestId,
            'expires_at' => $binding->expiresAt,
            'context' => $cipher->seal(trim(substr($header, 7)), $binding),
        ];
    }

    /** @param array<string,mixed> $authorization */
    public function revalidateQueued(array $authorization, TaskRun $task): void
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || (string) $task->task_type !== 'file_parse'
            || (string) $task->resource_type !== 'file'
            || (int) ($input['file_id'] ?? 0) !== (int) $task->resource_id
            || (int) $task->resource_id < 1
            || ($authorization['version'] ?? null) !== 1
            || !is_int($authorization['authorization_id'] ?? null)
            || !is_string($authorization['source_ip'] ?? null)
            || !is_string($authorization['request_id'] ?? null)
            || !is_int($authorization['expires_at'] ?? null)
            || !is_array($authorization['context'] ?? null)) {
            self::unavailable();
        }
        $binding = new QueuedSandIamFileBinding(
            (int) $task->resource_id, $authorization['authorization_id'],
            $authorization['source_ip'], $authorization['request_id'], $authorization['expires_at'],
        );
        $signedContext = $this->cipher()->open($authorization['context'], $binding);
        try {
            $owner = UsageAttribution::fromCurrentPersisted(
                is_array($input['_usage_attribution'] ?? null) ? $input['_usage_attribution'] : null,
                (int) $task->environment_id, 'file_parse', 'sand_ai.document_parse',
            );
        } catch (\InvalidArgumentException) {
            self::unavailable();
        }
        if ($owner->organizationId === null || $owner->applicationId === null || $owner->workloadClientId === null) {
            self::unavailable();
        }
        $identity = new IdentityContext(
            (string) $owner->organizationId, (string) $owner->applicationId,
            (int) $task->environment_id, (string) $owner->workloadClientId,
            'sand-ai', ['sand_ai.document_parse'], $binding->expiresAt,
        );
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            $binding->authorizationId, $signedContext, $identity,
            'sand_ai.document_parse', 'sand_ai.file', (string) $binding->fileId,
            $binding->sourceIp, $binding->requestId,
        );
    }

    private function cipher(): QueuedSandIamContextCipher
    {
        return $this->cipher ?? new QueuedSandIamContextCipher((array) config('sand_ai_iam_context', []));
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
    }
}
