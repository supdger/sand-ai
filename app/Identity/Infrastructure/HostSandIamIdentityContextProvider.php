<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Contract\IdentityContextProvider;
use support\Request;
use Throwable;

/** SandAI main-app adapter for a verified SandIAM workload context. */
final class HostSandIamIdentityContextProvider implements IdentityContextProvider
{
    public function __construct(
        private readonly ?object $hostVerifier = null,
    ) {
    }

    public function requireContext(Request $request, string $serviceAction): IdentityContext
    {
        $header = trim((string) $request->header('authorization', ''));
        if (!str_starts_with($header, 'Bearer ') || trim(substr($header, 7)) === '') {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }
        return $this->requireSignedContext(
            trim(substr($header, 7)), $serviceAction, $request->getRemoteIp(),
            RequestAuditId::ensure($request),
        );
    }

    public function requireSignedContext(string $signedContext, string $serviceAction, string $sourceIp, string $requestId): IdentityContext
    {
        if ($signedContext === '') throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        try {
            $host = $this->hostVerifier ?? HostSandIamRuntime::identityContextProvider();
            if ($host === null || !method_exists($host, 'verifyForService')) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            if ($sourceIp === '0.0.0.0' || $sourceIp === '::' || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
                throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            }
            $payload = $host->verifyForService(
                $signedContext, 'sand_ai', 'sand-ai', $serviceAction, $sourceIp,
                self::verificationAuditRequestId($requestId),
            );
        } catch (Throwable $exception) {
            throw HostSandIamErrorMapper::toIdentityException($exception);
        }

        if (!is_array($payload)) {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }
        $actions = $payload['actions'] ?? null;
        $environmentId = filter_var($payload['environment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $organizationId = $this->stableId($payload['organization_id'] ?? null);
        $applicationId = $this->stableId($payload['application_id'] ?? null);
        $workloadClientId = $this->stableId($payload['workload_client_id'] ?? null);
        $audience = is_string($payload['audience'] ?? null) ? trim($payload['audience']) : '';
        $serviceCode = $payload['service_code'] ?? null;
        $expiresAt = $payload['exp'] ?? null;
        if (!is_array($actions) || !array_is_list($actions) || $environmentId === false || $organizationId === '' || $applicationId === '' || $workloadClientId === '' || $audience === '' || !is_string($serviceCode) || $serviceCode === '' || !is_int($expiresAt)) {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }
        if (!hash_equals('sand-ai', $audience) || !hash_equals('sand_ai', $serviceCode)) {
            throw new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN');
        }
        if ($expiresAt <= time()) {
            throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
        }
        foreach ($actions as $action) {
            if (!is_string($action) || $action === '') {
                throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
            }
        }

        $context = new IdentityContext($organizationId, $applicationId, $environmentId, $workloadClientId, $audience, array_values($actions), $expiresAt);
        if ($context->expiresAt === null || $context->expiresAt <= time()) {
            throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_EXPIRED');
        }

        return $context->requireAction($serviceAction);
    }

    private function stableId(mixed $value): string
    {
        return is_int($value) && $value > 0 ? (string) $value : '';
    }

    /**
     * SandIAM audits every context.verify under a unique (request_id, action).
     * Keep the caller's request ID for SandAI idempotency and use a traceable,
     * fresh audit ID for each verification, including retries of the same key.
     */
    private static function verificationAuditRequestId(string $requestId): string
    {
        return substr($requestId, 0, 32) . ':cv:' . substr(hash('sha256', $requestId), 0, 12)
            . ':' . bin2hex(random_bytes(16));
    }
}
