<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Authorizes a read, cancellation, or retry against the persisted task ID. */
final class TaskOperationAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, int $taskId, string $action, string $requestId): array
    {
        return ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, $action, 'sand_ai.task_operation', (string) $taskId,
            'task-operation:' . bin2hex(random_bytes(16)), $requestId,
        );
    }

    /** @return array<string,mixed> */
    public function authorizeSigned(string $authorizationHeader, string $sourceIp, IdentityContext $identity, int $taskId, string $action, string $requestId): array
    {
        return ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeSigned(
            $authorizationHeader, $sourceIp, $identity, $action, 'sand_ai.task_operation', (string) $taskId,
            'task-operation:' . bin2hex(random_bytes(16)), $requestId,
        );
    }

    /** @param array<string,mixed> $decision */
    public function revalidate(Request $request, IdentityContext $identity, int $taskId, string $action, string $requestId, array $decision): void
    {
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) $signedContext = trim(substr($signedContext, 7));
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity, $action,
            'sand_ai.task_operation', (string) $taskId, $request->getRemoteIp(), $requestId,
        );
    }

    /** @param array<string,mixed> $decision */
    public function revalidateSigned(string $signedContext, string $sourceIp, IdentityContext $identity, int $taskId, string $action, string $requestId, array $decision): void
    {
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity, $action,
            'sand_ai.task_operation', (string) $taskId, $sourceIp, $requestId,
        );
    }
}
