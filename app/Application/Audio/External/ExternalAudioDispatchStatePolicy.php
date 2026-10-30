<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;

/** Monotonic durable lifecycle; no state can authorize a retry after provider visibility. */
final class ExternalAudioDispatchStatePolicy
{
    public const PREPARED = 'prepared'; public const DISPATCHING = 'dispatching'; public const ACCEPTED = 'accepted';
    public const SUCCEEDED = 'succeeded'; public const NOT_DISPATCHED = 'not_dispatched'; public const FAILED = 'failed';
    public const AMBIGUOUS = 'ambiguous'; public const RECOVERY_PENDING = 'recovery_pending';

    /** @return list<string> */
    public static function states(): array { return [self::PREPARED,self::DISPATCHING,self::ACCEPTED,self::SUCCEEDED,self::NOT_DISPATCHED,self::FAILED,self::AMBIGUOUS,self::RECOVERY_PENDING]; }
    public static function assertTransition(string $from, string $to): void
    {
        $allowed = [self::PREPARED => [self::DISPATCHING,self::NOT_DISPATCHED], self::DISPATCHING => [self::ACCEPTED,self::RECOVERY_PENDING,self::NOT_DISPATCHED,self::SUCCEEDED,self::FAILED,self::AMBIGUOUS], self::ACCEPTED => [self::RECOVERY_PENDING,self::SUCCEEDED,self::FAILED,self::AMBIGUOUS], self::RECOVERY_PENDING => [self::SUCCEEDED,self::FAILED,self::AMBIGUOUS]];
        if (!in_array($from, self::states(), true) || !in_array($to, $allowed[$from] ?? [], true)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_STATE_INVALID', 'External audio dispatch transition is invalid');
    }
    public static function maySend(string $state): bool { return $state === self::DISPATCHING; }
    public static function isTerminal(string $state): bool { return in_array($state, [self::SUCCEEDED,self::NOT_DISPATCHED,self::FAILED,self::AMBIGUOUS], true); }
    public static function usageKnown(string $state): bool { return in_array($state, [self::SUCCEEDED,self::FAILED], true); }
}
