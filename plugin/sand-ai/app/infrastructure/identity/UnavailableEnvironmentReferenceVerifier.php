<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\identity;

use plugin\SandAi\app\contract\EnvironmentReferenceVerifier;
use plugin\SandAi\app\contract\IdentityContextException;

/** Safe default until the host installs a SandIAM administration adapter. */
final class UnavailableEnvironmentReferenceVerifier implements EnvironmentReferenceVerifier
{
    public function requireEnvironment(int $environmentId): void
    {
        throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    }
}
