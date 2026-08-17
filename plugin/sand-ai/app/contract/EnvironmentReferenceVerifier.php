<?php

declare(strict_types=1);

namespace plugin\SandAi\app\contract;

/**
 * SandIAM-owned environment reference validation for package administration.
 * The package stores only the opaque numeric reference and never creates a
 * parallel environment table.
 */
interface EnvironmentReferenceVerifier
{
    /** @throws IdentityContextException when SandIAM cannot verify the reference. */
    public function requireEnvironment(int $environmentId): void;
}
