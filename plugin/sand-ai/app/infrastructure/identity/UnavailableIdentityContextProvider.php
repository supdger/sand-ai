<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\identity;

use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use support\Request;

/** Secure default before a host-specific SandIAM adapter is installed. */
final class UnavailableIdentityContextProvider implements IdentityContextProvider
{
    public function requireContext(Request $request, string $serviceAction): IdentityContext
    {
        throw new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    }
}
