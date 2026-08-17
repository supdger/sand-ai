<?php

use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\contract\EnvironmentReferenceVerifier;
use plugin\SandAi\app\infrastructure\identity\HostSandIamEnvironmentReferenceVerifier;
use plugin\SandAi\app\infrastructure\identity\HostSandIamIdentityContextProvider;

$container = new Webman\Container();
$container->addDefinitions([
    IdentityContextProvider::class => static function (): IdentityContextProvider {
        $audience = (string) config('plugin.sand-ai.app.identity.audience', 'sand-ai');

        return new HostSandIamIdentityContextProvider(null, $audience !== '' ? $audience : 'sand-ai');
    },
    EnvironmentReferenceVerifier::class => static fn (): EnvironmentReferenceVerifier => new HostSandIamEnvironmentReferenceVerifier(),
]);

return $container;
