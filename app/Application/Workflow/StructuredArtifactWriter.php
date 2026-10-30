<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptRetentionPolicy;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\Artifact;
use app\Infrastructure\Persistence\Model\ArtifactFieldSource;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Commits a validated manifest. It never calls a Provider or ChatGateway. */
final class StructuredArtifactWriter
{
    /** @param null|\Closure():void $beforeCommit test seam; production leaves it null. */
    public function __construct(
        private readonly ?OutcomeReceiptService $receipts = null,
        private readonly ?\Closure $beforeCommit = null,
    )
    {
    }

    /**
     * @param array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} $manifest
     */
    public function commit(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $manifest, ?int $invocationId, StructuredArtifactCommitContext $context): int
    {
        return Db::connect('pgsql')->transaction(function () use ($run, $task, $lease, $manifest, $invocationId, $context): int {
            // Terminal dispatch uses invocation -> ledger -> task. Receipt recovery
            // keeps that prefix and only then locks workflow-owned rows.
            $invocation = $invocationId === null ? null : Invocation::where('id', $invocationId)->lock(true)->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->lock(true)->find();
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            $existing = Artifact::where('workflow_run_id', (int) $run->id)->lock(true)->find();

            $expectedTaskState = $context->recovery ? TaskState::RECOVERY_PENDING : TaskState::RUNNING;
            $expectedStepState = $context->recovery ? TaskState::FAILED : TaskState::RUNNING;
            if ($activeTask === null || $step === null || $lockedRun === null || count($runFiles) < 1
                || (int) $activeTask->id !== (int) $task->id || (int) $lockedRun->task_id !== (int) $task->id
                || (string) $activeTask->state !== $expectedTaskState || (string) $step->state !== $expectedStepState
                || (string) $activeTask->lease_owner !== $lease->owner || (string) $activeTask->lease_token !== $lease->token
                || (int) $activeTask->lease_fence !== $lease->fence) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease was lost before artifact persistence');
            }

            if ($invocation !== null) {
                $this->assertStructuredInvocation($lockedRun, $activeTask, $step, $runFiles[0], $invocation, $dispatch);
            } elseif (in_array((string) $lockedRun->workflow_code, ['material_analysis', 'legal_analysis'], true)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The structured analysis is missing its terminal invocation');
            }

            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)();
            }

            $reviewRequired = (bool) $lockedRun->review_required;
            if ((string) $lockedRun->workflow_code === 'material_analysis') {
                $reviewRequired = (new MaterialReviewRequirement())->required($reviewRequired, $manifest['payload']);
            } elseif ((string) $lockedRun->workflow_code === 'legal_analysis') {
                $reviewRequired = true;
            }
            if ($existing === null) {
                $artifact = Artifact::create([
                    'workflow_run_id' => (int) $lockedRun->id,
                    'schema_code' => (string) $lockedRun->output_schema_code,
                    'schema_version' => (string) $lockedRun->output_schema_version,
                    'payload' => $manifest['payload'],
                    'review_required' => $reviewRequired,
                    'state' => 'succeeded',
                    'status' => 1,
                ]);
                foreach ($manifest['field_sources'] as $fieldSource) {
                    ArtifactFieldSource::create([
                        'artifact_id' => (int) $artifact->id,
                        'field_path' => $fieldSource['field_path'],
                        'source_block_id' => $fieldSource['source_block_id'],
                    ]);
                }
            } else {
                $artifact = $existing;
                $persistedSources = [];
                foreach (ArtifactFieldSource::where('artifact_id', (int) $artifact->id)->order('id')->lock(true)->select() as $source) {
                    $persistedSources[] = ['field_path' => (string) $source->field_path, 'source_block_id' => (int) $source->source_block_id];
                }
                (new ArtifactManifestConsistencyPolicy())->assertExisting(
                    (string) $artifact->schema_code === (string) $lockedRun->output_schema_code ? (string) $artifact->schema_code : '',
                    (string) $artifact->schema_version === (string) $lockedRun->output_schema_version ? (string) $artifact->schema_version : '',
                    (string) $artifact->state,
                    (int) $artifact->status,
                    (bool) $artifact->review_required,
                    $reviewRequired,
                    $artifact->payload,
                    $manifest['payload'],
                    $manifest['field_sources'],
                    $persistedSources,
                );
            }

            $now = date('Y-m-d H:i:s');
            $fileValues = WorkflowRunFileState::forTask(TaskState::SUCCEEDED);
            if ($existing === null) {
                $fileValues['attempt_count'] = (int) $activeTask->attempt_count;
            }
            if ($invocation !== null) {
                $fileValues['last_invocation_id'] = (int) $invocation->id;
            }
            if (WorkflowRunFile::where('workflow_run_id', (int) $lockedRun->id)->update($fileValues) < 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Workflow files changed before artifact persistence');
            }

            $taskUpdated = TaskRun::where('id', $lease->taskId)->where('state', $expectedTaskState)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->update([
                    'state' => TaskState::SUCCEEDED, 'result' => ['workflow_run_id' => (int) $lockedRun->id, 'artifact_id' => (int) $artifact->id],
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'error_code' => null, 'error_summary' => null,
                    'completed_at' => $now, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', $expectedStepState)->update([
                'state' => TaskState::SUCCEEDED, 'error_code' => null, 'error_summary' => null, 'completed_at' => $now,
            ]);
            if ($taskUpdated !== 1 || $stepUpdated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task changed before artifact completion');
            }

            if ($invocation !== null) {
                $receiptConfig = config('sand_ai_outcome_receipt');
                if (!is_array($receiptConfig)) {
                    throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt retention is not configured');
                }
                $retainUntil = OutcomeReceiptRetentionPolicy::fromConfig($receiptConfig)->retainUntil(time());
                $receiptService = $this->receipts ?? new OutcomeReceiptService(
                    new PostgresOutcomeReceiptStore(),
                    new OutcomeReceiptCipher($receiptConfig),
                    (int) ($receiptConfig['ttl_seconds'] ?? 0),
                );
                $receiptService->acknowledgeArtifact((int) $invocation->id, (int) $artifact->id, $now, $retainUntil);
                if (WorkflowRun::where('id', (int) $lockedRun->id)->update([
                    'last_invocation_id' => (int) $invocation->id, 'consumer_ack_time' => $now,
                    'consumer_ack_ref' => 'artifact:' . (int) $artifact->id, 'retain_until' => $retainUntil,
                ]) !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Workflow receipt acknowledgement could not be persisted');
                }
            }

            AuditLog::create([
                'environment_id' => (int) $lockedRun->environment_id,
                'actor_type' => 'system', 'actor_ref' => $context->recovery ? 'outcome_receipt_recovery' : 'structured_workflow',
                'action' => $context->recovery ? 'workflow.recovered_from_receipt' : 'workflow.succeeded',
                'resource_type' => 'workflow_run', 'resource_id' => (int) $lockedRun->id,
                'summary' => $context->recovery ? 'Recovered structured artifact from encrypted outcome receipt' : 'Generated SandAI structured artifact',
                'context' => ['workflow_code' => (string) $lockedRun->workflow_code, 'review_required' => $reviewRequired, 'provider_called' => $context->providerCalled],
            ]);

            (new WorkflowStateRevision())->terminalChanged((int) $lockedRun->id, TaskState::SUCCEEDED);
            return (int) $artifact->id;
        });
    }

    private function assertStructuredInvocation(WorkflowRun $run, TaskRun $task, TaskStep $step, WorkflowRunFile $runFile, Invocation $invocation, ?InvocationDispatch $dispatch): void
    {
        if ($dispatch === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery context does not match the frozen workflow execution');
        }
        $expected = [
            'environment_id' => (int) $run->environment_id, 'parent_workflow_run_id' => (int) $run->id,
            'parent_task_run_id' => (int) $task->id, 'parent_task_step_id' => (int) $step->id,
            'parent_workflow_run_file_id' => (int) $runFile->id, 'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id, 'request_id' => 'structured_' . (string) $run->logical_execution_id,
            'logical_execution_id' => (string) $run->logical_execution_id, 'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
        ];
        $invocationRecord = ['id' => (int) $invocation->id, 'state' => (string) $invocation->state];
        $dispatchRecord = ['invocation_id' => (int) $dispatch->invocation_id, 'state' => (string) $dispatch->state, 'capability_code' => (string) $dispatch->capability_code];
        foreach (array_keys($expected) as $field) {
            $invocationRecord[$field] = $invocation->{$field};
            if ($field !== 'request_id') {
                $dispatchRecord[$field] = $dispatch->{$field};
            }
        }
        (new MaterialOutcomeRecoveryBindingPolicy())->assert($expected, $invocationRecord, $dispatchRecord);
    }
}
