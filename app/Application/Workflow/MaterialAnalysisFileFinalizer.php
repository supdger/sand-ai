<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\Artifact;
use app\Infrastructure\Persistence\Model\ArtifactFieldSource;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewSource;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewWithheld;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Completes material runs from sealed file results and, for multiple files, a sealed review. */
final class MaterialAnalysisFileFinalizer
{
    public function __construct(private readonly MaterialAnalysisFileResultMerger $merger = new MaterialAnalysisFileResultMerger())
    {
    }

    public function commit(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, bool $recovery = false): int
    {
        return Db::connect('pgsql')->transaction(function () use ($run, $task, $lease, $recovery): int {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            if ($activeTask === null || $step === null || $lockedRun === null
                || (int) $activeTask->id !== (int) $task->id
                || (int) $lockedRun->task_id !== (int) $task->id
                || (int) $lockedRun->environment_id !== (int) $activeTask->environment_id
                || (string) $activeTask->state !== ($recovery ? TaskState::RECOVERY_PENDING : TaskState::RUNNING)
                || (string) $step->state !== ($recovery ? TaskState::FAILED : TaskState::RUNNING)
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || (string) $lockedRun->workflow_code !== 'material_analysis'
                || (string) $lockedRun->output_schema_code !== 'sand_ai.material_analysis'
                || (string) $lockedRun->output_schema_version !== '1.0.0'
                || (int) $lockedRun->status !== 1 || $lockedRun->delete_time !== null) {
                self::invalid();
            }
            $input = is_object($lockedRun->input_snapshot) ? (array) $lockedRun->input_snapshot : $lockedRun->input_snapshot;
            if (!is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
                self::invalid();
            }
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $lockedRun->id)->order('id')->lock(true)->select();
            if (count($runFiles) < 1) {
                self::invalid();
            }
            $fileIds = [];
            foreach ($runFiles as $runFile) {
                $fileIds[] = (int) $runFile->id;
            }
            $resultRows = WorkflowFileResult::whereIn('workflow_run_file_id', $fileIds)
                ->order('workflow_run_file_id')->lock(true)->select();
            if (count($resultRows) !== count($runFiles)) {
                self::invalid();
            }
            $byFile = [];
            foreach ($resultRows as $result) {
                $id = (int) $result->workflow_run_file_id;
                if (isset($byFile[$id])) {
                    self::invalid();
                }
                $byFile[$id] = $result;
            }
            $mergeInput = [];
            $duplicateFiles = [];
            $reviewPromptFiles = [];
            $lastInvocationId = null;
            foreach ($runFiles as $runFile) {
                $result = $byFile[(int) $runFile->id] ?? null;
                if ($result === null || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                    || (string) $runFile->analysis_state !== 'succeeded'
                    || (int) $runFile->attempt_count < 1
                    || (int) $runFile->last_invocation_id !== (int) $result->invocation_id
                    || (int) $runFile->attempt_count !== (int) $result->attempt_no
                    || !hash_equals((string) $lockedRun->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)
                    || (string) $result->schema_code !== 'sand_ai.material_analysis'
                    || (string) $result->schema_version !== '1.0.0') {
                    self::invalid();
                }
                $allowedIds = [];
                $reviewBlocks = [];
                foreach (SourceBlock::where('file_id', (int) $runFile->file_id)
                    ->where('parse_id', (int) $runFile->parse_id)->where('status', 1)->lock(true)->select() as $block) {
                    $allowedIds[(int) $block->id] = true;
                    if (count($runFiles) > 1) {
                        $content = (string) $block->content;
                        if (!hash_equals((string) $block->content_sha256, hash('sha256', $content))) {
                            self::invalid();
                        }
                        $reviewBlocks[] = [
                            'id' => (int) $block->id,
                            'file_id' => (int) $runFile->file_id,
                            'parse_id' => (int) $runFile->parse_id,
                            'content' => $content,
                            'content_sha256' => (string) $block->content_sha256,
                            'locator_type' => (string) $block->locator_type,
                            'locator' => is_object($block->locator) ? (array) $block->locator : (is_array($block->locator) ? $block->locator : []),
                        ];
                    }
                }
                if ($allowedIds === []) {
                    self::invalid();
                }
                $duplicateFiles[] = [
                    'file_id' => (int) $runFile->file_id,
                    'sha256' => (string) $runFile->sha256,
                    'source_block_ids' => array_keys($allowedIds),
                ];
                $sourceRows = [];
                foreach (WorkflowFileResultSource::where('result_id', (int) $result->id)
                    ->order('field_path')->order('source_block_id')->lock(true)->select() as $source) {
                    $sourceId = (int) $source->source_block_id;
                    if (!isset($allowedIds[$sourceId])) {
                        self::invalid();
                    }
                    $sourceRows[] = [
                        'field_path' => (string) $source->field_path,
                        'source_block_id' => $sourceId,
                    ];
                }
                if (count($sourceRows) !== (int) $result->field_source_count) {
                    self::invalid();
                }
                $payload = MaterialAnalysisFilePayloadDigest::verified(
                    $result->payload, (string) $result->payload_sha256,
                    $result->payload_digest_version, $result->content_purged_time,
                );
                $mergeInput[] = [
                    'workflow_run_file_id' => (int) $runFile->id,
                    'file_id' => (int) $runFile->file_id,
                    'payload' => $payload,
                    'field_sources' => $sourceRows,
                    'source_block_ids' => array_keys($allowedIds),
                ];
                if (count($runFiles) > 1) {
                    $reviewPromptFiles[] = [
                        'workflow_run_file_id' => (int) $runFile->id,
                        'file_id' => (int) $runFile->file_id,
                        'parse_id' => (int) $runFile->parse_id,
                        'payload' => $payload,
                        'field_sources' => $sourceRows,
                        'blocks' => $reviewBlocks,
                    ];
                }
                $lastInvocationId = (int) $result->invocation_id;
            }
            if (Artifact::where('workflow_run_id', (int) $lockedRun->id)->lock(true)->find() !== null) {
                self::invalid();
            }
            $reviewRequired = (bool) $lockedRun->review_required;
            if (count($runFiles) > 1) {
                $review = $this->sealedReview($lockedRun, $activeTask, $step, $input, $reviewPromptFiles);
                $manifest = (new MaterialCrossFileReviewedMerger())->merge($mergeInput, $review);
                $lastInvocationId = $review['invocation_id'];
            } else {
                $manifest = $this->merger->merge($mergeInput);
            }
            $manifest = (new MaterialDuplicateFileWarnings())->append($manifest, $duplicateFiles);
            $reviewRequired = (new MaterialReviewRequirement())->required($reviewRequired, $manifest['payload']);
            $artifact = Artifact::create([
                'workflow_run_id' => (int) $lockedRun->id,
                'schema_code' => (string) $lockedRun->output_schema_code,
                'schema_version' => (string) $lockedRun->output_schema_version,
                'payload' => $manifest['payload'],
                'review_required' => $reviewRequired,
                'state' => 'succeeded',
                'status' => 1,
            ]);
            foreach ($manifest['field_sources'] as $source) {
                ArtifactFieldSource::create([
                    'artifact_id' => (int) $artifact->id,
                    'field_path' => $source['field_path'],
                    'source_block_id' => $source['source_block_id'],
                ]);
            }
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('state', $recovery ? TaskState::RECOVERY_PENDING : TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->update([
                    'state' => TaskState::SUCCEEDED,
                    'result' => ['workflow_run_id' => (int) $lockedRun->id, 'artifact_id' => (int) $artifact->id],
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'error_code' => null, 'error_summary' => null,
                    'completed_at' => $now, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', $recovery ? TaskState::FAILED : TaskState::RUNNING)->update([
                'state' => TaskState::SUCCEEDED, 'error_code' => null, 'error_summary' => null, 'completed_at' => $now,
            ]);
            if ($updated !== 1 || $stepUpdated !== 1) {
                self::invalid();
            }
            if (WorkflowRun::where('id', (int) $lockedRun->id)->update(['last_invocation_id' => $lastInvocationId]) !== 1) {
                self::invalid();
            }
            AuditLog::create([
                'environment_id' => (int) $lockedRun->environment_id,
                'actor_type' => 'system', 'actor_ref' => $recovery ? 'outcome_receipt_recovery' : 'structured_workflow',
                'action' => 'workflow.succeeded', 'resource_type' => 'workflow_run',
                'resource_id' => (int) $lockedRun->id,
                'summary' => 'Generated SandAI material artifact from completed file results',
                'context' => ['workflow_code' => 'material_analysis', 'review_required' => $reviewRequired,
                    'provider_called' => !$recovery, 'from_sealed_file_results' => true,
                    'file_result_count' => count($runFiles)],
            ]);
            (new WorkflowStateRevision())->terminalChanged((int) $lockedRun->id, TaskState::SUCCEEDED);
            return (int) $artifact->id;
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $files
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>,withheld_candidates:list<array{workflow_run_file_id:int,field_path:string}>,invocation_id:int}
     */
    public function sealedReview(WorkflowRun $run, TaskRun $task, TaskStep $step, array $input, array $files, bool $lock = true): array
    {
        $state = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock($lock)->find();
        $result = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->lock($lock)->find();
        if ($state === null || $result === null || (string) $state->state !== 'succeeded'
            || (int) $state->attempt_count < 1
            || (int) $state->attempt_count !== (int) $result->attempt_no
            || (int) $state->last_invocation_id !== (int) $result->invocation_id
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
            self::invalid();
        }
        $chunkRunner = new MaterialCrossFileReviewChunkRunner();
        $chunked = $chunkRunner->active($run, $task);
        $invocationAttempt = $chunked ? $chunkRunner->rootInvocationAttemptNo($run, $task, (int) $result->invocation_id) : (int) $result->attempt_no;
        $identity = new MaterialCrossFileReviewAttemptIdentity(
            (string) $run->logical_execution_id, $invocationAttempt,
        );
        $invocation = Invocation::where('id', (int) $result->invocation_id)->find();
        $dispatch = InvocationDispatch::where('invocation_id', (int) $result->invocation_id)->find();
        $usage = UsageEvent::where('invocation_id', (int) $result->invocation_id)->find();
        if ($invocation === null || $dispatch === null || $usage === null
            || (int) $invocation->environment_id !== (int) $run->environment_id
            || (int) $invocation->organization_id !== (int) $run->organization_id
            || (int) $invocation->application_id !== (int) $run->application_id
            || (int) $invocation->workload_client_id !== (int) $run->workload_client_id
            || (int) $invocation->parent_workflow_run_id !== (int) $run->id
            || (int) $invocation->parent_task_run_id !== (int) $task->id
            || (int) $invocation->parent_task_step_id !== (int) $step->id
            || $invocation->parent_workflow_run_file_id !== null
            || (string) $invocation->workflow_node_code !== 'cross_file_review'
            || (int) $invocation->execution_attempt_no !== $invocationAttempt
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
            || !hash_equals($identity->requestId(), (string) $invocation->request_id)
            || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
            || (string) $invocation->state !== 'succeeded'
            || (int) $invocation->status !== 1 || $invocation->delete_time !== null
            || (int) $dispatch->invocation_id !== (int) $invocation->id
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->parent_workflow_run_id !== (int) $run->id
            || (int) $dispatch->parent_task_run_id !== (int) $task->id
            || (int) $dispatch->parent_task_step_id !== (int) $step->id
            || $dispatch->parent_workflow_run_file_id !== null
            || (string) $dispatch->workflow_node_code !== 'cross_file_review'
            || (int) $dispatch->attempt_no !== $invocationAttempt
            || (string) $dispatch->state !== 'succeeded'
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)
            || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $dispatch->logical_execution_id)
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || (int) $usage->invocation_id !== (int) $invocation->id
            || (int) $usage->environment_id !== (int) $run->environment_id
            || (int) $usage->organization_id !== (int) $run->organization_id
            || (int) $usage->application_id !== (int) $run->application_id
            || (int) $usage->workload_client_id !== (int) $run->workload_client_id
            || (int) $usage->model_id !== (int) $invocation->model_id
            || (int) $usage->deployment_id !== (int) $invocation->deployment_id
            || (string) $usage->service_action !== 'sand_ai.workflow.run'
            || (string) $usage->source_kind !== 'structured_workflow'
            || (string) $usage->capability_code !== 'structured_extraction'
            || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id) {
            self::invalid();
        }
        $profileRef = $input['analysis_profile'] ?? null;
        if (!is_array($profileRef) || !is_array($profileRef['snapshot'] ?? null)) {
            self::invalid();
        }
        $profile = (new MaterialAnalysisProfilePolicy())->validate($profileRef['snapshot']);
        $snapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        if (!is_array($snapshot)
            || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($snapshot))) {
            self::invalid();
        }
        $output = MaterialReviewOutputContract::fromSnapshot($snapshot);
        $prompt = (new MaterialCrossFileReviewPromptFactory())->build($profile, $files, $output, $chunked);
        if (!hash_equals((string) $result->input_sha256, $prompt['input_sha256'])) {
            self::invalid();
        }
        $payload = is_object($result->payload) ? (array) $result->payload : $result->payload;
        if (!is_array($payload) || !is_array($payload['conflicts'] ?? null)
            || !is_array($payload['items_to_verify'] ?? null)
            || !hash_equals((string) $result->payload_sha256, StructuredWorkflowPolicy::fingerprint($payload))) {
            self::invalid();
        }
        $sources = [];
        foreach (WorkflowCrossFileReviewSource::where('result_id', (int) $result->id)
            ->order('field_path')->order('source_block_id')->lock($lock)->select() as $source) {
            $sources[] = ['field_path' => (string) $source->field_path, 'source_block_id' => (int) $source->source_block_id];
        }
        $withheld = [];
        foreach (WorkflowCrossFileReviewWithheld::where('result_id', (int) $result->id)
            ->order('workflow_run_file_id')->order('field_path')->lock($lock)->select() as $candidate) {
            $withheld[] = [
                'workflow_run_file_id' => (int) $candidate->workflow_run_file_id,
                'field_path' => (string) $candidate->field_path,
            ];
        }
        if (count($sources) !== (int) $result->field_source_count
            || count($withheld) !== (int) $result->withheld_candidate_count) {
            self::invalid();
        }
        if ($chunked) {
            $sealed = [];
            $fileResultIds = [];
            foreach ($files as $file) {
                $fileResult = WorkflowFileResult::where('workflow_run_file_id', $file['workflow_run_file_id'])->find();
                if ($fileResult === null) self::invalid();
                $fileResultIds[] = (int) $fileResult->id;
                $sealed[] = [$file['workflow_run_file_id'], (int) $fileResult->id, (string) $fileResult->payload_sha256];
            }
            $completeInput = ['workflow_run_id' => (int) $run->id, 'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                'file_result_ids' => $fileResultIds, 'sealed_set_sha256' => hash('sha256', json_encode($sealed, JSON_THROW_ON_ERROR))] + $prompt;
            if ($output !== null) $completeInput['material_review_output_contract'] = $snapshot['material_review_output_contract'];
            $complete = $chunkRunner->complete($run, $task, $completeInput);
            $prepared = $chunkRunner->prepareSealed($run, $task, $completeInput, $completeInput, $complete['raw_json'], (int) $result->invocation_id);
            if ($prepared['payload_sha256'] !== (string) $result->payload_sha256
                || $prepared['source_rows'] !== $sources || $prepared['withheld_rows'] !== $withheld) self::invalid();
        }
        return [
            'payload' => $payload,
            'field_sources' => $sources,
            'withheld_candidates' => $withheld,
            'invocation_id' => (int) $invocation->id,
        ];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The material file results cannot complete this workflow');
    }
}
