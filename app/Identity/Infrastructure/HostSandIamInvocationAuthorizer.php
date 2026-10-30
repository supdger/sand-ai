<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use support\Request;
use Throwable;

/**
 * Adapts SandIAM 0.7.3's host-local invocation port.
 * The resource key must identify a server-owned, persisted resource resolved
 * by a registered SandAI fact resolver; it is never a request-body fact set.
 */
final class HostSandIamInvocationAuthorizer
{
    private ?HttpStreamAuthorizationScope $httpStreamScope = null;

    public function __construct(private readonly ?object $hostAuthorizer = null)
    {
    }

    /** Allocate exclusively inside one HTTP stream handler; never share between requests. */
    public static function forHttpStream(Request $request, string $requestId): self
    {
        $authorizer = new self();
        $authorizer->httpStreamScope = new HttpStreamAuthorizationScope(
            HttpStreamAuthorizationScope::requestBinding(
                trim((string) $request->header('authorization', '')), $request->getRemoteIp(), $requestId,
            ),
        );
        return $authorizer;
    }

    /** @return array<string,mixed> */
    public function authorize(
        Request $request,
        IdentityContext $identity,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $operationId,
        string $requestId,
    ): array {
        $signedContext = trim((string) $request->header('authorization', ''));
        $headerRequestId = trim((string) $request->header('x-request-id', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $headerRequestId) === 1
            && !hash_equals($headerRequestId, $requestId)) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }
        return $this->authorizeSigned($signedContext, $request->getRemoteIp(), $identity, $requiredAction,
            $resolverCode, $resourceKey, $operationId, $requestId);
    }

    /** @return array<string,mixed> */
    public function authorizeSigned(
        string $authorizationHeader,
        string $sourceIp,
        IdentityContext $identity,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $operationId,
        string $requestId,
    ): array {
        if ($this->httpStreamScope !== null) {
            $header = trim($authorizationHeader);
            $resource = $this->httpStreamScope->resource(
                $header, $sourceIp, $requestId, $identity, $requiredAction, $resolverCode, $resourceKey,
            );
            $authorizationId = $this->httpStreamScope->authorizationId($resource);
            if ($authorizationId !== null) {
                if (!str_starts_with($header, 'Bearer ') || trim(substr($header, 7)) === '') {
                    throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED'));
                }
                return $this->revalidate(
                    $authorizationId, trim(substr($header, 7)), $identity, $requiredAction,
                    $resolverCode, $resourceKey, $sourceIp, $requestId,
                );
            }
            // The ordinary adapter still performs every first-authorization check.
            $decision = (new self($this->hostAuthorizer))->authorizeSigned(
                $authorizationHeader, $sourceIp, $identity, $requiredAction, $resolverCode,
                $resourceKey, $operationId, $requestId,
            );
            $this->httpStreamScope->remember($resource, $decision['authorization_id']);
            return $decision;
        }
        try {
            $identity->requireAction($requiredAction);
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
            $signedContext = trim($authorizationHeader);
            if (!str_starts_with($signedContext, 'Bearer ') || trim(substr($signedContext, 7)) === '') {
                throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
            }
            if ($sourceIp === '0.0.0.0' || $sourceIp === '::' || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $host = $this->hostAuthorizer ?? HostSandIamRuntime::invocationAuthorizer();
            if ($host === null || !method_exists($host, 'authorizeInvocation')) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $decision = $host->authorizeInvocation(
                trim(substr($signedContext, 7)),
                'sand_ai',
                'sand-ai',
                $requiredAction,
                $resolverCode,
                $resourceKey,
                $sourceIp,
                $operationId,
                self::invocationAuditRequestId($requestId),
            );
            // Initial authorization may wait on the same live IAM row locks
            // as revalidation. Its result cannot extend the original context.
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        } catch (Throwable $exception) {
            throw IdentityContextProblem::from(HostSandIamErrorMapper::toIdentityException($exception));
        }

        return $this->verifiedDecision($decision, $identity, $requiredAction, $operationId);
    }

    /** @return array<string,mixed> */
    public function revalidate(
        int $authorizationId,
        string $signedContext,
        IdentityContext $identity,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $sourceIp,
        string $requestId,
    ): array {
        try {
            $identity->requireAction($requiredAction);
            if ($authorizationId < 1 || $signedContext === '') {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
            if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::'
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $host = $this->hostAuthorizer ?? HostSandIamRuntime::invocationAuthorizer();
            if ($host === null || !method_exists($host, 'revalidateInvocation')) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $decision = $host->revalidateInvocation(
                $authorizationId,
                $signedContext,
                'sand_ai',
                'sand-ai',
                $requiredAction,
                $resolverCode,
                $resourceKey,
                $sourceIp,
                self::invocationAuditRequestId($requestId),
            );
            // Host-local row locks may wait past the original context expiry.
            // A successful decision cannot extend that context's lifetime.
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        } catch (Throwable $exception) {
            throw IdentityContextProblem::from(HostSandIamErrorMapper::toIdentityException($exception));
        }

        $verified = $this->verifiedDecision($decision, $identity, $requiredAction);
        if ($verified['authorization_id'] !== $authorizationId) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }
        return $verified;
    }

    /** @return array<string,mixed> A new authorization for a resource found during queued execution. */
    public function authorizeQueued(
        string $signedContext,
        IdentityContext $identity,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $sourceIp,
        string $requestId,
    ): array {
        $operationId = 'queued-source:' . bin2hex(random_bytes(16));
        try {
            $identity->requireAction($requiredAction);
            if ($signedContext === '') {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
            if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::'
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1
                || $resourceKey === '') {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $host = $this->hostAuthorizer ?? HostSandIamRuntime::invocationAuthorizer();
            if ($host === null || !method_exists($host, 'authorizeInvocation')) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $decision = $host->authorizeInvocation(
                $signedContext, 'sand_ai', 'sand-ai', $requiredAction,
                $resolverCode, $resourceKey, $sourceIp, $operationId, self::invocationAuditRequestId($requestId),
            );
            if ($identity->expiresAt === null || $identity->expiresAt <= time()) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        } catch (Throwable $exception) {
            throw IdentityContextProblem::from(HostSandIamErrorMapper::toIdentityException($exception));
        }
        return $this->verifiedDecision($decision, $identity, $requiredAction, $operationId);
    }

    /** @return array<string,mixed> */
    private function verifiedDecision(mixed $decision, IdentityContext $identity, string $requiredAction, ?string $operationId = null): array
    {
        if (!is_array($decision)
            || !is_int($decision['authorization_id'] ?? null) || $decision['authorization_id'] < 1
            || !is_int($decision['grant_id'] ?? null) || $decision['grant_id'] < 1
            || !is_string($decision['context_id'] ?? null) || $decision['context_id'] === ''
            || !is_bool($decision['replayed'] ?? null)
            || !array_key_exists('data_class', $decision)
            || ($decision['data_class'] !== null && (!is_string($decision['data_class']) || $decision['data_class'] === ''))
            || !is_string($decision['operation_id'] ?? null) || $decision['operation_id'] === ''
            || ($operationId !== null && $decision['operation_id'] !== $operationId)
            || ($decision['service_code'] ?? null) !== 'sand_ai'
            || ($decision['audience'] ?? null) !== 'sand-ai'
            || ($decision['action_code'] ?? null) !== $requiredAction
            || !$this->matchesScope($decision, $identity)
        ) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }

        return $decision;
    }

    /** @param array<string,mixed> $decision */
    private function matchesScope(array $decision, IdentityContext $identity): bool
    {
        foreach ([
            'organization_id' => $identity->organizationId,
            'application_id' => $identity->applicationId,
            'environment_id' => (string) $identity->environmentId,
            'workload_client_id' => $identity->workloadClientId,
        ] as $field => $expected) {
            $actual = $decision[$field] ?? null;
            if (!is_int($actual) || $actual < 1 || (string) $actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * SandIAM audits context.verify once per (request_id, action). Invocation
     * authorization verifies the context again, possibly many times in one
     * SandAI request. Keep the original request ID for SandAI's business
     * records and derive a distinct, traceable ID for each host-local call.
     */
    private static function invocationAuditRequestId(string $requestId): string
    {
        return substr($requestId, 0, 32) . ':ai:' . substr(hash('sha256', $requestId), 0, 12)
            . ':' . bin2hex(random_bytes(16));
    }
}
