<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Capability\EnvironmentCapabilityLock;
use app\Application\File\FileOwnershipGuard;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\ArtifactFieldSource;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewSource;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Artifact;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\MaterialAnalysisProfile;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\db\ConnectionInterface;
use think\facade\Db;

final class MaterialAnalysisProfileGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly MaterialAnalysisProfileDraftPolicy $draftPolicy = new MaterialAnalysisProfileDraftPolicy(),
        private readonly EnvironmentCapabilityLock $environmentLock = new EnvironmentCapabilityLock(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(int $environmentId): array
    {
        $this->requireEnvironment($environmentId);
        return MaterialAnalysisProfile::where('environment_id', $environmentId)
            ->whereNull('delete_time')->order('code')->order('version', 'desc')->select()
            ->map(static fn (MaterialAnalysisProfile $profile): array => self::summary($profile, false))->all();
    }

    /** @return array<string,mixed> */
    public function read(int $environmentId, string $code, string $version): array
    {
        $this->requireEnvironment($environmentId);
        $profile = $this->find($environmentId, $code, $version);
        if ($profile === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '材料整理配置不存在');
        }
        return self::summary($profile, true);
    }

    /** @return list<array{sample_run_id:int,completed_at:string}> */
    public function samples(int $environmentId, string $code, string $version, int $revision): array
    {
        $this->requireEnvironment($environmentId);
        $profile = $this->find($environmentId, $code, $version);
        if ($profile === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '材料整理配置不存在');
        }
        if ($revision < 1 || (string) $profile->state !== 'draft' || (int) $profile->status !== 2
            || (int) $profile->revision !== $revision) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置草稿修订号已变化或已发布');
        }
        $rows = Db::connect('pgsql')->query(
            "SELECT run.id AS sample_run_id, task.update_time AS completed_at
             FROM sand_ai_workflow_run run
             JOIN sand_ai_task_run task ON task.id = run.task_id
             JOIN sand_ai_artifact artifact ON artifact.workflow_run_id = run.id
             JOIN sand_ai_invocation invocation ON invocation.id = run.last_invocation_id
             JOIN sand_ai_invocation_dispatch dispatch ON dispatch.invocation_id = invocation.id
             JOIN sand_ai_usage_event usage ON usage.invocation_id = invocation.id
             WHERE run.environment_id = ? AND run.workflow_code = 'material_analysis'
               AND run.status = 1 AND run.delete_time IS NULL
               AND run.input_snapshot #>> '{material_profile_sample,profile_id}' = ?
               AND run.input_snapshot #>> '{material_profile_sample,revision}' = ?
               AND run.input_snapshot #>> '{material_profile_sample,config_sha256}' = ?
               AND task.environment_id = run.environment_id
               AND task.resource_type = 'workflow_run' AND task.resource_id = run.id
               AND task.state = 'succeeded'
               AND artifact.state = 'succeeded' AND artifact.status = 1 AND artifact.delete_time IS NULL
               AND invocation.state = 'succeeded' AND dispatch.state = 'succeeded'
             ORDER BY run.id DESC LIMIT 20",
            [$environmentId, (string) $profile->id, (string) $revision, StructuredWorkflowPolicy::fingerprint($profile->config)],
        );
        return array_map(static fn (array $row): array => [
            'sample_run_id' => (int) $row['sample_run_id'],
            'completed_at' => (string) $row['completed_at'],
        ], $rows);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveDraft(array $input, string $actorRef): array
    {
        $draft = $this->draftPolicy->normalize($input);
        $actorId = $this->actorId($actorRef);
        $environmentId = $draft['environment_id'];
        $this->requireEnvironment($environmentId);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($draft, $actorId, $environmentId): array {
            $this->environmentLock->acquire($connection, $environmentId);
            $code = $draft['config']['code'];
            $version = $draft['config']['version'];
            $existing = MaterialAnalysisProfile::where('environment_id', $environmentId)
                ->where('code', $code)->where('version', $version)->lock(true)->find();
            if ($existing === null) {
                if ($draft['expected_revision'] !== null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置修订号已变化');
                }
                $profile = MaterialAnalysisProfile::create([
                    'environment_id' => $environmentId,
                    'code' => $code,
                    'version' => $version,
                    'name' => $draft['name'],
                    'revision' => 1,
                    'config' => $draft['config'],
                    'state' => 'draft',
                    'status' => 2,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            } else {
                if ((string) $existing->state !== 'draft' || $existing->delete_time !== null
                    || $draft['expected_revision'] !== (int) $existing->revision) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置已发布、撤销或修订号已变化');
                }
                $existing->save([
                    'name' => $draft['name'],
                    'config' => $draft['config'],
                    'revision' => (int) $existing->revision + 1,
                    'updated_by' => $actorId,
                ]);
                $profile = $existing;
            }
            AuditLog::create([
                'environment_id' => $environmentId,
                'actor_type' => 'admin',
                'actor_ref' => (string) $actorId,
                'action' => 'material_analysis_profile.draft',
                'resource_type' => 'material_analysis_profile',
                'resource_id' => (int) $profile->id,
                'summary' => '保存材料整理配置草稿',
                'context' => ['code' => $code, 'version' => $version, 'revision' => (int) $profile->revision],
            ]);
            return self::summary($profile, true);
        });
    }

    /** @return array<string,mixed> */
    public function revoke(int $environmentId, string $code, string $version, string $actorRef): array
    {
        $actorId = $this->actorId($actorRef);
        $this->requireEnvironment($environmentId);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $code, $version, $actorId): array {
            $profile = $this->find($environmentId, $code, $version, true);
            if ($profile === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '材料整理配置不存在');
            }
            if ((string) $profile->state !== 'published' || (int) $profile->status !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '仅能撤销已发布的材料整理配置');
            }
            if ($profile->save([
                'state' => 'revoked',
                'status' => 2,
                'revoked_at' => gmdate('Y-m-d H:i:s'),
                'updated_by' => $actorId,
            ]) !== true) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置撤销状态未能保存');
            }
            AuditLog::create([
                'environment_id' => $environmentId,
                'actor_type' => 'admin',
                'actor_ref' => (string) $actorId,
                'action' => 'material_analysis_profile.revoke',
                'resource_type' => 'material_analysis_profile',
                'resource_id' => (int) $profile->id,
                'summary' => '撤销材料整理配置',
                'context' => ['code' => $code, 'version' => $version, 'revision' => (int) $profile->revision],
            ]);
            return self::summary($profile, false);
        });
    }

    /** @return array<string,mixed> */
    public function publish(int $environmentId, string $code, string $version, int $revision, int $sampleRunId, string $actorRef): array
    {
        if ($revision < 1 || $sampleRunId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '需要精确修订号和样本运行标识');
        }
        $actorId = $this->actorId($actorRef);
        $this->requireEnvironment($environmentId);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $code, $version, $revision, $sampleRunId, $actorId): array {
            $profile = $this->find($environmentId, $code, $version, true);
            if ($profile === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '材料整理配置不存在');
            }
            if ((string) $profile->state !== 'draft' || (int) $profile->status !== 2 || (int) $profile->revision !== $revision) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置草稿修订号已变化或已发布');
            }

            // Lock in the same task -> run -> artifact order as the artifact writer.
            $candidate = WorkflowRun::where('id', $sampleRunId)->where('environment_id', $environmentId)
                ->where('status', 1)->whereNull('delete_time')->find();
            $taskId = $candidate === null ? 0 : (int) $candidate->task_id;
            $task = $taskId < 1 ? null : TaskRun::where('id', $taskId)->lock(true)->find();
            $run = $task === null ? null : WorkflowRun::where('id', $sampleRunId)
                ->where('environment_id', $environmentId)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            $artifact = $run === null ? null : Artifact::where('workflow_run_id', $sampleRunId)
                ->whereNull('delete_time')->lock(true)->find();
            $invocationId = $run === null ? 0 : (int) $run->last_invocation_id;
            $invocation = $invocationId < 1 ? null : Invocation::where('id', $invocationId)->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', $invocationId)->find();
            $usage = $invocationId < 1 ? null : UsageEvent::where('invocation_id', $invocationId)->find();
            if ($run === null || $task === null || $artifact === null || $invocation === null || $dispatch === null || $usage === null
                || (int) $run->task_id !== (int) $task->id) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '指定样本尚无完整的任务、成果、调用和计量证据');
            }

            $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
            $sealed = is_array($input) && ($input['material_execution_mode'] ?? null) === 'single_file_v1'
                ? $this->sampleFileEvidence($run, $task, $artifact) : null;
            (new MaterialAnalysisSampleEvidencePolicy())->assertPublishable(
                [
                    'id' => (int) $profile->id, 'environment_id' => $environmentId,
                    'code' => $code, 'version' => $version, 'revision' => $revision,
                    'config' => $profile->config,
                ],
                [
                    'id' => (int) $run->id, 'task_id' => (int) $run->task_id,
                    'last_invocation_id' => $invocationId,
                    'environment_id' => (int) $run->environment_id,
                    'organization_id' => (int) $run->organization_id,
                    'application_id' => (int) $run->application_id,
                    'workload_client_id' => (int) $run->workload_client_id,
                    'workflow_code' => (string) $run->workflow_code,
                    'logical_execution_id' => (string) $run->logical_execution_id,
                    'status' => (int) $run->status,
                    'input_snapshot' => $run->input_snapshot,
                    'execution_snapshot' => $run->execution_snapshot,
                    'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                    'snapshot_sha256' => (string) $run->snapshot_sha256,
                    'file_snapshot' => $run->file_snapshot,
                ],
                [
                    'id' => (int) $task->id, 'environment_id' => (int) $task->environment_id,
                    'resource_type' => (string) $task->resource_type, 'resource_id' => (int) $task->resource_id,
                    'state' => (string) $task->state, 'input' => $task->input,
                ],
                [
                    'id' => (int) $artifact->id, 'workflow_run_id' => (int) $artifact->workflow_run_id,
                    'state' => (string) $artifact->state, 'status' => (int) $artifact->status,
                ],
                [
                    'id' => (int) $invocation->id, 'state' => (string) $invocation->state,
                    'environment_id' => (int) $invocation->environment_id,
                    'organization_id' => (int) $invocation->organization_id,
                    'application_id' => (int) $invocation->application_id,
                    'workload_client_id' => (int) $invocation->workload_client_id,
                    'parent_workflow_run_id' => (int) $invocation->parent_workflow_run_id,
                    'parent_task_run_id' => (int) $invocation->parent_task_run_id,
                    'request_id' => (string) $invocation->request_id,
                    'logical_execution_id' => (string) $invocation->logical_execution_id,
                    'execution_snapshot_sha256' => (string) $invocation->execution_snapshot_sha256,
                    'model_id' => (int) $invocation->model_id,
                    'deployment_id' => (int) $invocation->deployment_id,
                    'parent_task_step_id' => $invocation->parent_task_step_id === null ? null : (int) $invocation->parent_task_step_id,
                    'parent_workflow_run_file_id' => $invocation->parent_workflow_run_file_id === null ? null : (int) $invocation->parent_workflow_run_file_id,
                    'workflow_node_code' => $invocation->workflow_node_code,
                    'execution_attempt_no' => (int) $invocation->execution_attempt_no,
                    'status' => (int) $invocation->status, 'delete_time' => $invocation->delete_time,
                ],
                [
                    'invocation_id' => (int) $dispatch->invocation_id, 'state' => (string) $dispatch->state,
                    'environment_id' => (int) $dispatch->environment_id,
                    'application_id' => (int) $dispatch->application_id,
                    'parent_workflow_run_id' => (int) $dispatch->parent_workflow_run_id,
                    'parent_task_run_id' => (int) $dispatch->parent_task_run_id,
                    'logical_execution_id' => (string) $dispatch->logical_execution_id,
                    'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
                    'service_action' => (string) $dispatch->service_action,
                    'source_kind' => (string) $dispatch->source_kind,
                    'capability_code' => (string) $dispatch->capability_code,
                    'model_id' => (int) $dispatch->model_id,
                    'deployment_id' => (int) $dispatch->deployment_id,
                    'parent_task_step_id' => $dispatch->parent_task_step_id === null ? null : (int) $dispatch->parent_task_step_id,
                    'parent_workflow_run_file_id' => $dispatch->parent_workflow_run_file_id === null ? null : (int) $dispatch->parent_workflow_run_file_id,
                    'workflow_node_code' => $dispatch->workflow_node_code,
                    'attempt_no' => (int) $dispatch->attempt_no,
                    'logical_request_id' => (string) $dispatch->logical_request_id,
                ],
                [
                    'id' => (int) $usage->id, 'invocation_id' => (int) $usage->invocation_id,
                    'environment_id' => (int) $usage->environment_id,
                    'organization_id' => (int) $usage->organization_id,
                    'application_id' => (int) $usage->application_id,
                    'workload_client_id' => (int) $usage->workload_client_id,
                    'source_kind' => (string) $usage->source_kind,
                    'service_action' => (string) $usage->service_action,
                    'capability_code' => (string) $usage->capability_code,
                    'model_id' => (int) $usage->model_id,
                    'deployment_id' => (int) $usage->deployment_id,
                    'idempotency_key' => (string) $usage->idempotency_key,
                ],
                $sealed,
            );
            if ($profile->save([
                'state' => 'published', 'status' => 1,
                'sample_run_id' => $sampleRunId,
                'sample_artifact_id' => (int) $artifact->id,
                'sample_usage_event_id' => (int) $usage->id,
                'published_at' => gmdate('Y-m-d H:i:s'),
                'updated_by' => $actorId,
            ]) !== true) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '材料整理配置发布状态未能保存');
            }
            AuditLog::create([
                'environment_id' => $environmentId,
                'actor_type' => 'admin',
                'actor_ref' => (string) $actorId,
                'action' => 'material_analysis_profile.publish',
                'resource_type' => 'material_analysis_profile',
                'resource_id' => (int) $profile->id,
                'summary' => '发布已完成样本验证的材料整理配置',
                'context' => [
                    'code' => $code, 'version' => $version, 'revision' => $revision,
                    'sample_run_id' => $sampleRunId, 'sample_artifact_id' => (int) $artifact->id,
                    'sample_usage_event_id' => (int) $usage->id,
                    'config_sha256' => StructuredWorkflowPolicy::fingerprint($profile->config),
                ],
            ]);
            return self::summary($profile, false);
        });
    }

    /** @return array{step_id:int,files:list<array<string,mixed>>,review:array<string,mixed>|null} */
    private function sampleFileEvidence(WorkflowRun $run, TaskRun $task, Artifact $artifact): array
    {
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($snapshot) ? $snapshot : null, (int) $run->environment_id,
                'structured_workflow', 'sand_ai.workflow.run',
            );
            $scope = FileOwnershipGuard::scope($attribution);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本文件归属快照无效');
        }
        if ($attribution->organizationId !== (int) $run->organization_id
            || $attribution->applicationId !== (int) $run->application_id
            || $attribution->workloadClientId !== (int) $run->workload_client_id) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本归属与冻结工作负载不一致');
        }
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->lock(true)->find();
        $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
        if ($step === null || count($runFiles) < 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本缺少固定文件或任务步骤');
        }
        $files = [];
        $sourceFiles = [];
        foreach ($runFiles as $runFile) {
            $file = AiFile::where('id', (int) $runFile->file_id)->lock(true)->find();
            $parse = AiFileParse::where('id', (int) $runFile->parse_id)->lock(true)->find();
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->lock(true)->find();
            if ($file === null || $parse === null || $result === null
                || (int) $file->environment_id !== (int) $run->environment_id
                || (int) $file->status !== 1 || $file->delete_time !== null || (string) $file->state !== 'parsed'
                || (string) $file->owner_scope !== $scope
                || (int) $parse->file_id !== (int) $file->id || (string) $parse->state !== 'succeeded'
                || (int) $parse->status !== 1 || $parse->delete_time !== null
                || !hash_equals((string) $runFile->sha256, (string) $file->sha256)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本固定文件或解析已失效');
            }
            (new FileOwnershipGuard())->assert($file, $scope);
            MaterialAnalysisFilePayloadDigest::verified(
                $result->payload, (string) $result->payload_sha256,
                $result->payload_digest_version, $result->content_purged_time,
            );
            $sourceFiles[(int) $runFile->file_id] = (int) $runFile->parse_id;
            $sourceCount = 0;
            foreach (WorkflowFileResultSource::where('result_id', (int) $result->id)->lock(true)->select() as $source) {
                $this->assertSampleSource((int) $source->source_block_id, [(int) $runFile->file_id => (int) $runFile->parse_id]);
                $sourceCount++;
            }
            $invocation = Invocation::where('id', (int) $result->invocation_id)->find();
            $dispatch = InvocationDispatch::where('invocation_id', (int) $result->invocation_id)->find();
            $usage = UsageEvent::where('invocation_id', (int) $result->invocation_id)->find();
            if ($invocation === null || $dispatch === null || $usage === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本逐文件调用或计量证据不完整');
            }
            $files[] = [
                'run_file' => ['id' => (int) $runFile->id, 'file_id' => (int) $runFile->file_id,
                    'parse_id' => (int) $runFile->parse_id, 'sha256' => (string) $runFile->sha256,
                    'version_ref' => (string) $runFile->version_ref,
                    'status' => (int) $runFile->status, 'delete_time' => $runFile->delete_time,
                    'analysis_state' => (string) $runFile->analysis_state,
                    'attempt_count' => (int) $runFile->attempt_count,
                    'last_invocation_id' => (int) $runFile->last_invocation_id],
                'result' => ['id' => (int) $result->id, 'workflow_run_file_id' => (int) $result->workflow_run_file_id,
                    'attempt_no' => (int) $result->attempt_no, 'invocation_id' => (int) $result->invocation_id,
                    'execution_snapshot_sha256' => (string) $result->execution_snapshot_sha256,
                    'schema_code' => (string) $result->schema_code, 'schema_version' => (string) $result->schema_version,
                    'field_source_count' => (int) $result->field_source_count],
                'source_count' => $sourceCount,
                'invocation' => $this->sampleInvocationFacts($invocation),
                'dispatch' => $this->sampleDispatchFacts($dispatch),
                'usage' => $this->sampleUsageFacts($usage),
            ];
        }
        foreach (ArtifactFieldSource::where('artifact_id', (int) $artifact->id)->lock(true)->select() as $source) {
            $this->assertSampleSource((int) $source->source_block_id, $sourceFiles);
        }
        $review = null;
        if (count($files) > 1) {
            $state = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
            $result = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($state === null || $result === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本缺少跨文件核对证据');
            }
            $sourceCount = 0;
            foreach (WorkflowCrossFileReviewSource::where('result_id', (int) $result->id)->lock(true)->select() as $source) {
                $this->assertSampleSource((int) $source->source_block_id, $sourceFiles);
                $sourceCount++;
            }
            $review = [
                'state' => ['state' => (string) $state->state, 'attempt_count' => (int) $state->attempt_count,
                    'last_invocation_id' => (int) $state->last_invocation_id],
                'result' => ['workflow_run_id' => (int) $result->workflow_run_id,
                    'attempt_no' => (int) $result->attempt_no, 'invocation_id' => (int) $result->invocation_id,
                    'execution_snapshot_sha256' => (string) $result->execution_snapshot_sha256,
                    'field_source_count' => (int) $result->field_source_count],
                'source_count' => $sourceCount,
            ];
        }
        return ['step_id' => (int) $step->id, 'files' => $files, 'review' => $review];
    }

    /** @param array<int,int> $sourceFiles */
    private function assertSampleSource(int $sourceBlockId, array $sourceFiles): void
    {
        $block = SourceBlock::where('id', $sourceBlockId)->lock(true)->find();
        if ($block === null || (int) $block->status !== 1 || $block->delete_time !== null
            || !isset($sourceFiles[(int) $block->file_id])
            || $sourceFiles[(int) $block->file_id] !== (int) $block->parse_id
            || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '样本来源块已失效');
        }
    }

    /** @return array<string,mixed> */
    private function sampleInvocationFacts(Invocation $invocation): array
    {
        return ['id' => (int) $invocation->id, 'state' => (string) $invocation->state,
            'status' => (int) $invocation->status, 'delete_time' => $invocation->delete_time,
            'environment_id' => (int) $invocation->environment_id,
            'organization_id' => (int) $invocation->organization_id,
            'application_id' => (int) $invocation->application_id,
            'workload_client_id' => (int) $invocation->workload_client_id,
            'parent_workflow_run_id' => (int) $invocation->parent_workflow_run_id,
            'parent_task_run_id' => (int) $invocation->parent_task_run_id,
            'parent_task_step_id' => (int) $invocation->parent_task_step_id,
            'parent_workflow_run_file_id' => $invocation->parent_workflow_run_file_id === null ? null : (int) $invocation->parent_workflow_run_file_id,
            'workflow_node_code' => $invocation->workflow_node_code,
            'execution_attempt_no' => (int) $invocation->execution_attempt_no,
            'request_id' => (string) $invocation->request_id,
            'logical_execution_id' => (string) $invocation->logical_execution_id,
            'execution_snapshot_sha256' => (string) $invocation->execution_snapshot_sha256,
            'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id];
    }

    /** @return array<string,mixed> */
    private function sampleDispatchFacts(InvocationDispatch $dispatch): array
    {
        return ['invocation_id' => (int) $dispatch->invocation_id, 'state' => (string) $dispatch->state,
            'environment_id' => (int) $dispatch->environment_id,
            'application_id' => (int) $dispatch->application_id,
            'parent_workflow_run_id' => (int) $dispatch->parent_workflow_run_id,
            'parent_task_run_id' => (int) $dispatch->parent_task_run_id,
            'parent_task_step_id' => (int) $dispatch->parent_task_step_id,
            'parent_workflow_run_file_id' => $dispatch->parent_workflow_run_file_id === null ? null : (int) $dispatch->parent_workflow_run_file_id,
            'workflow_node_code' => $dispatch->workflow_node_code,
            'attempt_no' => (int) $dispatch->attempt_no,
            'logical_execution_id' => (string) $dispatch->logical_execution_id,
            'logical_request_id' => (string) $dispatch->logical_request_id,
            'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
            'service_action' => (string) $dispatch->service_action,
            'source_kind' => (string) $dispatch->source_kind,
            'capability_code' => (string) $dispatch->capability_code,
            'model_id' => (int) $dispatch->model_id,
            'deployment_id' => (int) $dispatch->deployment_id];
    }

    /** @return array<string,mixed> */
    private function sampleUsageFacts(UsageEvent $usage): array
    {
        return ['id' => (int) $usage->id, 'invocation_id' => (int) $usage->invocation_id,
            'environment_id' => (int) $usage->environment_id,
            'organization_id' => (int) $usage->organization_id,
            'application_id' => (int) $usage->application_id,
            'workload_client_id' => (int) $usage->workload_client_id,
            'source_kind' => (string) $usage->source_kind,
            'service_action' => (string) $usage->service_action,
            'capability_code' => (string) $usage->capability_code,
            'idempotency_key' => (string) $usage->idempotency_key,
            'model_id' => (int) $usage->model_id,
            'deployment_id' => (int) $usage->deployment_id];
    }

    private function find(int $environmentId, string $code, string $version, bool $forUpdate = false): ?MaterialAnalysisProfile
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '材料整理配置标识无效');
        }
        $query = MaterialAnalysisProfile::where('environment_id', $environmentId)
            ->where('code', $code)->where('version', $version)->whereNull('delete_time');
        return ($forUpdate ? $query->lock(true) : $query)->find();
    }

    /** @return array<string,mixed> */
    private static function summary(MaterialAnalysisProfile $profile, bool $includeConfig): array
    {
        $result = [
            'id' => (int) $profile->id,
            'environment_id' => (int) $profile->environment_id,
            'code' => (string) $profile->code,
            'version' => (string) $profile->version,
            'name' => (string) $profile->getAttr('name'),
            'revision' => (int) $profile->revision,
            'state' => (string) $profile->state,
            'status' => (int) $profile->status,
            'sample_run_id' => $profile->sample_run_id === null ? null : (int) $profile->sample_run_id,
        ];
        if ($includeConfig) {
            $result['config'] = $profile->config;
        }
        return $result;
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function actorId(string $actorRef): int
    {
        if (!ctype_digit($actorRef) || (int) $actorRef < 1) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', '需要已登录的后台管理员');
        }
        return (int) $actorRef;
    }
}
