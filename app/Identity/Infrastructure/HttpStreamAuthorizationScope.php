<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;

/** One HTTP stream only: retain authorization IDs, never decisions or resource facts. */
final class HttpStreamAuthorizationScope
{
    private ?string $identityBinding = null;

    /** @var array<string,int> */
    private array $authorizationIds = [];

    public function __construct(private readonly string $requestBinding)
    {
    }

    public static function requestBinding(string $header, string $sourceIp, string $requestId): string
    {
        return hash('sha256', json_encode([$header, $sourceIp, $requestId], JSON_THROW_ON_ERROR));
    }

    public function resource(
        string $header, string $sourceIp, string $requestId, IdentityContext $identity,
        string $action, string $resolver, string $resource,
    ): string {
        $binding = hash('sha256', json_encode([
            $identity->organizationId, $identity->applicationId, $identity->environmentId,
            $identity->workloadClientId, $identity->audience, $identity->expiresAt,
        ], JSON_THROW_ON_ERROR));
        if (!hash_equals($this->requestBinding, self::requestBinding($header, $sourceIp, $requestId))
            || ($this->identityBinding !== null && !hash_equals($this->identityBinding, $binding))) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }
        if ($this->identityBinding === null) {
            $this->identityBinding = $binding;
        }
        return hash('sha256', json_encode([$action, $resolver, $resource], JSON_THROW_ON_ERROR));
    }

    public function authorizationId(string $resource): ?int
    {
        return $this->authorizationIds[$resource] ?? null;
    }

    public function remember(string $resource, int $authorizationId): void
    {
        if ($authorizationId < 1 || isset($this->authorizationIds[$resource])) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }
        $this->authorizationIds[$resource] = $authorizationId;
    }
}
