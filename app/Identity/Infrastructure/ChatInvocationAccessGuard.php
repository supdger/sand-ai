<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Reauthorizes each public chat attempt, including reads of old idempotent results. */
final class ChatInvocationAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, int $invocationId, string $requestId): array
    {
        return ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request,
            $identity,
            'sand_ai.chat.complete',
            'sand_ai.chat_invocation',
            (string) $invocationId,
            'chat-invocation:' . bin2hex(random_bytes(16)),
            $requestId,
        );
    }

    /** @param array<string,mixed> $decision */
    public function revalidate(
        Request $request, IdentityContext $identity, int $invocationId, string $requestId, array $decision,
    ): void {
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) {
            $signedContext = trim(substr($signedContext, 7));
        }
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity,
            'sand_ai.chat.complete', 'sand_ai.chat_invocation', (string) $invocationId,
            $request->getRemoteIp(), $requestId,
        );
    }
}
