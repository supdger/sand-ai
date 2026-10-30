<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;

/** Generates the only accepted task lease owner representation before a task is claimed. */
final class TaskWorkerIdentity
{
    public static function forProcess(string|false $hostname, int $pid, ?string $suffix = null): string
    {
        if ($pid < 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task worker process identity is invalid');
        }
        $name = strtolower(is_string($hostname) ? $hostname : '');
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $name = trim($name, '-');
        $name = $name === '' ? 'sand-ai' : $name;
        $suffix ??= bin2hex(random_bytes(6));
        if (preg_match('/\A[a-f0-9]{12}\z/', $suffix) !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task worker uniqueness suffix is invalid');
        }
        $tail = ':' . $pid . ':' . $suffix;
        $name = substr($name, 0, 128 - strlen($tail));
        $owner = rtrim($name, '-') . $tail;
        self::assertValid($owner);
        return $owner;
    }

    public static function assertValid(string $owner): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9:_-]{0,127}\z/', $owner) !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task worker identity is invalid');
        }
    }
}
