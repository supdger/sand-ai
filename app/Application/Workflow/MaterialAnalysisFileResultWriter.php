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
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Persists a validated single-file result and its source links atomically. */
final class MaterialAnalysisFileResultWriter
{
    public function __construct(private readonly MaterialAnalysisFileResultPolicy $policy = new MaterialAnalysisFileResultPolicy())
    {
    }

    /**
     * @param array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} $manifest
     */
    public function store(
        TaskExecutionLease $lease,
        int $workflowRunId,
        int $workflowRunFileId,
        int $invocationId,
        MaterialAnalysisPrompt $prompt,
        array $manifest,
        bool $recovery = false,
        ?array $chunkPlan = null,
    ): int {
        if ($workflowRunId < 1 || $workflowRunFileId < 1 || $invocationId < 1) {
            self::invalid();
        }
        return Db::connect('pgsql')->transaction(function () use (
            $lease, $workflowRunId, $workflowRunFileId, $invocationId, $prompt, $manifest, $recovery, $chunkPlan,
        ): int {
            // Keep the same lock order as StructuredArtifactWriter.
            $invocation = Invocation::where('id', $invocationId)->lock(true)->find();
            $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->lock(true)->find();
            $usageEvent = UsageEvent::where('invocation_id', $invocationId)->find();
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $run = WorkflowRun::where('id', $workflowRunId)->lock(true)->find();
            $runFile = WorkflowRunFile::where('id', $workflowRunFileId)->where('workflow_run_id', $workflowRunId)
                ->lock(true)->find();
            if ($invocation === null || $dispatch === null || $usageEvent === null
                || $task === null || $step === null
                || $run === null || $runFile === null
                || (string) $run->workflow_code !== 'material_analysis'
                || (string) $run->output_schema_code !== 'sand_ai.material_analysis'
                || (string) $run->output_schema_version !== '1.0.0'
                || (int) $run->task_id !== (int) $task->id
                || (int) $run->environment_id !== (int) $task->environment_id
                || (string) $task->state !== ($recovery ? TaskState::RECOVERY_PENDING : TaskState::RUNNING)
                || (string) $step->state !== ($recovery ? TaskState::FAILED : TaskState::RUNNING)
                || (string) $task->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $task->lease_token)
                || (int) $task->lease_fence !== $lease->fence
                || (int) $run->status !== 1 || $run->delete_time !== null
                || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                || !in_array((string) $runFile->analysis_state, $recovery ? ['recovery_pending', 'succeeded'] : ['running', 'succeeded'], true)
                || (int) $runFile->attempt_count < 1) {
                self::invalid();
            }
            self::assertUnexpiredLease($task, $recovery);
            $blocks = SourceBlock::where('file_id', (int) $runFile->file_id)
                ->where('parse_id', (int) $runFile->parse_id)->where('status', 1)->order('sequence_no')->order('id')->lock(true)->select();
            $currentSourceIds = [];
            $sourceBlocks = [];
            foreach ($blocks as $block) {
                $currentSourceIds[] = (int) $block->id;
                $sourceBlocks[] = ['id' => (int) $block->id, 'content' => (string) $block->content,
                    'content_sha256' => (string) $block->content_sha256, 'locator_type' => (string) $block->locator_type,
                    'locator' => is_object($block->locator) ? (array) $block->locator : $block->locator];
                if (!hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) self::invalid();
            }
            $invocationAttempt = $chunkPlan === null ? (int) $runFile->attempt_count
                : (new MaterialChunkCoverage())->assert($run, $task, $runFile, $chunkPlan, [
                    'file_id' => (int) $runFile->file_id, 'parse_id' => (int) $runFile->parse_id,
                    'sha256' => (string) $runFile->sha256, 'version_ref' => (string) $runFile->version_ref, 'blocks' => $sourceBlocks,
                ], $manifest, $invocationId);
            $prepared = $this->policy->prepare(
                [
                    'environment_id' => (int) $run->environment_id,
                    'organization_id' => (int) $run->organization_id,
                    'application_id' => (int) $run->application_id,
                    'workload_client_id' => (int) $run->workload_client_id,
                    'workflow_run_id' => (int) $run->id,
                    'workflow_run_file_id' => (int) $runFile->id,
                    'file_id' => (int) $runFile->file_id,
                    'parse_id' => (int) $runFile->parse_id,
                    'task_id' => (int) $task->id,
                    'step_id' => (int) $step->id,
                    'attempt_no' => $invocationAttempt,
                    'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                    'logical_execution_id' => (string) $run->logical_execution_id,
                ],
                [
                    'id' => (int) $invocation->id,
                    'environment_id' => (int) $invocation->environment_id,
                    'organization_id' => (int) $invocation->organization_id,
                    'application_id' => (int) $invocation->application_id,
                    'workload_client_id' => (int) $invocation->workload_client_id,
                    'parent_workflow_run_id' => (int) $invocation->parent_workflow_run_id,
                    'parent_task_run_id' => (int) $invocation->parent_task_run_id,
                    'parent_task_step_id' => (int) $invocation->parent_task_step_id,
                    'parent_workflow_run_file_id' => (int) $invocation->parent_workflow_run_file_id,
                    'execution_attempt_no' => (int) $invocation->execution_attempt_no,
                    'execution_snapshot_sha256' => (string) $invocation->execution_snapshot_sha256,
                    'logical_execution_id' => (string) $invocation->logical_execution_id,
                    'request_id' => (string) $invocation->request_id,
                    'model_id' => (int) $invocation->model_id,
                    'deployment_id' => (int) $invocation->deployment_id,
                    'state' => (string) $invocation->state,
                    'status' => (int) $invocation->status,
                    'delete_time' => $invocation->delete_time,
                ],
                [
                    'invocation_id' => (int) $dispatch->invocation_id,
                    'environment_id' => (int) $dispatch->environment_id,
                    'parent_workflow_run_id' => (int) $dispatch->parent_workflow_run_id,
                    'parent_task_run_id' => (int) $dispatch->parent_task_run_id,
                    'parent_task_step_id' => (int) $dispatch->parent_task_step_id,
                    'parent_workflow_run_file_id' => (int) $dispatch->parent_workflow_run_file_id,
                    'attempt_no' => (int) $dispatch->attempt_no,
                    'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
                    'logical_execution_id' => (string) $dispatch->logical_execution_id,
                    'model_id' => (int) $dispatch->model_id,
                    'deployment_id' => (int) $dispatch->deployment_id,
                    'capability_code' => (string) $dispatch->capability_code,
                    'state' => (string) $dispatch->state,
                ],
                [
                    'invocation_id' => (int) $usageEvent->invocation_id,
                    'environment_id' => (int) $usageEvent->environment_id,
                    'organization_id' => (int) $usageEvent->organization_id,
                    'application_id' => (int) $usageEvent->application_id,
                    'workload_client_id' => (int) $usageEvent->workload_client_id,
                    'model_id' => (int) $usageEvent->model_id,
                    'deployment_id' => (int) $usageEvent->deployment_id,
                    'service_action' => (string) $usageEvent->service_action,
                    'source_kind' => (string) $usageEvent->source_kind,
                    'capability_code' => (string) $usageEvent->capability_code,
                    'idempotency_key' => (string) $usageEvent->idempotency_key,
                ],
                $manifest,
                $prompt->sourceBlockIds,
                $currentSourceIds,
                $chunkPlan !== null,
            );
            $existing = WorkflowFileResult::where('workflow_run_file_id', $workflowRunFileId)->lock(true)->find();
            if ($existing !== null) {
                $existingPayload = MaterialAnalysisFilePayloadDigest::verified(
                    $existing->payload, (string) $existing->payload_sha256,
                    $existing->payload_digest_version, $existing->content_purged_time,
                );
                if ((string) $runFile->analysis_state !== 'succeeded'
                    || (int) $runFile->last_invocation_id !== $invocationId
                    || (int) $existing->invocation_id !== $invocationId
                    || (int) $existing->attempt_no !== (int) $runFile->attempt_count
                    || (string) $existing->schema_code !== 'sand_ai.material_analysis'
                    || (string) $existing->schema_version !== '1.0.0'
                    || !hash_equals((string) $existing->execution_snapshot_sha256, (string) $run->execution_snapshot_sha256)
                    || !hash_equals(MaterialAnalysisFilePayloadDigest::version2($existingPayload), $prepared['payload_sha256'])
                    || (int) $existing->field_source_count !== $prepared['field_source_count']) {
                    self::invalid();
                }
                $persistedSources = [];
                foreach (WorkflowFileResultSource::where('result_id', (int) $existing->id)->order('field_path')
                    ->order('source_block_id')->lock(true)->select() as $source) {
                    $persistedSources[] = [
                        'field_path' => (string) $source->field_path,
                        'source_block_id' => (int) $source->source_block_id,
                    ];
                }
                if ($persistedSources !== $prepared['source_rows']) {
                    self::invalid();
                }
                self::assertUnexpiredLease($task, $recovery);
                $this->acknowledgeReceipt($invocationId, (int) $existing->id);
                self::assertUnexpiredLease($task, $recovery);
                return (int) $existing->id;
            }
            $expectedFileState = $recovery ? 'recovery_pending' : 'running';
            if ((string) $runFile->analysis_state !== $expectedFileState) {
                self::invalid();
            }
            self::assertUnexpiredLease($task, $recovery);
            $result = WorkflowFileResult::create([
                'workflow_run_file_id' => $workflowRunFileId,
                'attempt_no' => (int) $runFile->attempt_count,
                'invocation_id' => $invocationId,
                'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                'schema_code' => 'sand_ai.material_analysis',
                'schema_version' => '1.0.0',
                'payload' => $prepared['payload'],
                'payload_sha256' => $prepared['payload_sha256'],
                'payload_digest_version' => $prepared['payload_digest_version'],
                'field_source_count' => $prepared['field_source_count'],
            ]);
            foreach ($prepared['source_rows'] as $source) {
                WorkflowFileResultSource::create([
                    'result_id' => (int) $result->id,
                    'field_path' => $source['field_path'],
                    'source_block_id' => $source['source_block_id'],
                ]);
            }
            if (WorkflowRunFile::where('id', $workflowRunFileId)
                ->where('analysis_state', $expectedFileState)
                ->where('attempt_count', (int) $runFile->attempt_count)
                ->update([
                    'analysis_state' => 'succeeded',
                    'failure_summary' => null,
                    'last_invocation_id' => $invocationId,
                ]) !== 1) {
                self::invalid();
            }
            $this->acknowledgeReceipt($invocationId, (int) $result->id);
            (new WorkflowStateRevision())->changed($workflowRunId);
            self::assertUnexpiredLease($task, $recovery);
            return (int) $result->id;
        });
    }

    private static function assertUnexpiredLease(TaskRun $task, bool $recovery): void
    {
        // The TaskRun lock holds owner/token/fence stable, but time advances
        // during later source, result and receipt locks. Every claim path —
        // RUNNING and receipt recovery alike — writes lease_expire_time with the
        // one trusted host clock date(). Never renew the lease while sealing a result.
        $expireTime = $task->lease_expire_time;
        $now = date('Y-m-d H:i:s');
        if (!is_string($expireTime)
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $expireTime) !== 1
            || $expireTime <= $now) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease expired before single-file result persistence');
        }
    }

    private function acknowledgeReceipt(int $invocationId, int $resultId): void
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt retention is not configured');
        }
        $now = gmdate('Y-m-d H:i:s');
        $retainUntil = OutcomeReceiptRetentionPolicy::fromConfig($config)->retainUntil(time());
        (new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(),
            new OutcomeReceiptCipher($config),
            (int) ($config['ttl_seconds'] ?? 0),
        ))->acknowledgeFileResult($invocationId, $resultId, $now, $retainUntil);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Single-file result changed before persistence');
    }
}
