<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowRun;

/** Binds a public task operation to the persisted workflow caller. */
final class StructuredTaskAccessGuard
{
    /** @param null|\Closure(int):?WorkflowRun $loadRun */
    public function __construct(private readonly ?\Closure $loadRun = null)
    {
    }

    public function assert(TaskRun $task, ?string $currentScope): void
    {
        if ((string) $task->task_type !== 'structured_workflow') {
            return;
        }
        if (!is_string($currentScope) || preg_match('/\A[a-f0-9]{64}\z/', $currentScope) !== 1
            || (string) $task->resource_type !== 'workflow_run' || (int) $task->resource_id < 1) {
            $this->notFound();
        }
        $run = $this->loadRun === null
            ? WorkflowRun::where('id', (int) $task->resource_id)->where('environment_id', (int) $task->environment_id)
                ->where('task_id', (int) $task->id)->where('status', 1)->whereNull('delete_time')->find()
            : ($this->loadRun)((int) $task->resource_id);
        if (!$run instanceof WorkflowRun || (int) $run->id !== (int) $task->resource_id
            || (int) $run->environment_id !== (int) $task->environment_id
            || (int) $run->task_id !== (int) $task->id || (int) $run->status !== 1
            || $run->delete_time !== null) {
            $this->notFound();
        }
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($snapshot) ? $snapshot : null,
                (int) $run->environment_id,
                'structured_workflow',
                'sand_ai.workflow.run',
            );
        } catch (\InvalidArgumentException) {
            $this->notFound();
        }
        if ($attribution->organizationId === null || $attribution->applicationId === null
            || $attribution->workloadClientId === null
            || $attribution->organizationId !== (int) $run->organization_id
            || $attribution->applicationId !== (int) $run->application_id
            || $attribution->workloadClientId !== (int) $run->workload_client_id
            || !hash_equals(RequestIdempotencyKey::trustedScopeForAttribution($attribution), $currentScope)) {
            $this->notFound();
        }
    }

    private function notFound(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
    }
}
