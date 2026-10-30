<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;

/**
 * Locks every worker-owned record immediately before an internal provider call.
 * It deliberately does not authorize SandIAM itself: without a real authorizer,
 * the gateway remains fail-closed after this consistency check.
 */
final class PreDispatchGuard
{
    /** @return array{0:TaskRun,1:TaskStep} */
    public function assertTaskLeaseLocked(InternalModelInvocationContext $context): array
    {
        $task = TaskRun::where('id', $context->taskRunId)->lock(true)->find();
        $step = TaskStep::where('id', $context->taskStepId)->where('task_id', $context->taskRunId)->lock(true)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task is no longer dispatchable');
        }
        $problem = TaskLeaseStatePolicy::problemCode((string) $task->state, $task->lease_owner === null ? null : (string) $task->lease_owner, $task->lease_token === null ? null : (string) $task->lease_token, $task->lease_fence === null ? null : (int) $task->lease_fence, $context);
        if ($problem === 'SAND_AI_TASK_LEASE_LOST') {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease changed before provider dispatch');
        }
        if ($problem !== '') { throw new ApiProblem($problem, 'Task is no longer dispatchable'); }
        if ($step === null || (string) $step->state !== 'running') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Task step is no longer dispatchable');
        }

        return [$task, $step];
    }

    public function assertCurrentLocked(InvocationDispatch $dispatch, TaskRun $task, TaskStep $step, InternalModelInvocationContext $context, UsageAttribution $attribution, Provider $provider, ModelDeployment $deployment, int $modelId, string $capabilityCode): void
    {
        // task and step were acquired before the configuration chain. Workflow
        // is deliberately the first workflow-owned lock after that chain.
        $workflow = WorkflowRun::where('id', $context->workflowRunId)->lock(true)->find();
        if ((string) $task->lease_owner !== $context->lease->owner || (string) $task->lease_token !== $context->lease->token || (int) $task->lease_fence !== $context->lease->fence) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease changed before provider dispatch'); }
        if ($workflow === null
                || (string) $dispatch->state !== 'prepared'
                || (string) $task->state !== 'running' || (string) $step->state !== 'running'
                || (int) $step->task_id !== $context->taskRunId
                || (int) $workflow->environment_id !== $attribution->environmentId
                || !hash_equals((string) $workflow->execution_snapshot_sha256, $context->executionSnapshotSha256)
                || (int) $dispatch->parent_workflow_run_id !== $context->workflowRunId || (int) $dispatch->parent_task_run_id !== $context->taskRunId
                || (int) $dispatch->parent_task_step_id !== $context->taskStepId
                || ($dispatch->parent_workflow_run_file_id === null ? null : (int) $dispatch->parent_workflow_run_file_id) !== $context->workflowRunFileId
                || (int) $dispatch->attempt_no !== $context->attemptNo
                || (string) $dispatch->workflow_node_code !== $context->workflowNodeCode || (string) $dispatch->execution_snapshot_sha256 !== $context->executionSnapshotSha256
                || (string) $dispatch->capability_code !== $capabilityCode || (int) $dispatch->provider_id !== (int) $provider->id
                || (int) $dispatch->deployment_id !== (int) $deployment->id || (int) $dispatch->model_id !== $modelId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Trusted dispatch context changed before provider call');
        }
        if ($context->singleFile) {
            $runFile = WorkflowRunFile::where('id', $context->workflowRunFileId)
                ->where('workflow_run_id', $context->workflowRunId)->lock(true)->find();
            if ($runFile === null || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                || (string) $runFile->analysis_state !== 'running'
                || (int) $runFile->attempt_count !== $context->attemptNo) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material file attempt changed before provider call');
            }
        } elseif ($context->crossFileReview) {
            $review = WorkflowCrossFileReview::where('workflow_run_id', $context->workflowRunId)
                ->lock(true)->find();
            if ($review === null || (string) $review->state !== 'running'
                || (int) $review->attempt_count !== $context->attemptNo
                || $review->last_invocation_id !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The cross-file review attempt changed before provider call');
            }
            $runFiles = WorkflowRunFile::where('workflow_run_id', $context->workflowRunId)
                ->order('id')->lock(true)->select();
            if (count($runFiles) < 2) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review needs sealed file results');
            }
            foreach ($runFiles as $runFile) {
                if ((int) $runFile->status !== 1 || $runFile->delete_time !== null
                    || (string) $runFile->analysis_state !== 'succeeded'
                    || (int) $runFile->attempt_count < 1
                    || $runFile->last_invocation_id === null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material file is not sealed for review');
                }
            }
        }
        $snapshot = is_object($workflow->attribution_snapshot) ? (array) $workflow->attribution_snapshot : $workflow->attribution_snapshot;
        try {
            $persisted = UsageAttribution::fromPersisted(is_array($snapshot) ? $snapshot : null, $attribution->environmentId, $attribution->sourceKind, $attribution->serviceAction);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow attribution snapshot is invalid');
        }
        if ($persisted->capabilityCode !== $capabilityCode || !hash_equals($persisted->applicationScope(), $context->applicationScope) || !hash_equals($attribution->applicationScope(), $context->applicationScope)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow attribution scope changed before provider call');
        }
    }
}
