<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use support\Request;

/** Uses the persisted tool run as the SandIAM resource for dispatch and reads. */
final class ToolRunAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, int $runId, string $action, string $requestId, mixed $expectedDataClass): array
    {
        if (!is_string($expectedDataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $expectedDataClass) !== 1) {
            self::unverified();
        }
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, $action, 'sand_ai.tool_run', (string) $runId,
            'tool-run:' . bin2hex(random_bytes(16)), $requestId,
        );
        if (($decision['data_class'] ?? null) !== $expectedDataClass) self::unverified();
        return $decision;
    }

    /** @param array<string,mixed> $decision */
    public function revalidate(Request $request, IdentityContext $identity, int $runId, string $action, string $requestId, array $decision): void
    {
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) $signedContext = trim(substr($signedContext, 7));
        $verified = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity, $action,
            'sand_ai.tool_run', (string) $runId, $request->getRemoteIp(), $requestId,
        );
        if (($verified['data_class'] ?? null) !== ($decision['data_class'] ?? null)) self::unverified();
    }

    /**
     * Verify the original sealed parent context for the real tool action.
     * The returned secret is request local and must never be persisted.
     * @param array<string,mixed> $queued Decoded by TaskRunAccessGuard.
     */
    public function queuedIdentity(array $queued): IdentityContext
    {
        $parent = $queued['identity'] ?? null;
        if (!$parent instanceof IdentityContext || !is_string($queued['signed_context'] ?? null)
            || !is_string($queued['source_ip'] ?? null) || !is_string($queued['request_id'] ?? null)) self::unverified();
        try {
            $identity = (new HostSandIamIdentityContextProvider())->requireSignedContext(
                $queued['signed_context'], 'sand_ai.tool.run', $queued['source_ip'], $queued['request_id'],
            );
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        if ($identity->organizationId !== $parent->organizationId
            || $identity->applicationId !== $parent->applicationId
            || $identity->environmentId !== $parent->environmentId
            || $identity->workloadClientId !== $parent->workloadClientId
            || $identity->audience !== $parent->audience
            || $identity->expiresAt !== $parent->expiresAt) self::unverified();
        return $identity;
    }

    /** @param array<string,mixed> $queued @return array<string,mixed> */
    public function authorizeQueued(array $queued, IdentityContext $identity, int $runId, string $operationId, mixed $expectedDataClass): array
    {
        if ($runId < 1 || !is_string($expectedDataClass)
            || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $expectedDataClass) !== 1) self::unverified();
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeSigned(
            'Bearer ' . $queued['signed_context'], $queued['source_ip'], $identity,
            'sand_ai.tool.run', 'sand_ai.tool_run', (string) $runId, $operationId, $queued['request_id'],
        );
        if (($decision['data_class'] ?? null) !== $expectedDataClass) self::unverified();
        return $decision;
    }

    /** @param array<string,mixed> $queued @param array<string,mixed> $decision */
    public function revalidateQueued(array $queued, IdentityContext $identity, int $runId, array $decision): void
    {
        $verified = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $queued['signed_context'], $identity,
            'sand_ai.tool.run', 'sand_ai.tool_run', (string) $runId, $queued['source_ip'], $queued['request_id'],
        );
        if (($verified['data_class'] ?? null) !== ($decision['data_class'] ?? null)) self::unverified();
    }

    private static function unverified(): never
    {
        throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
    }
}
