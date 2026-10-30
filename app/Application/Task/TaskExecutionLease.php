<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Trusted claim handed only from TaskGateway to a fenced executor. */
final readonly class TaskExecutionLease
{
    public function __construct(public int $taskId, public string $owner, public string $token, public int $fence)
    {
        if ($taskId < 1 || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 || $fence < 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task execution lease is invalid');
        }
        TaskWorkerIdentity::assertValid($owner);
    }

    public static function fromTask(TaskRun $task): self
    {
        return new self((int) $task->id, (string) $task->lease_owner, (string) $task->lease_token, (int) $task->lease_fence);
    }
}
