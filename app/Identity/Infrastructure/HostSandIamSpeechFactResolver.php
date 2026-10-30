<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;

final class HostSandIamSpeechFactResolver implements ServiceInvocationFactResolver
{
    /** @return array<string,mixed> */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        return (new SpeechInvocationFacts())->resolve($serviceCode, $actionCode, $resourceKey);
    }
}
