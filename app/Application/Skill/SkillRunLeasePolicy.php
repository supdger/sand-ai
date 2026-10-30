<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Stable claim/fence decisions; database mutation remains in SkillGateway. */
final class SkillRunLeasePolicy
{
    public const FINALIZER_MARGIN_SECONDS = 60;

    /** @return array{lease_token:string,lease_fence:int,lease_expires_at:string} */
    public static function initial(string $token, int $seconds, \DateTimeImmutable $now): array
    {
        return self::claim($token, $seconds, 1, $now);
    }

    /** @return array{lease_token:string,lease_fence:int,lease_expires_at:string} */
    public static function reclaim(string $token, int $seconds, int $previousFence, ?string $expiresAt, \DateTimeImmutable $now): array
    {
        if ($previousFence < 1 || $expiresAt === null || new \DateTimeImmutable($expiresAt) > $now) {
            throw new ApiProblem('SAND_AI_SKILL_RUN_IN_PROGRESS', 'Skill run is already being finalized');
        }
        return self::claim($token, $seconds, $previousFence + 1, $now);
    }

    /** @return array{lease_token:string,lease_fence:int,lease_expires_at:string} */
    private static function claim(string $token, int $seconds, int $fence, \DateTimeImmutable $now): array
    {
        if ($token === '' || $fence < 1 || $seconds < 61 || $seconds > 3660) {
            throw new ApiProblem('SAND_AI_SKILL_RUN_STATE_INVALID', 'Skill run lease is invalid');
        }
        return ['lease_token' => $token, 'lease_fence' => $fence, 'lease_expires_at' => $now->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s')];
    }

    public static function owns(string $token, int $fence, mixed $storedToken, mixed $storedFence): bool
    {
        return is_string($storedToken) && $token !== '' && hash_equals($storedToken, $token) && (int) $storedFence === $fence;
    }
}
