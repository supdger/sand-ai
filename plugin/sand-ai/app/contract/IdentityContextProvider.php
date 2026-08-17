<?php

declare(strict_types=1);

namespace plugin\SandAi\app\contract;

use support\Request;

interface IdentityContextProvider
{
    /** @throws IdentityContextException for invalid/missing SandIAM context. */
    public function requireContext(Request $request, string $serviceAction): IdentityContext;
}
