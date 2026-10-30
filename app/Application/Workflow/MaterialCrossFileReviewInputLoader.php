<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
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

/**
 * Reloads the complete sealed file set in one PostgreSQL snapshot.
 *
 * The caller must revalidate the original SandIAM context and the frozen
 * execution snapshot before this read, then recheck the lease and SandIAM
 * grant immediately before a provider-visible dispatch.
 */
final class MaterialCrossFileReviewInputLoader
{
    public function __construct(private readonly MaterialCrossFileReviewPromptFactory $promptFactory = new MaterialCrossFileReviewPromptFactory())
    {
    }

    /**
     * @return array{workflow_run_id:int,execution_snapshot_sha256:string,file_result_ids:list<int>,sealed_set_sha256:string,messages:list<array{role:string,content:string}>,source_file_ids:array<int,int>,candidates:list<array{workflow_run_file_id:int,file_id:int,field_path:string,source_block_ids:list<int>}>,input_sha256:string}
     */
    public function load(int $workflowRunId, TaskExecutionLease $lease): array
    {
        if ($workflowRunId < 1) {
            self::invalid();
        }
        $connection = Db::connect('pgsql');
        return $connection->transaction(function () use ($connection, $workflowRunId, $lease): array {
            $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return $this->loadForWriteTransaction($workflowRunId, $lease);
        });
    }

    /**
     * Rebuilds the same input inside a caller-owned PostgreSQL write
     * transaction. The caller must first lock the task, workflow, review
     * state, fixed files and source rows that it will seal against.
     *
     * @return array<string,mixed>
     */
    public function loadForWriteTransaction(int $workflowRunId, TaskExecutionLease $lease): array
    {
        if ($workflowRunId < 1) {
            self::invalid();
        }
        $run = WorkflowRun::where('id', $workflowRunId)->where('status', 1)->whereNull('delete_time')->find();
        $task = TaskRun::where('id', $lease->taskId)->find();
        $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->find();
        if ($run === null || $task === null || $step === null
            || (int) $run->task_id !== (int) $task->id
            || (int) $run->environment_id !== (int) $task->environment_id
            || (string) $run->workflow_code !== 'material_analysis'
            || (string) $run->output_schema_code !== 'sand_ai.material_analysis'
            || (string) $run->output_schema_version !== '1.0.0'
            || (string) $task->lease_owner !== $lease->owner
            || !hash_equals($lease->token, (string) $task->lease_token)
            || (int) $task->lease_fence !== $lease->fence
            || !in_array((string) $task->state, [TaskState::RUNNING, TaskState::RECOVERY_PENDING], true)
            || (string) $step->state !== ((string) $task->state === TaskState::RUNNING ? TaskState::RUNNING : TaskState::FAILED)) {
            self::invalid();
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (!is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1'
            || !is_string($run->execution_snapshot_sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $run->execution_snapshot_sha256) !== 1) {
            self::invalid();
        }
        $profileRef = $input['analysis_profile'] ?? null;
        if (!is_array($profileRef) || !is_string($profileRef['code'] ?? null)
            || !is_string($profileRef['version'] ?? null)
            || !is_array($profileRef['snapshot'] ?? null)
            || ($profileRef['snapshot']['code'] ?? null) !== $profileRef['code']
            || ($profileRef['snapshot']['version'] ?? null) !== $profileRef['version']) {
            self::invalid();
        }
        $profile = (new MaterialAnalysisProfilePolicy())->validate($profileRef['snapshot']);
        $attribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $trustedIdentityScope = FileOwnershipGuard::scope(UsageAttribution::fromCurrentPersisted(
                is_array($attribution) ? $attribution : null,
                (int) $run->environment_id,
                'structured_workflow',
                'sand_ai.workflow.run',
            ));
        } catch (\InvalidArgumentException) {
            self::invalid();
        }
        $runFiles = WorkflowRunFile::where('workflow_run_id', $workflowRunId)->order('id')->select();
        if (count($runFiles) < 2) {
            self::invalid();
        }
        $files = [];
        $fileResultIds = [];
        $sealed = [];
        $snapshotFiles = [];
        foreach ($runFiles as $runFile) {
            $fileId = (int) $runFile->file_id;
            $parseId = (int) $runFile->parse_id;
            $runFileId = (int) $runFile->id;
            $file = AiFile::where('id', $fileId)->where('environment_id', (int) $run->environment_id)
                ->where('owner_scope', $trustedIdentityScope)->where('status', 1)->whereNull('delete_time')->find();
            $parse = AiFileParse::where('id', $parseId)->where('file_id', $fileId)
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null || (string) $file->state !== 'parsed' || $parse === null
                || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                || (string) $runFile->analysis_state !== 'succeeded'
                || (int) $runFile->attempt_count < 1
                || !hash_equals((string) $runFile->sha256, (string) $file->sha256)) {
                self::invalid();
            }
            (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);
            $result = WorkflowFileResult::where('workflow_run_file_id', $runFileId)->find();
            if ($result === null || (int) $result->attempt_no !== (int) $runFile->attempt_count
                || (int) $result->invocation_id !== (int) $runFile->last_invocation_id
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)
                || (string) $result->schema_code !== 'sand_ai.material_analysis'
                || (string) $result->schema_version !== '1.0.0'
                || !is_string($result->payload_sha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $result->payload_sha256) !== 1) {
                self::invalid();
            }
            $invocationAttempt = (int) $result->attempt_no;
            $context = is_object($step->context) ? (array) $step->context : $step->context;
            $journal = is_array($context) ? ($context['material_chunks']['file_' . $runFileId] ?? null) : null;
            if (is_array($journal)) {
                $root = $journal['nodes'][$journal['plan']['root_node'] ?? ''] ?? null;
                if (($journal['version'] ?? null) !== MaterialChunkCheckpoint::VERSION || !is_array($root)
                    || ($root['state'] ?? null) !== 'succeeded'
                    || ($root['invocation_id'] ?? null) !== (int) $result->invocation_id
                    || !is_int($root['attempt_no'] ?? null) || $root['attempt_no'] < 1
                    || $root['attempt_no'] > (int) $result->attempt_no) self::invalid();
                $invocationAttempt = $root['attempt_no'];
                (new MaterialChunkRunner())->assertFileCheckpoint($run, $task, $runFile, $journal);
            }
            $identity = new MaterialAnalysisFileAttemptIdentity(
                (string) $run->logical_execution_id, $runFileId, $invocationAttempt,
            );
            $invocation = Invocation::where('id', (int) $result->invocation_id)
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->find();
            $dispatch = InvocationDispatch::where('invocation_id', (int) $result->invocation_id)->find();
            $usage = UsageEvent::where('invocation_id', (int) $result->invocation_id)->find();
            if ($invocation === null || $dispatch === null || $usage === null
                || (int) $invocation->environment_id !== (int) $run->environment_id
                || (int) $invocation->organization_id !== (int) $run->organization_id
                || (int) $invocation->application_id !== (int) $run->application_id
                || (int) $invocation->workload_client_id !== (int) $run->workload_client_id
                || (int) $invocation->parent_workflow_run_id !== $workflowRunId
                || (int) $invocation->parent_task_run_id !== (int) $task->id
                || (int) $invocation->parent_task_step_id !== (int) $step->id
                || (int) $invocation->parent_workflow_run_file_id !== $runFileId
                || (int) $invocation->execution_attempt_no !== $invocationAttempt
                || !hash_equals($identity->requestId(), (string) $invocation->request_id)
                || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
                || (string) $dispatch->state !== 'succeeded'
                || (int) $dispatch->environment_id !== (int) $run->environment_id
                || (int) $dispatch->parent_workflow_run_id !== $workflowRunId
                || (int) $dispatch->parent_task_run_id !== (int) $task->id
                || (int) $dispatch->parent_task_step_id !== (int) $step->id
                || (int) $dispatch->parent_workflow_run_file_id !== $runFileId
                || (int) $dispatch->attempt_no !== $invocationAttempt
                || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $dispatch->logical_execution_id)
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)
                || (int) $dispatch->model_id !== (int) $invocation->model_id
                || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
                || (string) $dispatch->capability_code !== 'structured_extraction'
                || (int) $usage->environment_id !== (int) $run->environment_id
                || (int) $usage->organization_id !== (int) $run->organization_id
                || (int) $usage->application_id !== (int) $run->application_id
                || (int) $usage->workload_client_id !== (int) $run->workload_client_id
                || (int) $usage->model_id !== (int) $invocation->model_id
                || (int) $usage->deployment_id !== (int) $invocation->deployment_id
                || (string) $usage->service_action !== 'sand_ai.workflow.run'
                || (string) $usage->source_kind !== 'structured_workflow'
                || (string) $usage->capability_code !== 'structured_extraction'
                || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id
                || (int) $usage->invocation_id !== (int) $invocation->id) {
                self::invalid();
            }
            $payload = MaterialAnalysisFilePayloadDigest::verified(
                $result->payload, (string) $result->payload_sha256,
                $result->payload_digest_version, $result->content_purged_time,
            );
            $sourceRows = [];
            foreach (WorkflowFileResultSource::where('result_id', (int) $result->id)
                ->order('field_path')->order('source_block_id')->select() as $source) {
                $sourceRows[] = [
                    'field_path' => (string) $source->field_path,
                    'source_block_id' => (int) $source->source_block_id,
                ];
            }
            if (count($sourceRows) !== (int) $result->field_source_count) {
                self::invalid();
            }
            $blocks = [];
            foreach (SourceBlock::where('file_id', $fileId)->where('parse_id', $parseId)
                ->where('status', 1)->order('sequence_no')->select() as $block) {
                $content = (string) $block->content;
                $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                if (!hash_equals((string) $block->content_sha256, hash('sha256', $content))) {
                    self::invalid();
                }
                $blocks[] = [
                    'id' => (int) $block->id, 'file_id' => $fileId, 'parse_id' => $parseId,
                    'sequence_no' => (int) $block->sequence_no,
                    'locator_type' => (string) $block->locator_type,
                    'locator' => is_array($locator) ? $locator : [],
                    'content' => $content, 'content_sha256' => (string) $block->content_sha256,
                ];
            }
            $snapshotFiles[] = [
                'file_id' => $fileId, 'parse_id' => $parseId,
                'sha256' => (string) $runFile->sha256,
                'version_ref' => (string) $runFile->version_ref,
                'blocks' => $blocks,
            ];
            $files[] = [
                'workflow_run_file_id' => $runFileId, 'file_id' => $fileId, 'parse_id' => $parseId,
                'payload' => $payload, 'field_sources' => $sourceRows, 'blocks' => $blocks,
            ];
            $fileResultIds[] = (int) $result->id;
            $sealed[] = [$runFileId, (int) $result->id, (string) $result->payload_sha256];
        }
        $storedSnapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        if (!is_array($storedSnapshot)) {
            self::invalid();
        }
        if (($storedSnapshot['analysis_profile'] ?? null) != $profileRef
            || ($storedSnapshot['attribution'] ?? null) != $attribution) {
            self::invalid();
        }
        (new ExecutionSnapshotBuilder())->assertRebuild(
            $storedSnapshot, (string) $run->execution_snapshot_sha256, $snapshotFiles,
        );
        $output = MaterialReviewOutputContract::fromSnapshot($storedSnapshot);
        $prompt = $this->promptFactory->build($profile, $files, $output, ($input['material_chunk_policy'] ?? null) === MaterialChunkCheckpoint::VERSION);
        if ($output !== null) $prompt['material_review_output_contract'] = $storedSnapshot['material_review_output_contract'];
        $sealedJson = json_encode($sealed, JSON_THROW_ON_ERROR);
        return [
            'workflow_run_id' => $workflowRunId,
            'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
            'file_result_ids' => $fileResultIds,
            'sealed_set_sha256' => hash('sha256', $sealedJson),
        ] + $prompt;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The cross-file review cannot load the complete sealed file set');
    }
}
