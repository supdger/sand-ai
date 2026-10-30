<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Reauthorizes public Skill dispatch and each persisted-result replay. */
final class SkillInvocationAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    public function authorize(Request $request, IdentityContext $identity, int $invocationId, string $requestId, string $action = 'sand_ai.skill.run'): void
    {
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request,
            $identity,
            $action,
            'sand_ai.skill_invocation',
            (string) $invocationId,
            'skill-invocation:' . bin2hex(random_bytes(16)),
            $requestId,
        );
    }
}
