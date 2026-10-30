<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;

/** Converts failed fenced TaskRun/TaskStep terminal updates into one stable worker outcome. */
final class TaskLeaseTerminalGuard
{
    public static function assertUpdated(int $taskRows, int $stepRows, string $phase): void
    {
        if ($taskRows !== 1 || $stepRows !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease was lost before ' . $phase);
        }
    }
}
