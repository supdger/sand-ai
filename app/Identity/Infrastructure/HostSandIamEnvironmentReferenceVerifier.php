<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use Throwable;

final class HostSandIamEnvironmentReferenceVerifier implements EnvironmentReferenceVerifier
{
    public function __construct(private readonly ?object $hostVerifier = null)
    {
    }

    public function requireEnvironment(int $environmentId): void
    {
        if ($environmentId <= 0) {
            throw new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN');
        }
        $host = $this->hostVerifier ?? HostSandIamRuntime::environmentReferenceVerifier();
        if ($host === null || !method_exists($host, 'verifyEnvironmentReferenceForClient')) {
            throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
        }
        try {
            $reference = $host->verifyEnvironmentReferenceForClient($environmentId);
        } catch (Throwable $exception) {
            throw HostSandIamErrorMapper::toIdentityException($exception);
        }
        if (!is_array($reference) || (int) ($reference['environment_id'] ?? 0) !== $environmentId || (int) ($reference['status'] ?? 0) !== 1) {
            throw new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN');
        }
    }
}
