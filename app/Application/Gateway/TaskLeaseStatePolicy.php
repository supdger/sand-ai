<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Pure precedence rule shared by the pre-dispatch worker fence. */
final class TaskLeaseStatePolicy
{
    public static function problemCode(?string $state, ?string $owner, ?string $token, ?int $fence, InternalModelInvocationContext $context): string
    {
        if ($owner !== $context->lease->owner || $token !== $context->lease->token || $fence !== $context->lease->fence) {
            return 'SAND_AI_TASK_LEASE_LOST';
        }
        return $state === 'running' ? '' : 'SAND_AI_SCOPE_SNAPSHOT_INVALID';
    }
}
