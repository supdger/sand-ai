<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use Throwable;

final class HostSandIamEnvironmentFacts
{
    public function __construct(private readonly ?object $hostVerifier = null)
    {
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int} */
    public function resolve(int $environmentId): array
    {
        if ($environmentId < 1) self::unavailable();
        $host = $this->hostVerifier ?? HostSandIamRuntime::environmentReferenceVerifier();
        if ($host === null || !method_exists($host, 'verifyEnvironmentReferenceForClient')) self::unavailable();
        try {
            $reference = $host->verifyEnvironmentReferenceForClient($environmentId);
        } catch (Throwable $exception) {
            throw HostSandIamErrorMapper::toIdentityException($exception);
        }
        if (!is_array($reference) || ($reference['status'] ?? null) !== 1
            || ($reference['environment_id'] ?? null) !== $environmentId
            || !is_int($reference['organization_id'] ?? null) || $reference['organization_id'] < 1
            || !is_int($reference['application_id'] ?? null) || $reference['application_id'] < 1) {
            self::unavailable();
        }
        return [
            'organization_id' => $reference['organization_id'],
            'application_id' => $reference['application_id'],
            'environment_id' => $environmentId,
        ];
    }

    private static function unavailable(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
