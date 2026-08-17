<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\identity;

use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use support\Request;
use Throwable;

/**
 * Host SandIAM adapter for plugin-local `/api/sand-ai/v1/*` calls.
 *
 * It extracts a Bearer workload context, asks SandIAM to verify signature,
 * audience, grant and live credential/environment state, then maps the
 * payload onto SandAI's IdentityContext. Controllers still call
 * requireAction() locally. No API Key fallback exists.
 */
final class HostSandIamIdentityContextProvider implements IdentityContextProvider
{
    public function __construct(
        private readonly ?object $hostVerifier = null,
        private readonly string $expectedAudience = 'sand-ai',
    ) {
    }

    public function requireContext(Request $request, string $serviceAction): IdentityContext
    {
        $host = $this->hostVerifier ?? HostSandIamRuntime::identityContextProvider();
        if ($host === null || !method_exists($host, 'verify')) {
            throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
        }

        $context = $this->bearerContext($request);
        if ($context === '') {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }

        try {
            $payload = $host->verify($context, $this->expectedAudience, $serviceAction);
        } catch (IdentityContextException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw HostSandIamErrorMapper::toIdentityException($exception);
        }

        if (!is_array($payload)) {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }

        return $this->toIdentityContext($payload)->requireAction($serviceAction);
    }

    /** Reads the compact SandIAM context from the HTTP Authorization Bearer value. */
    private function bearerContext(Request $request): string
    {
        $header = trim((string) $request->header('authorization', ''));
        if ($header === '') {
            $header = trim((string) $request->header('Authorization', ''));
        }
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }

        return trim(substr($header, 7));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toIdentityContext(array $payload): IdentityContext
    {
        $actions = $payload['actions'] ?? null;
        if (!is_array($actions)) {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }

        $serviceActions = [];
        foreach ($actions as $action) {
            if (!is_string($action) || $action === '') {
                throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
            }
            $serviceActions[] = $action;
        }

        $environmentId = filter_var($payload['environment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $organizationId = $this->stableId($payload['organization_id'] ?? null);
        $applicationId = $this->stableId($payload['application_id'] ?? null);
        $workloadClientId = $this->stableId($payload['workload_client_id'] ?? null);
        $audience = is_string($payload['audience'] ?? null) ? trim($payload['audience']) : '';
        if ($environmentId === false || $organizationId === '' || $applicationId === '' || $workloadClientId === '' || $audience === '') {
            throw new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED');
        }

        return new IdentityContext(
            $organizationId,
            $applicationId,
            $environmentId,
            $workloadClientId,
            $audience,
            $serviceActions,
            $this->expiresAt($payload),
        );
    }

    private function stableId(mixed $value): string
    {
        if (is_int($value) || is_string($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /** @param array<string, mixed> $payload */
    private function expiresAt(array $payload): ?int
    {
        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            return (int) $payload['exp'];
        }
        if (isset($payload['expire_time']) && (is_string($payload['expire_time']) || is_numeric($payload['expire_time']))) {
            $parsed = strtotime((string) $payload['expire_time']);

            return $parsed === false ? null : $parsed;
        }

        return null;
    }
}
