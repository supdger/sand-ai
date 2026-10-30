<?php

declare(strict_types=1);

namespace app\Application\Task;

final class TaskState
{
    public const AUTHORIZATION_PENDING = 'authorization_pending';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const RETRYING = 'retrying';
    public const RECOVERY_PENDING = 'recovery_pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    /** @return list<string> */
    public static function claimable(): array
    {
        return [self::QUEUED, self::RETRYING];
    }

    public static function terminal(string $state): bool
    {
        return in_array($state, [self::SUCCEEDED, self::FAILED, self::CANCELED], true);
    }

    public static function retryDelaySeconds(int $attempt): int
    {
        return min(300, max(1, 2 ** max(0, $attempt - 1)));
    }
}
