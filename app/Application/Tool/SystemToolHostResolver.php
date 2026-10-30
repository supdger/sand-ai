<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Application\Onboarding\SystemHostResolver;

/** Reuse server-owned DNS pins; ToolEndpointPolicy still validates every address. */
final class SystemToolHostResolver implements ToolHostResolver
{
    public function resolve(string $host): array
    {
        return (new SystemHostResolver('SAND_AI_TOOL'))->resolve($host);
    }
}
