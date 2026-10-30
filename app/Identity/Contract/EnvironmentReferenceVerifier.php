<?php

declare(strict_types=1);

namespace app\Identity\Contract;

interface EnvironmentReferenceVerifier
{
    /** @throws IdentityContextException when SandIAM rejects the reference. */
    public function requireEnvironment(int $environmentId): void;
}
