<?php

declare(strict_types=1);

namespace app\Identity\Contract;

use support\Request;

interface IdentityContextProvider
{
    /** @throws IdentityContextException when SandIAM rejects the context. */
    public function requireContext(Request $request, string $serviceAction): IdentityContext;
}
