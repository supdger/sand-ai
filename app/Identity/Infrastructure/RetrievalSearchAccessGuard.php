<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use support\Request;

/** Verifies the current retrieval identity without inventing source classification. */
final class RetrievalSearchAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamIdentityContextProvider $identityContexts = null,
    ) {
    }

    public function verifyCurrent(Request $request, IdentityContext $identity): void
    {
        try {
            $current = ($this->identityContexts ?? new HostSandIamIdentityContextProvider())
                ->requireContext($request, 'sand_ai.retrieval.search');
            if ($current->organizationId !== $identity->organizationId
                || $current->applicationId !== $identity->applicationId
                || $current->environmentId !== $identity->environmentId
                || $current->workloadClientId !== $identity->workloadClientId
                || $current->audience !== $identity->audience) {
                throw new IdentityContextException('SAND_AI_INVOCATION_SCOPE_FORBIDDEN');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }
}
