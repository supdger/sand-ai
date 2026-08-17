<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\task;

/** Shared execution state machine for file and AI work; never business approval. */
final class TaskState
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const RETRYING = 'retrying';
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
