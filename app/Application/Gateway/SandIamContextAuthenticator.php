<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Contract\IdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use support\Request;

/** SandIAM owns caller, credential, grant, application and environment checks. */
final class SandIamContextAuthenticator
{
    public function __construct(private readonly ?IdentityContextProvider $identityContexts = null)
    {
    }

    /** @return array{context: IdentityContext, environment_id: int} */
    public function authenticate(Request $request, string $serviceAction): array
    {
        try {
            $context = ($this->identityContexts ?? $this->hostProvider())
                ->requireContext($request, $serviceAction)
                ->requireAction($serviceAction);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }

        return ['context' => $context, 'environment_id' => $context->environmentId];
    }

    private function hostProvider(): IdentityContextProvider
    {
        return new HostSandIamIdentityContextProvider();
    }
}
