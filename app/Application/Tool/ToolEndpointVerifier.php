<?php

declare(strict_types=1);

namespace app\Application\Tool;

interface ToolEndpointVerifier
{
    public function verify(mixed $origin, mixed $path): VerifiedToolEndpoint;
}
