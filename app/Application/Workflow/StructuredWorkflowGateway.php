<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\File\FileOwnershipGuard;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Legal\LegalAnalysisExecutor;
use app\Application\Legal\LegalAnalysisOutputValidator;
use app\Application\Legal\LegalAnalysisProfileRegistry;
use app\Application\Legal\LegalAnalysisPromptFactory;
use app\Application\Legal\LegalSourceGateway;
use app\Application\Task\TaskGateway;
use app\Application\Task\DispatchRetryPolicy;
use app\Application\Task\MaterialFileDispatchFence;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageMeter;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\Artifact;
use app\Infrastructure\Persistence\Model\ArtifactFieldSource;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Identity\Infrastructure\WorkflowRunAccessGuard;
use Throwable;
use support\Log;
use think\facade\Db;

final class StructuredWorkflowGateway
{
    public function __construct(
        private readonly ?MaterialAnalysisExecutor $materialAnalysisExecutor = null,
        private readonly ?MaterialAnalysisCompletionClientFactory $materialAnalysisClients = null,
        private readonly ?StructuredArtifactWriter $artifactWriter = null,
        /** @var null|\Closure(WorkflowRun,TaskRun,int):void test seam; production leaves it null. */
        private readonly ?\Closure $beforeArtifactCommit = null,
        /** @var null|\Closure():void test seam for the post-Provider heartbeat only. */
        private readonly ?\Closure $postMaterialHeartbeat = null,
        /** @var null|\Closure(WorkflowRun):int test seam for terminal Invocation lookup only. */
        private readonly ?\Closure $terminalInvocationLookup = null,
    )
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function submitSample(int $environmentId, array $input, int $revision, string $idempotencyKey, UsageAttribution $attribution, \Closure $authorizeRun): array
    {
        $profile = $input['analysis_profile'] ?? null;
        if (!is_array($profile) || ($input['workflow']['code'] ?? null) !== 'material_analysis'
            || !is_string($profile['code'] ?? null) || !is_string($profile['version'] ?? null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A material analysis draft is required for sample execution');
        }
        $draft = (new MaterialAnalysisSampleProfileRegistry())->draft(
            $environmentId, $profile['code'], $profile['version'], $revision,
        );
        return $this->submit($environmentId, $input, $idempotencyKey, $attribution, $authorizeRun, $draft);
    }

    /** @param array<string, mixed> $input @param null|array<string,mixed> $sampleDraft @return array<string, mixed> */
    public function submit(int $environmentId, array $input, string $idempotencyKey, UsageAttribution $attribution, \Closure $authorizeRun, ?array $sampleDraft = null): array
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey, 'UTF-8') > 128) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Idempotency-Key is required and must not exceed 128 characters');
        }
        $normalized = StructuredWorkflowPolicy::normalizeRequest($input, new WorkflowDefinitionRegistry(), $environmentId, null, $sampleDraft);
        $sampleMarker = $sampleDraft === null ? null : [
            'profile_id' => $sampleDraft['id'],
            'revision' => $sampleDraft['revision'],
            'config_sha256' => StructuredWorkflowPolicy::fingerprint($sampleDraft['config']),
        ];
        if ($attribution->environmentId !== $environmentId) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Structured workflows require trusted environment and application attribution');
        }
        $modelWorkflow = in_array($normalized['workflow']['code'], ['material_analysis', 'legal_analysis'], true);        $modelWorkflow = in_array($normalized['workflow']['code'], ['material_analysis', 'legal_analysis'], true);
        $capabilityCode = $modelWorkflow ? 'structured_extraction' : 'source_manifest';
        try {
            $attribution = $attribution->withCapability($capabilityCode);
            $applicationScope = $attribution->applicationScope();
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Structured workflows require trusted application attribution');
        }
        $fingerprintInput = [
            'definition' => $normalized['definition'],
            'business_scope' => $normalized['business_scope'],
            'workflow' => $normalized['workflow'],
            'files' => $normalized['files'],
            'output_schema' => $normalized['output_schema'],
            'review_required' => $normalized['review_required'],
        ];
        if ($normalized['analysis_profile'] !== null) {
            $fingerprintInput['analysis_profile'] = $normalized['analysis_profile'];
        }
        if ($normalized['legal_analysis'] !== null) {
            $fingerprintInput['legal_analysis'] = $normalized['legal_analysis'];
        }
        if ($sampleMarker !== null) {
            $fingerprintInput['material_profile_sample'] = $sampleMarker;
        }
        $fingerprint = StructuredWorkflowPolicy::fingerprint($fingerprintInput);

        $prepared = Db::connect('pgsql')->transaction(function () use ($environmentId, $normalized, $sampleMarker, $idempotencyKey, $fingerprint, $attribution, $applicationScope, $capabilityCode): array {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtext(?))', [$environmentId . ':' . $applicationScope . ':' . $idempotencyKey]);
            $this->assertCurrentMaterialProfile($environmentId, $normalized['analysis_profile'], $normalized['workflow']['code'] === 'material_analysis', $sampleMarker);
            if ($normalized['legal_analysis'] !== null) {
                (new LegalAnalysisProfileRegistry())->assertSnapshotCurrent($environmentId, $normalized['legal_analysis']['profile']);
            }
            $existing = WorkflowRun::where('environment_id', $environmentId)->where('idempotency_application_ref', $applicationScope)->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if ($existing === null) {
                $legacy = WorkflowRun::where('environment_id', $environmentId)->whereNull('idempotency_application_ref')->where('idempotency_key', $idempotencyKey)->lock(true)->find();
                if ($legacy !== null) {
                    $legacySnapshot = is_object($legacy->attribution_snapshot) ? (array) $legacy->attribution_snapshot : $legacy->attribution_snapshot;
                    try {
                        $legacyAttribution = UsageAttribution::fromPersisted(is_array($legacySnapshot) ? $legacySnapshot : null, $environmentId, 'structured_workflow', 'sand_ai.workflow.run');
                        $sameApplication = hash_equals($applicationScope, $legacyAttribution->applicationScope());
                    } catch (\InvalidArgumentException) {
                        $sameApplication = false;
                    }
                    if (!$sameApplication || WorkflowRun::where('id', (int) $legacy->id)->whereNull('idempotency_application_ref')->update(['idempotency_application_ref' => $applicationScope]) !== 1) {
                        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Legacy workflow idempotency ownership cannot be proven');
                    }
                    $existing = $legacy;
                }
            }
            if ($existing !== null) {
                if (!hash_equals((string) $existing->snapshot_sha256, $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Idempotency-Key belongs to a different workflow request');
                }
                return ['idempotent_replay' => true, 'run_id' => (int) $existing->id];
            }

            $frozenFiles = $this->verifyFiles($environmentId, $normalized['files'], FileOwnershipGuard::scope($attribution));
            if ($normalized['legal_analysis'] !== null) {
                $this->assertLegalSourcesCurrent($environmentId, FileOwnershipGuard::scope($attribution), $normalized['legal_analysis'], $frozenFiles);
            }
            $snapshotFiles = $this->snapshotSourceFiles($frozenFiles);
            $singleFileMode = $normalized['workflow']['code'] === 'material_analysis'
                && (count($frozenFiles) === 1
                    ? $this->materialFileResultTableAvailable()
                    : $this->materialReviewTablesAvailable());
            if ($normalized['workflow']['code'] === 'material_analysis' && !$singleFileMode) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Material-analysis result schema is not installed');
            }
            $logicalExecutionId = bin2hex(random_bytes(24));
            $reviewOutput = $singleFileMode && count($frozenFiles) > 1
                ? MaterialReviewOutputContract::forNewRun($normalized['analysis_profile']['snapshot']) : null;
            $executionSnapshot = (new ExecutionSnapshotBuilder())->build([
                'environment_id' => $environmentId,
                'attribution' => $attribution->toPersisted(),
                'workflow_definition' => $normalized['definition'],
                'business_scope' => $normalized['business_scope'],
                'workflow' => $normalized['workflow'],
                'output_schema' => $normalized['output_schema'],
                'analysis_profile' => $normalized['analysis_profile'],
                ...($reviewOutput === null ? [] : ['material_review_output_contract' => $reviewOutput]),
                ...($normalized['legal_analysis'] === null ? [] : ['legal_analysis' => $normalized['legal_analysis'],
                    'legal_output_contract' => \app\Application\Legal\LegalOutputContract::forNewRun($normalized['legal_analysis']['profile'])]),
                ...($singleFileMode ? ['material_execution_mode' => 'single_file_v1', 'material_chunk_policy' => MaterialChunkCheckpoint::VERSION] : []),
                ...($sampleMarker === null ? [] : ['material_profile_sample' => $sampleMarker]),
                'review_required' => $normalized['review_required'],
                'logical_execution_id' => $logicalExecutionId,
                'files' => $snapshotFiles,
                'capability_code' => $capabilityCode,
                'route' => in_array($normalized['workflow']['code'], ['material_analysis', 'legal_analysis'], true)
                    ? ['state' => 'pending_pre_dispatch', 'receipt_policy' => 'not_enabled']
                    : ['state' => 'not_required'],
                'prompt_builder_version' => match ($normalized['workflow']['code']) {
                    'material_analysis' => 'material_analysis_prompt@1',
                    'legal_analysis' => 'legal_analysis_prompt@1',
                    default => null,
                },
                'price_decision' => ['state' => 'pending_pre_dispatch'],
            ]);
            $run = WorkflowRun::create([
                'environment_id' => $environmentId,
                'organization_id' => $attribution->organizationId,
                'application_id' => $attribution->applicationId,
                'workload_client_id' => $attribution->workloadClientId,
                'attribution_snapshot' => $attribution->toPersisted(),
                'idempotency_application_id' => $attribution->applicationId,
                'idempotency_application_ref' => $applicationScope,
                'logical_execution_id' => $logicalExecutionId,
                'execution_snapshot_version' => ExecutionSnapshotBuilder::VERSION,
                'execution_snapshot' => $executionSnapshot['snapshot'],
                'execution_snapshot_sha256' => $executionSnapshot['sha256'],
                'workflow_code' => $normalized['workflow']['code'],
                'workflow_version' => $normalized['workflow']['version'],
                'output_schema_code' => $normalized['output_schema']['code'],
                'output_schema_version' => $normalized['output_schema']['version'],
                'business_scope_ref' => $normalized['business_scope']['ref'],
                'idempotency_key' => $idempotencyKey,
                'snapshot_sha256' => $fingerprint,
                'definition_snapshot' => $normalized['definition'],
                'input_snapshot' => [
                    'business_scope' => $normalized['business_scope'],
                    'workflow' => $normalized['workflow'],
                    'output_schema' => $normalized['output_schema'],
                    'analysis_profile' => $normalized['analysis_profile'],
                    ...($normalized['legal_analysis'] === null ? [] : ['legal_analysis' => $normalized['legal_analysis']]),
                    ...($singleFileMode ? ['material_execution_mode' => 'single_file_v1', 'material_chunk_policy' => MaterialChunkCheckpoint::VERSION] : []),
                    ...($sampleMarker === null ? [] : ['material_profile_sample' => $sampleMarker]),
                    'review_required' => $normalized['review_required'],
                ],
                'file_snapshot' => $frozenFiles,
                'review_required' => $normalized['review_required'],
                'status' => 1,
            ]);
            foreach ($frozenFiles as $file) {
                WorkflowRunFile::create([
                    'workflow_run_id' => (int) $run->id,
                    'file_id' => $file['file_id'],
                    'parse_id' => $file['parse_id'],
                    'sha256' => $file['sha256'],
                    'version_ref' => $file['version_ref'],
                    'analysis_state' => 'pending',
                    'attempt_count' => 0,
                    'status' => 1,
                ]);
            }
            if ($singleFileMode && count($frozenFiles) > 1) {
                WorkflowCrossFileReview::create([
                    'workflow_run_id' => (int) $run->id,
                    'state' => 'pending',
                    'attempt_count' => 0,
                ]);
            }
            $task = TaskRun::create([
                'environment_id' => $environmentId,
                'organization_id' => $attribution->organizationId,
                'application_id' => $attribution->applicationId,
                'workload_client_id' => $attribution->workloadClientId,
                'task_type' => 'structured_workflow',
                'resource_type' => 'workflow_run',
                'resource_id' => (int) $run->id,
                'request_id' => 'workflow_run_' . (int) $run->id,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('sand_ai_task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => ['workflow_run_id' => (int) $run->id, 'snapshot_sha256' => $fingerprint, 'execution_snapshot_sha256' => $executionSnapshot['sha256']],
                'status' => 1,
            ]);
            $run->save(['task_id' => (int) $task->id]);
            TaskStep::create([
                'task_id' => (int) $task->id,
                'step_code' => (string) ($normalized['definition']['steps'][0] ?? 'execute_workflow'),
                'sequence_no' => 1,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'context' => ['workflow_code' => $normalized['workflow']['code'], 'review_required' => $normalized['review_required']],
                'status' => 1,
            ]);
            $this->audit($environmentId, 'workflow.authorization_pending', (int) $run->id, 'SandAI structured workflow awaits authorization', ['workflow_code' => $normalized['workflow']['code']]);
            // Initial insert has state_version=1 and state_revision_txid=txid_current().
            return ['idempotent_replay' => false, 'run_id' => (int) $run->id];
        });

        try {
            // SandIAM must see a committed server-owned run and persist its
            // own authorization or denial audit outside the SandAI transaction.
            $iamAuthorization = $authorizeRun($prepared['run_id']);
        } catch (Throwable $failure) {
            try {
                $this->rejectPendingAuthorization($prepared['run_id'], $failure instanceof ApiProblem ? $failure->errorCode : 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
            } catch (Throwable $cleanupFailure) {
                // The pending task is non-claimable and the worker timeout will
                // close it later; keep SandIAM's stable refusal for the caller.
                Log::warning('SandAI workflow authorization cleanup failed', [
                    'workflow_run_id' => $prepared['run_id'],
                    'exception_type' => $cleanupFailure::class,
                ]);
            }
            throw $failure;
        }

        try {
            return $this->activateAuthorizedRun($prepared['run_id'], $environmentId, $fingerprint, $iamAuthorization, $prepared['idempotent_replay']);
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_RESOURCE_CONFLICT'], true)) {
                try {
                    $this->rejectPendingAuthorization($prepared['run_id'], $problem->errorCode);
                } catch (Throwable $cleanupFailure) {
                    Log::warning('SandAI revoked material profile cleanup failed', [
                        'workflow_run_id' => $prepared['run_id'],
                        'exception_type' => $cleanupFailure::class,
                    ]);
                }
            }
            throw $problem;
        }
    }

    /** @param array<string,mixed> $iamAuthorization @return array<string,mixed> */
    private function activateAuthorizedRun(int $runId, int $environmentId, string $fingerprint, array $iamAuthorization, bool $replay): array
    {
        return Db::connect('pgsql')->transaction(function () use ($runId, $environmentId, $fingerprint, $iamAuthorization, $replay): array {
            $probe = WorkflowRun::where('id', $runId)->where('environment_id', $environmentId)->find();
            if ($probe === null || (int) $probe->task_id < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow run changed before enqueue');
            }
            $task = TaskRun::where('id', (int) $probe->task_id)->where('resource_type', 'workflow_run')
                ->where('resource_id', $runId)->lock(true)->find();
            $run = WorkflowRun::where('id', $runId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($run === null || $task === null || (int) $run->task_id !== (int) $task->id
                || !hash_equals((string) $run->snapshot_sha256, $fingerprint)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow run changed before enqueue');
            }
            $frozenInput = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
            $this->assertCurrentMaterialProfile(
                $environmentId, is_array($frozenInput) ? ($frozenInput['analysis_profile'] ?? null) : null,
                (string) $run->workflow_code === 'material_analysis',
                is_array($frozenInput) ? ($frozenInput['material_profile_sample'] ?? null) : null,
            );
            if ((string) $run->workflow_code === 'legal_analysis') {
                $legalProfile = is_array($frozenInput) && is_array($frozenInput['legal_analysis'] ?? null)
                    ? ($frozenInput['legal_analysis']['profile'] ?? null) : null;
                if (!is_array($legalProfile)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen legal analysis profile is missing');
                }
                (new LegalAnalysisProfileRegistry())->assertSnapshotCurrent($environmentId, $legalProfile);
            }
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            if (!is_array($input) || (int) ($input['workflow_run_id'] ?? 0) !== $runId
                || !hash_equals($fingerprint, (string) ($input['snapshot_sha256'] ?? ''))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow task snapshot changed');
            }
            $pending = (string) $task->state === TaskState::AUTHORIZATION_PENDING
                || ((string) $task->state === TaskState::FAILED && (int) $task->attempt_count === 0 && !isset($input['iam_authorization']));
            if ($pending) {
                $this->assertFrozenRunFilesCurrent($run);
                if ((string) $run->workflow_code === 'legal_analysis') {
                    if (!is_array($frozenInput) || !is_array($frozenInput['legal_analysis'] ?? null)) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The legal analysis input is unavailable');
                    }
                    $this->assertLegalSourcesCurrent(
                        $environmentId, $this->scopeForRun($run),
                        $frozenInput['legal_analysis'], $this->frozenRunFileSnapshots($run),
                    );
                }
                $taskUpdated = $task->save([
                    'state' => TaskState::QUEUED,
                    'input' => [...$input, 'iam_authorization' => $iamAuthorization],
                    'available_at' => date('Y-m-d H:i:s'),
                    'completed_at' => null,
                    'error_code' => null,
                    'error_summary' => null,
                ]);
                if ($taskUpdated !== true) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow task changed before enqueue');
                }
                $stepUpdated = TaskStep::where('task_id', (int) $task->id)
                    ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::FAILED])
                    ->update(['state' => TaskState::QUEUED, 'completed_at' => null, 'error_code' => null, 'error_summary' => null]);
                if ($stepUpdated !== 1) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow step is unavailable');
                }
                if (WorkflowRunFile::where('workflow_run_id', $runId)->update(WorkflowRunFileState::forTask(TaskState::QUEUED)) < 1) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized workflow source files are unavailable');
                }
                $this->audit($environmentId, 'workflow.queued', $runId, 'Queued SandAI structured workflow', ['workflow_code' => (string) $run->workflow_code, 'review_required' => (bool) $run->review_required]);
                $run->state_version = (new WorkflowStateRevision())->changed($runId);
            } elseif (in_array((string) $task->state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
                // Preserve and revalidate the original queued identity on replay.
                $originalAuthorization = $input['iam_authorization'] ?? null;
                if (!is_array($originalAuthorization)) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workflow authorization is unavailable');
                }
                (new WorkflowRunAccessGuard())->revalidateQueued($originalAuthorization, $run);
            }
            $result = ['idempotent_replay' => $replay, 'run' => $this->summary($run, false, $replay)];
            // The current request must remain authorized after result construction.
            (new WorkflowRunAccessGuard())->revalidateQueued($iamAuthorization, $run);
            return $result;
        });
    }

    private function assertCurrentMaterialProfile(int $environmentId, mixed $analysisProfile, bool $required, mixed $sampleMarker = null): void
    {
        if ($analysisProfile === null) {
            if ($required) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen material analysis profile is missing');
            }
            return;
        }
        if (!is_array($analysisProfile)
            || !is_string($analysisProfile['code'] ?? null)
            || !is_string($analysisProfile['version'] ?? null)
            || !is_array($analysisProfile['snapshot'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen material analysis profile is invalid');
        }
        if ($sampleMarker !== null) {
            if (!is_array($sampleMarker) || !is_int($sampleMarker['profile_id'] ?? null)
                || !is_int($sampleMarker['revision'] ?? null)
                || !is_string($sampleMarker['config_sha256'] ?? null)
                || !hash_equals($sampleMarker['config_sha256'], StructuredWorkflowPolicy::fingerprint($analysisProfile['snapshot']))) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft marker is invalid');
            }
            (new MaterialAnalysisSampleProfileRegistry())->assertSnapshotCurrent($environmentId, [
                'id' => $sampleMarker['profile_id'],
                'environment_id' => $environmentId,
                'code' => $analysisProfile['code'],
                'version' => $analysisProfile['version'],
                'revision' => $sampleMarker['revision'],
                'config' => $analysisProfile['snapshot'],
            ]);
            return;
        }
        (new MaterialAnalysisProfileRegistry())->assertSnapshotCurrent(
            $environmentId,
            $analysisProfile['code'],
            $analysisProfile['version'],
            $analysisProfile['snapshot'],
        );
    }

    private function rejectPendingAuthorization(int $runId, string $errorCode): void
    {
        Db::connect('pgsql')->transaction(function () use ($runId, $errorCode): void {
            $run = WorkflowRun::where('id', $runId)->find();
            if ($run === null) return;
            $task = TaskRun::where('id', (int) $run->task_id)->where('state', TaskState::AUTHORIZATION_PENDING)->lock(true)->find();
            if ($task === null) return;
            $now = date('Y-m-d H:i:s');
            $taskUpdated = $task->save(['state' => TaskState::FAILED, 'completed_at' => $now, 'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused']);
            $stepUpdated = TaskStep::where('task_id', (int) $task->id)->where('state', TaskState::AUTHORIZATION_PENDING)
                ->update(['state' => TaskState::FAILED, 'completed_at' => $now, 'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused']);
            if ($taskUpdated !== true || $stepUpdated !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow authorization state changed before refusal');
            }
            if (WorkflowRunFile::where('workflow_run_id', $runId)->update(WorkflowRunFileState::forTask(TaskState::FAILED, $errorCode)) < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow authorization source files are unavailable');
            }
            $this->audit((int) $run->environment_id, 'workflow.authorization_denied', $runId, 'SandAI structured workflow authorization was refused', ['error_code' => $errorCode]);
            (new WorkflowStateRevision())->terminalChanged($runId, TaskState::FAILED);
        });
    }

    public function sampleRunIdByRequestKey(int $environmentId, UsageAttribution $attribution, string $key): int
    {
        $run = $this->findRunByRequestKey($environmentId, $attribution, $key);
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (!is_array($input) || !is_array($input['material_profile_sample'] ?? null)
            || (string) $run->workflow_code !== 'material_analysis') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Material sample request not found');
        }
        return (int) $run->id;
    }

    public function runIdByRequestKey(int $environmentId, UsageAttribution $attribution, string $key): int
    {
        $run = $this->findRunByRequestKey($environmentId, $attribution, $key);
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (!is_array($input) || array_key_exists('material_profile_sample', $input)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Structured workflow request not found');
        }
        return (int) $run->id;
    }

    private function findRunByRequestKey(int $environmentId, UsageAttribution $attribution, string $key): WorkflowRun
    {
        if ($key === '' || mb_strlen($key, 'UTF-8') > 128) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid workflow request key is required');
        }
        try {
            $applicationScope = $attribution->applicationScope();
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Trusted application attribution is required');
        }
        $run = WorkflowRun::where('environment_id', $environmentId)
            ->where('idempotency_application_ref', $applicationScope)
            ->where('idempotency_key', $key)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null) {
            $applicationRef = $attribution->identitySnapshot['application_ref'] ?? null;
            if (!is_string($applicationRef) || trim($applicationRef) === '') {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Trusted application attribution is required');
            }
            $legacy = WorkflowRun::where('environment_id', $environmentId)
                ->whereNull('idempotency_application_ref')
                ->where('idempotency_key', $key)
                ->whereRaw("attribution_snapshot->'identity_snapshot'->>'application_ref' = ?", [$applicationRef])
                ->where('status', 1)->whereNull('delete_time')
                ->limit(2)->select();
            if (count($legacy) > 1) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Workflow request ownership is ambiguous');
            }
            $run = $legacy->first();
            if ($run !== null) {
                $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
                try {
                    $frozen = UsageAttribution::fromPersisted(
                        is_array($snapshot) ? $snapshot : null, $environmentId,
                        'structured_workflow', 'sand_ai.workflow.run',
                    );
                    if (!hash_equals($applicationScope, $frozen->applicationScope())) $run = null;
                } catch (\InvalidArgumentException) {
                    $run = null;
                }
            }
        }
        if ($run === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Structured workflow request not found');
        }
        return $run;
    }

    /** @return array<string, mixed> */
    public function read(int $environmentId, int $runId): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $runId): array {
            Db::connect('pgsql')->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return $this->readSnapshot($environmentId, $runId);
        });
    }

    /** @return array<string,mixed> */
    private function readSnapshot(int $environmentId, int $runId): array
    {
        $run = WorkflowRun::where('environment_id', $environmentId)->where('id', $runId)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Structured workflow run not found');
        }
        $summary = $this->summary($run, true, false);
        if ($run->content_purged_time !== null) {
            $summary['file_results'] = [];
            return $summary;
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if ((string) $run->workflow_code === 'material_analysis'
            && is_array($input) && ($input['material_execution_mode'] ?? null) === 'single_file_v1') {
            $summary['file_results'] = $this->readMaterialFileResults($run);
            $artifactPayload = $summary['artifact']['payload'] ?? null;
            if (is_array($artifactPayload)) {
                $sealedResults = [];
                foreach ($summary['file_results'] as $fileResult) {
                    $sealedResults[] = [
                        'file_id' => $fileResult['file_id'],
                        'payload' => $fileResult['result']['payload'],
                        'field_sources' => $fileResult['citations'],
                    ];
                }
                // Add location metadata to the response only. Historical
                // artifact bytes, sealed payload digests and billing stay fixed.
                $projected = (new MaterialConflictFieldLocator())->project($artifactPayload, $sealedResults);
                $summary['artifact']['payload'] = $projected;
                if (is_array($summary['result'] ?? null)) {
                    $summary['result']['payload'] = $projected;
                }
            }
            if (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->count() > 1) {
                $rows = Db::connect('pgsql')->query(
                    'SELECT review.state, review.attempt_count, review.error_code, '
                    . 'result.id AS result_id, result.attempt_no AS result_attempt_no, '
                    . 'result.invocation_id AS result_invocation_id, review.last_invocation_id '
                    . 'FROM sand_ai_workflow_cross_file_review AS review '
                    . 'LEFT JOIN sand_ai_workflow_cross_file_review_result AS result '
                    . 'ON result.workflow_run_id = review.workflow_run_id '
                    . 'WHERE review.workflow_run_id = ?',
                    [(int) $run->id],
                );
                $review = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
                if ($review === null || !in_array((string) $review['state'], [
                    'pending', 'running', 'recovery_pending', 'succeeded', 'failed', 'ambiguous', 'canceled',
                ], true) || ((string) $review['state'] === 'succeeded') !== ($review['result_id'] !== null)
                    || ($review['result_id'] !== null && (
                        (int) $review['attempt_count'] !== (int) $review['result_attempt_no']
                        || (int) $review['last_invocation_id'] !== (int) $review['result_invocation_id']
                    ))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is invalid');
                }
                $errorCode = (string) $review['error_code'];
                $summary['cross_file_review'] = [
                    'state' => (string) $review['state'],
                    'attempt_count' => (int) $review['attempt_count'],
                    'error_code' => in_array((string) $review['state'], ['recovery_pending', 'failed', 'ambiguous'], true)
                        && preg_match('/^SAND_AI_[A-Z0-9_]{1,96}$/D', $errorCode) === 1
                        ? $errorCode : null,
                ];
            }
        }
        if ((string) $run->workflow_code === 'material_analysis' && isset($summary['file_results'])) {
            $summary['file_results'] = $this->readMaterialFileResults($run, true);
        }
        return $summary;
    }

    /** @return array{source_block_id:int,file_id:int,locator_type:string,locator:array<string,mixed>,content:string,field_paths:list<string>} */
    public function source(int $environmentId, int $runId, int $sourceBlockId): array
    {
        if ($sourceBlockId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid source block id is required');
        }
        $detail = $this->read($environmentId, $runId);
        if ($detail['content_purged'] === true) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Workflow source not found');
        }
        if (!in_array(($detail['workflow']['code'] ?? null), ['material_analysis', 'legal_analysis'], true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Workflow source not found');
        }
        $sourceRun = WorkflowRun::where('environment_id', $environmentId)->where('id', $runId)->where('status', 1)->whereNull('delete_time')->find();
        if ($sourceRun !== null && (string) $sourceRun->workflow_code === 'material_analysis') {
            $detail['file_results'] = $this->readMaterialFileResults($sourceRun);
        }
        $matched = $this->matchingSourceReferences($detail, $sourceBlockId);
        if ($matched === []) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Material source not found');
        }
        $block = SourceBlock::where('id', $sourceBlockId)->where('status', 1)->whereNull('delete_time')->find();
        $run = WorkflowRun::where('environment_id', $environmentId)->where('id', $runId)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($block === null || $run === null) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A cited material source is unavailable');
        }
        return $this->currentSourcePreview($run, $block, $matched);
    }

    /** @param list<array<string,mixed>> $matched @return array{source_block_id:int,file_id:int,locator_type:string,locator:array<string,mixed>,content:string,field_paths:list<string>} */
    private function currentSourcePreview(WorkflowRun $run, SourceBlock $block, array $matched): array
    {
        $environmentId = (int) $run->environment_id;
        $runId = (int) $run->id;
        $runFile = WorkflowRunFile::where('workflow_run_id', $runId)->where('file_id', (int) $block->file_id)
            ->where('parse_id', (int) $block->parse_id)->where('status', 1)->whereNull('delete_time')->find();
        $scope = $this->scopeForRun($run);
        $file = AiFile::where('environment_id', $environmentId)->where('id', (int) $block->file_id)
            ->where('owner_scope', $scope)->where('status', 1)->whereNull('delete_time')->find();
        $parse = AiFileParse::where('id', (int) $block->parse_id)->where('file_id', (int) $block->file_id)
            ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->find();
        $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
        if ($runFile === null || $file === null || $parse === null || (string) $file->state !== 'parsed'
            || !hash_equals((string) $runFile->sha256, (string) $file->sha256)
            || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))
            || !is_array($locator)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A cited material source changed before reading');
        }
        $this->assertFrozenBlock($run, $block, $locator);
        (new FileOwnershipGuard())->assert($file, $scope);
        $fieldPaths = [];
        foreach ($matched as $reference) {
            if (($reference['file_id'] ?? null) !== (int) $block->file_id
                || ($reference['locator_type'] ?? null) !== (string) $block->locator_type
                || ($reference['locator'] ?? null) !== $locator
                || !is_string($reference['field_path'] ?? null)) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A cited material source changed before reading');
            }
            $fieldPaths[$reference['field_path']] = true;
        }
        return [
            'source_block_id' => (int) $block->id,
            'file_id' => (int) $block->file_id,
            'locator_type' => (string) $block->locator_type,
            'locator' => $locator,
            'content' => (string) $block->content,
            'field_paths' => array_keys($fieldPaths),
        ];
    }

    /** @param array<string,mixed> $detail @return list<array<string,mixed>> */
    private function matchingSourceReferences(array $detail, int $sourceBlockId): array
    {
        $references = is_array($detail['citations'] ?? null) ? $detail['citations'] : [];
        foreach ($detail['file_results'] ?? [] as $fileResult) {
            if (is_array($fileResult) && is_array($fileResult['citations'] ?? null)) {
                array_push($references, ...$fileResult['citations']);
            }
        }
        return array_values(array_filter($references, static fn (mixed $reference): bool =>
            is_array($reference) && ($reference['source_block_id'] ?? null) === $sourceBlockId
        ));
    }

    /** @return list<array<string,mixed>> */
    private function readMaterialFileResults(WorkflowRun $run, bool $paged = false): array
    {
        if (!$this->materialFileResultTableAvailable()) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Material-analysis payload verification schema is not installed');
        }
        $scope = $this->scopeForRun($run);
        $results = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->select() as $runFile) {
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
            if ($result === null) {
                if ((string) $runFile->analysis_state === 'succeeded') {
                    throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A successful material file has no sealed result');
                }
                continue;
            }
            $file = AiFile::where('environment_id', (int) $run->environment_id)
                ->where('id', (int) $runFile->file_id)->where('owner_scope', $scope)
                ->where('status', 1)->whereNull('delete_time')->find();
            $parse = AiFileParse::where('id', (int) $runFile->parse_id)
                ->where('file_id', (int) $runFile->file_id)->where('state', 'succeeded')
                ->where('status', 1)->whereNull('delete_time')->find();
            $invocation = Invocation::where('id', (int) $result->invocation_id)
                ->where('environment_id', (int) $run->environment_id)
                ->where('parent_workflow_run_id', (int) $run->id)
                ->where('parent_workflow_run_file_id', (int) $runFile->id)
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->find();
            $chunk = (new MaterialChunkCheckpoint())->read((int) $run->task_id, (int) $runFile->id);
            $rootAttempt = $chunk === null ? (int) $result->attempt_no
                : (int) ($chunk['nodes'][$chunk['plan']['root_node']]['attempt_no'] ?? 0);
            $attempt = new MaterialAnalysisFileAttemptIdentity(
                (string) $run->logical_execution_id, (int) $runFile->id, $rootAttempt,
            );
            if ($file === null || (string) $file->state !== 'parsed'
                || $parse === null || $invocation === null
                || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                || !hash_equals((string) $runFile->sha256, (string) $file->sha256)
                || (string) $runFile->analysis_state !== 'succeeded'
                || (int) $runFile->attempt_count !== (int) $result->attempt_no
                || (int) $runFile->last_invocation_id !== (int) $result->invocation_id
                || (int) $invocation->parent_task_run_id !== (int) $run->task_id
                || (int) $invocation->execution_attempt_no !== $rootAttempt
                || !hash_equals($attempt->requestId(), (string) $invocation->request_id)
                || !hash_equals($attempt->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)
                || (string) $result->schema_code !== (string) $run->output_schema_code
                || (string) $result->schema_version !== (string) $run->output_schema_version) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material file changed before reading');
            }
            (new FileOwnershipGuard())->assert($file, $scope);
            $payload = MaterialAnalysisFilePayloadDigest::verified(
                $result->payload, (string) $result->payload_sha256,
                $result->payload_digest_version, $result->content_purged_time,
            );
            $citations = [];
            foreach (WorkflowFileResultSource::where('result_id', (int) $result->id)->order('id')->select() as $source) {
                $block = SourceBlock::where('id', (int) $source->source_block_id)
                    ->where('file_id', (int) $runFile->file_id)->where('parse_id', (int) $runFile->parse_id)
                    ->where('status', 1)->find();
                if ($block === null || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
                    throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material citation is unavailable');
                }
                $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                $citations[] = [
                    'field_path' => (string) $source->field_path,
                    'source_block_id' => (int) $block->id,
                    'file_id' => (int) $runFile->file_id,
                    'locator_type' => (string) $block->locator_type,
                    'locator' => is_array($locator) ? $locator : [],
                ];
            }
            if (count($citations) !== (int) $result->field_source_count) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material result has incomplete citations');
            }
            $projection = [
                'file_id' => (int) $runFile->file_id,
                'attempt_count' => (int) $result->attempt_no,
                'result' => [
                    'schema' => ['code' => (string) $result->schema_code, 'version' => (string) $result->schema_version],
                    'payload' => $payload,
                ],
                'citations' => $citations,
            ];
            $results[] = $paged && $chunk !== null ? (new MaterialFileResultPager())->summary($projection) : $projection;
        }
        return $results;
    }

    /** Current authorization is supplied and revalidated by the official controller. */
    public function materialFileResultPage(int $environmentId, int $runId, int $fileId,
        string $section, ?string $extensionField, int $offset, int $limit): array
    {
        $detail = $this->read($environmentId, $runId);
        $run = WorkflowRun::where('environment_id', $environmentId)->where('id', $runId)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null || ($detail['content_purged'] ?? true) || (string) $run->workflow_code !== 'material_analysis') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Material file result not found');
        }
        if ($section === 'extensions' && !in_array($extensionField, $this->analysisProfile($run)['extension_fields'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The material extension field is outside the frozen profile');
        }
        foreach ($this->readMaterialFileResults($run) as $fileResult) {
            if ($fileResult['file_id'] === $fileId) {
                return (new MaterialFileResultPager())->page($fileResult, $section, $extensionField, $offset, $limit);
            }
        }
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Material file result not found');
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task, TaskExecutionLease $lease, ?\Closure $heartbeat = null): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (is_array($input) && is_array($input['_receipt_only_review'] ?? null)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Receipt-only intent cannot dispatch a Provider');
        }
        $runId = is_array($input) ? (int) ($input['workflow_run_id'] ?? 0) : 0;
        if ($runId <= 0) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The structured workflow task payload is invalid');
        }
        $run = WorkflowRun::where('environment_id', (int) $task->environment_id)->where('id', $runId)->find();
        if ($run === null || (int) $run->task_id !== (int) $task->id) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Structured workflow run not found');
        }
        if (!hash_equals((string) $run->snapshot_sha256, (string) ($input['snapshot_sha256'] ?? ''))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow snapshot is invalid');
        }
        if (!hash_equals((string) $run->execution_snapshot_sha256, (string) ($input['execution_snapshot_sha256'] ?? ''))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow execution snapshot is invalid');
        }
        $iamAuthorization = $input['iam_authorization'] ?? null;
        if (!is_array($iamAuthorization)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
        }
        (new WorkflowRunAccessGuard())->revalidateQueued($iamAuthorization, $run);
        $frozenInput = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (is_array($frozenInput) && array_key_exists('material_profile_sample', $frozenInput)) {
            Db::connect('pgsql')->transaction(function () use ($run, $frozenInput): void {
                $this->assertCurrentMaterialProfile(
                    (int) $run->environment_id, $frozenInput['analysis_profile'] ?? null, true,
                    $frozenInput['material_profile_sample'],
                );
            });
        }
        $definition = is_object($run->definition_snapshot) ? (array) $run->definition_snapshot : $run->definition_snapshot;
        if (!is_array($definition)
            || ($definition['version'] ?? null) !== (string) $run->workflow_version
            || ($definition['output_schema']['code'] ?? null) !== (string) $run->output_schema_code
            || ($definition['output_schema']['version'] ?? null) !== (string) $run->output_schema_version
        ) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen workflow definition is invalid');
        }
        $heartbeat?->__invoke();
        $files = $this->frozenSourceFiles((int) $run->id, $this->scopeForRun($run));
        $rebuiltExecutionSnapshot = $this->rebuildExecutionSnapshot($run, $definition, $files);
        if (!hash_equals((string) $run->execution_snapshot_sha256, $rebuiltExecutionSnapshot['sha256'])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The persisted execution snapshot no longer matches controlled inputs');
        }
        $heartbeat?->__invoke();
        if ((string) $run->workflow_code === 'material_analysis' && $this->materialExecutionMode($run) === 'single_file_v1') {
            MaterialFileOutputFailureEvidence::assertSchemaAvailable();
            return $this->executeMaterialFiles($run, $task, $lease, $iamAuthorization, $files, $heartbeat);
        }
        $manifest = match ((string) $run->workflow_code) {
            'source_manifest' => (new SourceManifestBuilder())->build($files),
            'material_analysis' => $this->materialAnalysisExecutor($run, $task, $lease, $iamAuthorization)->execute($this->analysisProfile($run), $files),
            'legal_analysis' => $this->executeLegalAnalysis($run, $task, $lease, $iamAuthorization, $files),
            default => throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', 'The frozen workflow cannot be executed'),
        };

        $providerOutcomeRetained = in_array((string) $run->workflow_code, ['material_analysis', 'legal_analysis'], true);
        try {
            if ($providerOutcomeRetained && $this->postMaterialHeartbeat !== null) {
                ($this->postMaterialHeartbeat)();
            } else {
                $heartbeat?->__invoke();
            }
            $invocationId = $providerOutcomeRetained
                ? ($this->terminalInvocationLookup === null ? $this->terminalInvocationId($run) : ($this->terminalInvocationLookup)($run))
                : null;
            if ($invocationId !== null && $this->beforeArtifactCommit !== null) {
                ($this->beforeArtifactCommit)($run, $task, $invocationId);
            }
            $commitContext = $providerOutcomeRetained
                ? StructuredArtifactCommitContext::normalMaterial()
                : StructuredArtifactCommitContext::providerFree();
            $artifactId = ($this->artifactWriter ?? new StructuredArtifactWriter())->commit($run, $task, $lease, $manifest, $invocationId, $commitContext);
        } catch (\Throwable $failure) {
            Log::warning('SandAI retained workflow outcome requires local recovery', [
                'workflow_run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'error_code' => $failure instanceof ApiProblem ? $failure->errorCode : null,
                'exception_type' => $failure::class,
                'exception_file' => basename($failure->getFile()),
                'exception_line' => $failure->getLine(),
            ]);
            throw (new RetainedOutcomeFailurePolicy())->map($providerOutcomeRetained, $failure);
        }
        return ['workflow_run_id' => (int) $run->id, 'artifact_id' => $artifactId, '_terminal_committed' => true];
    }

    /** @param list<array<string,mixed>> $files @param array<string,mixed> $iamAuthorization @return array<string,mixed> */
    private function executeMaterialFiles(
        WorkflowRun $run,
        TaskRun $task,
        TaskExecutionLease $lease,
        array $iamAuthorization,
        array $files,
        ?\Closure $heartbeat,
    ): array {
        $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->select();
        if (count($runFiles) < 1 || count($runFiles) !== count($files)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Fixed material execution file count changed');
        }
        $sourcesByFile = [];
        foreach ($files as $file) {
            $fileId = $file['file_id'] ?? null;
            if (!is_int($fileId) || isset($sourcesByFile[$fileId])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The fixed material files are duplicated');
            }
            $sourcesByFile[$fileId] = $file;
        }
        foreach ($runFiles as $runFile) {
            $sealed = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
            if ($sealed !== null) {
                MaterialAnalysisFilePayloadDigest::verified(
                    $sealed->payload, (string) $sealed->payload_sha256,
                    $sealed->payload_digest_version, $sealed->content_purged_time,
                );
            }
        }
        $profile = $this->analysisProfile($run);
        $failedFiles = false;
        foreach ($runFiles as $runFile) {
            $source = $sourcesByFile[(int) $runFile->file_id] ?? null;
            if ($source === null || (int) ($source['parse_id'] ?? 0) !== (int) $runFile->parse_id
                || !hash_equals((string) $runFile->sha256, (string) ($source['sha256'] ?? ''))
                || (string) $runFile->version_ref !== (string) ($source['version_ref'] ?? '')) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A fixed material file changed before execution');
            }
            $existing = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
            if ($existing !== null) {
                if ((string) $runFile->analysis_state !== 'succeeded'
                    || (int) $runFile->last_invocation_id !== (int) $existing->invocation_id
                    || (int) $runFile->attempt_count !== (int) $existing->attempt_no
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $existing->execution_snapshot_sha256)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A completed material file has inconsistent result evidence');
                }
                continue;
            }
            if ($this->confirmedIndependentMaterialFileFailure($run, $task, $runFile)) {
                $failedFiles = true;
                continue;
            }
            if ((string) $runFile->analysis_state !== 'running' || (int) $runFile->attempt_count < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material file is not ready for its next attempt');
            }
            $heartbeat?->__invoke();
            try {
                $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
                $executed = ($input['material_chunk_policy'] ?? null) === MaterialChunkCheckpoint::VERSION
                    ? (new MaterialChunkRunner())->execute($profile, $source, $run, $task, $runFile, $lease,
                        fn (?string $nodeId, int $attempt): MaterialAnalysisCompletionClient =>
                            $this->structuredCompletionClient($run, $task, $lease, $iamAuthorization, $runFile, $nodeId, $attempt), $heartbeat)
                    : $this->materialAnalysisExecutor($run, $task, $lease, $iamAuthorization, $runFile)->executeWithPrompt($profile, [$source]);
                if ($executed === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material chunk execution is unfinished');
            } catch (MaterialAnalysisOutputRejected $rejected) {
                $problem = new ApiProblem($rejected->errorCode, 'Material analysis output failed validation');
                if (!$this->isIndependentMaterialFileFailure($run, $task, $runFile, $problem, true)) {
                    throw $problem;
                }
                $this->recordIndependentMaterialFileFailure($run, $task, $runFile, $lease, $problem, true);
                $failedFiles = true;
                continue;
            } catch (ApiProblem $problem) {
                if (!$this->isIndependentMaterialFileFailure($run, $task, $runFile, $problem)) {
                    throw $problem;
                }
                $this->recordIndependentMaterialFileFailure($run, $task, $runFile, $lease, $problem);
                $failedFiles = true;
                continue;
            }
            if ($this->postMaterialHeartbeat !== null) {
                ($this->postMaterialHeartbeat)();
            } else {
                $heartbeat?->__invoke();
            }
            $invocationId = $executed['invocation_id'] ?? $this->terminalFileInvocationId($run, $runFile);
            (new MaterialAnalysisFileResultWriter())->store(
                $lease, (int) $run->id, (int) $runFile->id, $invocationId,
                $executed['prompt'], $executed['manifest'], false, $executed['chunk_plan'] ?? null,
            );
        }
        if ($failedFiles) {
            throw new ApiProblem('SAND_AI_MATERIAL_FILES_INCOMPLETE', 'One or more material files need a separate retry');
        }
        if (count($runFiles) > 1) {
            $heartbeat?->__invoke();
            $existingReview = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find();
            $materialInput = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
            if ($existingReview === null && ($materialInput['material_chunk_policy'] ?? null) === MaterialChunkCheckpoint::VERSION) {
                $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
                if ($review === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is missing');
                if ((string) $review->state === 'pending') $this->startCrossFileReview($run, $task, $lease);
                elseif ((string) $review->state !== 'running') throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review is not executable');
                $executedReview = (new MaterialCrossFileReviewChunkRunner())->execute($run, $task, $lease,
                    function (?string $batchId, int $attempt) use ($run, $task, $lease, $iamAuthorization): MaterialAnalysisCompletionClient {
                        $context = $this->crossFileReviewContext($run, $task, $lease, $iamAuthorization, $attempt, $batchId);
                        return ($this->materialAnalysisClients ?? new ProductionMaterialAnalysisCompletionClientFactory())->create($context);
                    }, $heartbeat);
                if ($executedReview === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Cross-file review is unfinished');
                $invocation = Invocation::where('id', $executedReview['invocation_id'])->find();
                if ($invocation === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Cross-file review original invocation is unavailable');
                $this->storeCrossFileReviewOutcome($lease, $run, $task, $invocation, $executedReview['input'], $executedReview['raw_json']);
            } elseif ($existingReview === null) {
                $attemptNo = null;
                (new NeuronMaterialReviewWorkflow(new NeuronMaterialReviewResources(
                    (int) $run->id,
                    $lease,
                    function (array $reviewInput) use ($run, $task, $lease, $iamAuthorization, &$attemptNo): string {
                        $attemptNo = $this->startCrossFileReview($run, $task, $lease);
                        $context = $this->crossFileReviewContext($run, $task, $lease, $iamAuthorization, $attemptNo);
                        return ($this->materialAnalysisClients ?? new ProductionMaterialAnalysisCompletionClientFactory())
                            ->create($context)->complete($reviewInput['messages']);
                    },
                    function (array $reviewInput, string $rawJson) use ($run, $task, $lease, $heartbeat, &$attemptNo): void {
                        if ($this->postMaterialHeartbeat !== null) {
                            ($this->postMaterialHeartbeat)();
                        } else {
                            $heartbeat?->__invoke();
                        }
                        if (!is_int($attemptNo) || $attemptNo < 1) {
                            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The terminal cross-file review attempt is unavailable');
                        }
                        $identity = new MaterialCrossFileReviewAttemptIdentity(
                            (string) $run->logical_execution_id, $attemptNo,
                        );
                        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
                            ->where('request_id', $identity->requestId())->where('state', 'succeeded')->find();
                        if ($invocation === null) {
                            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The terminal cross-file review invocation is unavailable');
                        }
                        $this->storeCrossFileReviewOutcome($lease, $run, $task, $invocation, $reviewInput, $rawJson);
                    },
                )))->review();
            }
        }
        $heartbeat?->__invoke();
        $artifactId = (new MaterialAnalysisFileFinalizer())->commit($run, $task, $lease);
        return ['workflow_run_id' => (int) $run->id, 'artifact_id' => $artifactId, '_terminal_committed' => true];
    }

    private function isIndependentMaterialFileFailure(
        WorkflowRun $run,
        TaskRun $task,
        WorkflowRunFile $runFile,
        ApiProblem $problem,
        bool $outputRejected = false,
    ): bool {
        if ((new MaterialChunkDispatchEvidence())->failed($run, $task, $runFile, $problem->errorCode)) return true;
        if ($outputRejected) {
            if (!in_array($problem->errorCode, [
                'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
            ], true)) {
                return false;
            }
            $dispatch = InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
                ->where('parent_task_run_id', (int) $task->id)
                ->where('parent_workflow_run_file_id', (int) $runFile->id)
                ->where('attempt_no', (int) $runFile->attempt_count)->find();
            return $dispatch !== null
                && $this->confirmedSucceededMaterialAttempt($run, $task, $runFile, $dispatch, true);
        }
        if (!in_array($problem->errorCode, [
            'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE',
            'SAND_AI_PROVIDER_REJECTED',
        ], true)) {
            return false;
        }
        $dispatches = InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
            ->where('parent_task_run_id', (int) $task->id)
            ->where('parent_workflow_run_file_id', (int) $runFile->id)
            ->where('attempt_no', (int) $runFile->attempt_count)->select();
        if ($problem->errorCode === 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE') {
            return count($dispatches) === 0;
        }
        return count($dispatches) === 1 && (string) $dispatches[0]->state === 'failed';
    }

    /** Persist a known per-file outcome before continuing with another file. */
    private function recordIndependentMaterialFileFailure(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $runFile,
        TaskExecutionLease $lease, ApiProblem $problem, bool $outputRejected = false,
    ): void {
        Db::connect('pgsql')->transaction(function () use ($run, $task, $runFile, $lease, $problem, $outputRejected): void {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $file = WorkflowRunFile::where('id', (int) $runFile->id)->lock(true)->find();
            if ($activeTask === null || $step === null || $lockedRun === null || $file === null
                || (int) $activeTask->id !== (int) $task->id
                || (string) $activeTask->state !== TaskState::RUNNING
                || (string) $step->state !== TaskState::RUNNING
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || $activeTask->lease_expire_time === null
                || (string) $activeTask->lease_expire_time <= date('Y-m-d H:i:s')
                || $activeTask->content_purged_time !== null
                || (int) $lockedRun->task_id !== (int) $task->id
                || (int) $lockedRun->environment_id !== (int) $activeTask->environment_id
                || (int) $lockedRun->status !== 1 || $lockedRun->delete_time !== null
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $lockedRun->execution_snapshot_sha256)
                || (int) $file->workflow_run_id !== (int) $lockedRun->id
                || (int) $file->status !== 1 || $file->delete_time !== null
                || (int) $file->file_id !== (int) $runFile->file_id
                || (int) $file->parse_id !== (int) $runFile->parse_id
                || !hash_equals((string) $runFile->sha256, (string) $file->sha256)
                || (string) $file->analysis_state !== 'running'
                || (int) $file->attempt_count !== (int) $runFile->attempt_count
                || $file->last_invocation_id !== null
                || WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material file failure lease changed');
            }
            // Recheck the terminal dispatch while cancellation and takeover are fenced.
            if (!$this->isIndependentMaterialFileFailure($lockedRun, $activeTask, $file, $problem, $outputRejected)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material file failure outcome changed');
            }
            $chunkFailure = (new MaterialChunkDispatchEvidence())->failed($lockedRun, $activeTask, $file, $problem->errorCode);
            if (!$chunkFailure && $problem->errorCode === 'SAND_AI_PROVIDER_REJECTED') {
                $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('parent_workflow_run_file_id', (int) $file->id)
                    ->where('attempt_no', (int) $file->attempt_count)->find();
                if ($dispatch === null || !$this->confirmedFailedMaterialAttempt($lockedRun, $activeTask, $file, $dispatch)) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material provider rejection is unverified');
                }
            }
            if ($outputRejected && !$chunkFailure) {
                $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('parent_workflow_run_file_id', (int) $file->id)
                    ->where('attempt_no', (int) $file->attempt_count)->find();
                if ($dispatch === null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material output failure dispatch is unavailable');
                }
                $this->recordMaterialOutputFailureEvidence($lockedRun, $activeTask, $file, $dispatch, $problem->errorCode);
            }
            if (WorkflowRunFile::where('id', (int) $file->id)->where('analysis_state', 'running')
                ->where('attempt_count', (int) $file->attempt_count)
                ->update(WorkflowRunFileState::forTask(TaskState::FAILED, $problem->errorCode)) !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material file failure changed');
            }
            (new WorkflowStateRevision())->changed((int) $lockedRun->id);
            if ((string) $activeTask->lease_expire_time <= date('Y-m-d H:i:s')) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material file failure lease expired');
            }
        });
    }

    private function confirmedIndependentMaterialFileFailure(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
    ): bool {
        if ((string) $file->analysis_state !== 'failed' || (int) $file->attempt_count < 1
            || $file->last_invocation_id !== null) {
            return false;
        }
        $errorCode = (string) $file->failure_summary;
        if ((new MaterialChunkDispatchEvidence())->failed($run, $task, $file, $errorCode)) return true;
        if ($errorCode === 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE') {
            return InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->where('parent_workflow_run_file_id', (int) $file->id)
                ->where('attempt_no', (int) $file->attempt_count)->count() === 0;
        }
        if (in_array($errorCode, [
            'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
        ], true)) {
            $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->where('parent_workflow_run_file_id', (int) $file->id)
                ->where('attempt_no', (int) $file->attempt_count)->find();
            $evidence = $dispatch === null ? null
                : (MaterialFileOutputFailureEvidence::byDispatchIds([(int) $dispatch->id])[(int) $dispatch->id] ?? null);
            return $dispatch !== null
                && MaterialFileOutputFailureEvidence::matches(
                    $evidence, (int) $run->id, (int) $file->id, (int) $task->id,
                    (int) $file->attempt_count, (int) $dispatch->invocation_id, (int) $dispatch->id,
                    (string) $run->execution_snapshot_sha256,
                )
                && ($evidence['error_code'] ?? null) === $errorCode
                && $this->confirmedSucceededMaterialAttempt($run, $task, $file, $dispatch);
        }
        if ($errorCode !== 'SAND_AI_PROVIDER_REJECTED') {
            return false;
        }
        $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
            ->where('parent_workflow_run_file_id', (int) $file->id)
            ->where('attempt_no', (int) $file->attempt_count)->find();
        return $dispatch !== null && $this->confirmedFailedMaterialAttempt($run, $task, $file, $dispatch);
    }

    private function terminalFileInvocationId(WorkflowRun $run, WorkflowRunFile $runFile): int
    {
        $identity = new MaterialAnalysisFileAttemptIdentity(
            (string) $run->logical_execution_id, (int) $runFile->id, (int) $runFile->attempt_count,
        );
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('request_id', $identity->requestId())->where('state', 'succeeded')->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The terminal material file invocation is unavailable');
        }
        return (int) $invocation->id;
    }

    private function startCrossFileReview(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease): int
    {
        return Db::connect('pgsql')->transaction(function () use ($run, $task, $lease): int {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($activeTask === null || $step === null || $lockedRun === null || $review === null
                || (int) $activeTask->id !== (int) $task->id
                || (int) $lockedRun->task_id !== (int) $task->id
                || (string) $activeTask->state !== TaskState::RUNNING
                || (string) $step->state !== TaskState::RUNNING
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || (string) $review->state !== 'pending'
                || $review->last_invocation_id !== null
                || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review is not ready to start');
            }
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            if (count($runFiles) < 2) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review has no complete file set');
            }
            foreach ($runFiles as $runFile) {
                $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
                if ((string) $runFile->analysis_state !== 'succeeded' || $result === null
                    || (int) $runFile->attempt_count !== (int) $result->attempt_no
                    || (int) $runFile->last_invocation_id !== (int) $result->invocation_id
                    || !hash_equals((string) $lockedRun->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material file is not sealed for review');
                }
            }
            $attemptNo = (int) $review->attempt_count + 1;
            if (WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)
                ->where('state', 'pending')->where('attempt_count', (int) $review->attempt_count)
                ->update([
                    'state' => 'running', 'attempt_count' => $attemptNo,
                    'max_output_tokens' => null, 'output_token_parameter' => null,
                    'error_code' => null, 'failure_summary' => null,
                ]) !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Cross-file review changed before dispatch');
            }
            (new WorkflowStateRevision())->changed((int) $run->id);
            return $attemptNo;
        });
    }

    /** @param array<string,mixed> $iamAuthorization */
    private function crossFileReviewContext(
        WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $iamAuthorization, int $attemptNo, ?string $materialReviewBatchId = null,
    ): StructuredCompletionContext {
        $route = (new CapabilityProfileResolver())->primary((int) $run->environment_id, 'structured_extraction');
        $deployment = is_array($route)
            ? ModelDeployment::where('id', (int) ($route['deployment_id'] ?? 0))->where('status', 1)->find() : null;
        $model = $deployment === null
            ? null : AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($snapshot) ? $snapshot : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run',
            );
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review attribution is invalid');
        }
        if ($deployment === null || $model === null || $step === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Cross-file review has no published route');
        }
        return new StructuredCompletionContext(
            (int) $run->environment_id, (int) $run->id, (int) $task->id, (int) $step->id,
            null, 'cross_file_review', $lease, (string) $run->execution_snapshot_sha256,
            (string) $run->logical_execution_id, $attribution, $attribution->applicationScope(),
            (string) $model->code, $iamAuthorization, $attemptNo, false, true,
            MaterialReviewOutputContract::fromSnapshot(is_object($run->execution_snapshot)
                ? (array) $run->execution_snapshot : $run->execution_snapshot),
            materialReviewBatchId: $materialReviewBatchId,
        );
    }

    private function materialExecutionMode(WorkflowRun $run): string
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $mode = is_array($input) ? ($input['material_execution_mode'] ?? null) : null;
        if ($mode === null) {
            return 'legacy_batch';
        }
        if ((string) $run->workflow_code !== 'material_analysis' || $mode !== 'single_file_v1') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material execution mode is invalid');
        }
        if (!$this->materialFileResultTableAvailable()) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Material-analysis payload verification schema is not installed');
        }
        return $mode;
    }

    private function materialFileResultTableAvailable(): bool
    {
        if (!MaterialFileOutputFailureEvidence::schemaAvailable()) {
            return false;
        }
        $rows = Db::connect('pgsql')->query(
            "SELECT CASE WHEN to_regclass('sand_ai_workflow_file_result') IS NOT NULL "
            . "AND to_regclass('sand_ai_workflow_file_result_source') IS NOT NULL "
            . "AND EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('sand_ai_workflow_file_result') "
            . "AND attname = 'payload_digest_version' AND atttypid = 'smallint'::regtype "
            . "AND NOT attnotnull AND NOT atthasdef AND NOT attisdropped) "
            . "AND EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_workflow_file_result') "
            . "AND conname = 'ck_sand_ai_workflow_file_result_digest_version' AND contype = 'c' AND convalidated "
            . "AND regexp_replace(pg_get_constraintdef(oid), '[[:space:]()]', '', 'g') "
            . "= 'CHECKpayload_digest_versionISNULLORpayload_digest_version=2') "
            . "AND EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_workflow_file_result') "
            . "AND conname = 'ck_sand_ai_workflow_file_result_source_count' "
            . "AND pg_get_constraintdef(oid) LIKE '%>= 0%') "
            . "AND EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_invocation_outcome_receipt') "
            . "AND conname = 'sand_ai_outcome_receipt_consumer_ack_check' "
            . "AND pg_get_constraintdef(oid) LIKE '%file_result%') THEN 1 ELSE 0 END AS available",
        );
        return is_array($rows) && isset($rows[0]) && is_array($rows[0])
            && (int) ($rows[0]['available'] ?? 0) === 1;
    }

    private function materialReviewTablesAvailable(): bool
    {
        if (!$this->materialFileResultTableAvailable()) {
            return false;
        }
        $rows = Db::connect('pgsql')->query(
            "SELECT CASE WHEN to_regclass('sand_ai_workflow_cross_file_review') IS NOT NULL "
            . "AND to_regclass('sand_ai_workflow_cross_file_review_result') IS NOT NULL "
            . "AND to_regclass('sand_ai_workflow_cross_file_review_source') IS NOT NULL "
            . "AND to_regclass('sand_ai_workflow_cross_file_review_withheld') IS NOT NULL "
            . "AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() "
            . "AND table_name = 'sand_ai_workflow_cross_file_review' AND column_name = 'max_output_tokens') "
            . "AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() "
            . "AND table_name = 'sand_ai_workflow_cross_file_review' AND column_name = 'output_token_parameter') "
            . "AND EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_invocation_outcome_receipt') "
            . "AND conname = 'sand_ai_outcome_receipt_consumer_ack_check' "
            . "AND pg_get_constraintdef(oid) LIKE '%cross_file_review%') THEN 1 ELSE 0 END AS available",
        );
        return is_array($rows) && isset($rows[0]) && is_array($rows[0])
            && (int) ($rows[0]['available'] ?? 0) === 1;
    }

    /** Receipt-only recovery. This path does not construct a completion client. @return array<string,mixed> */
    public function recoverOutcome(TaskRun $task, TaskExecutionLease $lease, bool $receiptOnly = false): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $marker = is_array($input) ? ($input['_receipt_only_review'] ?? null) : null;
        if (is_array($marker)) {
            if (($marker['version'] ?? null) !== 1 || ($marker['task_id'] ?? null) !== (int) $task->id
                || ($marker['workflow_run_id'] ?? null) !== (int) $task->resource_id
                || ($marker['local_recovery_no'] ?? null) !== (int) $task->local_recovery_count
                || !is_string($marker['intent_id'] ?? null)
                || preg_match('/\A[a-f0-9]{32}\z/D', $marker['intent_id']) !== 1
                || !is_int($marker['review_attempt_no'] ?? null) || $marker['review_attempt_no'] < 1
                || (int) WorkflowCrossFileReview::where('workflow_run_id', (int) $task->resource_id)
                    ->value('attempt_count') !== $marker['review_attempt_no']) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt-only recovery intent does not match this execution');
            }
            $receiptOnly = true;
        }
        if ($receiptOnly) (new MaterialReviewReceiptOnlyGuard())->assert($task);
        $runId = is_array($input) ? (int) ($input['workflow_run_id'] ?? 0) : 0;
        $run = $runId < 1 ? null : WorkflowRun::where('environment_id', (int) $task->environment_id)->where('id', $runId)->find();
        if ($run === null || (int) $run->task_id !== (int) $task->id
            || !in_array((string) $run->workflow_code, ['material_analysis', 'legal_analysis'], true)
            || !hash_equals((string) $run->snapshot_sha256, (string) ($input['snapshot_sha256'] ?? ''))
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) ($input['execution_snapshot_sha256'] ?? ''))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery workflow context is invalid');
        }
        $iamAuthorization = $input['iam_authorization'] ?? null;
        if (!is_array($iamAuthorization)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
        }
        (new WorkflowRunAccessGuard())->revalidateQueued($iamAuthorization, $run);
        $definition = is_object($run->definition_snapshot) ? (array) $run->definition_snapshot : $run->definition_snapshot;
        if (!is_array($definition) || ($definition['version'] ?? null) !== (string) $run->workflow_version) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen recovery workflow definition is invalid');
        }
        $files = $this->frozenSourceFiles((int) $run->id, $this->scopeForRun($run));
        $rebuilt = $this->rebuildExecutionSnapshot($run, $definition, $files);
        if (!hash_equals((string) $run->execution_snapshot_sha256, $rebuilt['sha256'])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery inputs no longer match the frozen execution');
        }
        if ((string) $run->workflow_code === 'material_analysis' && $this->materialExecutionMode($run) === 'single_file_v1') {
            MaterialFileOutputFailureEvidence::assertSchemaAvailable();
        }
        (new StructuredUsageRecovery())->recoverPending($run, $task, $lease);
        if ((string) $run->workflow_code === 'material_analysis' && $this->materialExecutionMode($run) === 'single_file_v1') {
            return $this->recoverMaterialFileOutcome($run, $task, $lease, $files, $receiptOnly);
        }
        $invocationId = $this->terminalInvocationId($run);
        $invocation = Invocation::where('id', $invocationId)->find();
        if ($invocation === null || !is_string($invocation->request_fingerprint) || $invocation->request_fingerprint === '') {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt recovery invocation is unavailable');
        }
        $receiptConfig = config('sand_ai_outcome_receipt');
        if (!is_array($receiptConfig)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
        }
        $content = (new OutcomeReceiptService(new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($receiptConfig), (int) ($receiptConfig['ttl_seconds'] ?? 0)))->replay([
            'environment_id' => (int) $run->environment_id, 'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => (string) $invocation->request_fingerprint, 'capability_code' => 'structured_extraction',
            'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ]);
        $content = StructuredProviderOutcome::content($content);
        if ((string) $run->workflow_code === 'legal_analysis') {
            $legal = $this->legalAnalysisInput($run);
            $resolved = $this->assertLegalSourcesCurrent(
                (int) $run->environment_id, $this->scopeForRun($run), $legal, $this->frozenRunFileSnapshots($run), false,
            );
            $this->assertResolvedLegalSourcesMatchFiles($resolved, $files);
            $prompt = (new LegalAnalysisPromptFactory())->build($legal, $resolved);
            try {
            $manifest = (new LegalAnalysisOutputValidator())->validate(
                $content, $legal['profile'], $prompt['source_block_ids'], $legal['sources'], $legal['facts'],
                \app\Application\Legal\LegalOutputContract::fromSnapshot((array) $run->execution_snapshot),
            );
            } catch (ApiProblem $failure) {
                if (!in_array($failure->errorCode, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'], true)) throw $failure;
                throw (new \app\Application\Legal\LegalOutputRejected($failure->errorCode))->atPaidOutcome(
                    (int) $run->id, $invocationId, 1, $content);
            }
        } else {
            $profile = (new MaterialAnalysisProfilePolicy())->validate($this->analysisProfile($run));
            $prompt = (new MaterialAnalysisPromptFactory())->build($profile, $files);
            $manifest = (new MaterialAnalysisOutputValidator())->validate($content, $profile, $files, $prompt->sourceBlockIds);
        }
        $artifactId = ($this->artifactWriter ?? new StructuredArtifactWriter())->commit(
            $run,
            $task,
            $lease,
            $manifest,
            $invocationId,
            StructuredArtifactCommitContext::receiptRecovery(),
        );
        return ['workflow_run_id' => (int) $run->id, 'artifact_id' => $artifactId, '_terminal_committed' => true, 'recovered_from' => 'outcome_receipt'];
    }

    /** @param list<array<string,mixed>> $files @return array<string,mixed> */
    private function recoverMaterialFileOutcome(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $files, bool $receiptOnly = false): array
    {
        $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->select();
        if (count($runFiles) < 1 || count($runFiles) !== count($files)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Fixed material recovery file count changed');
        }
        $sourcesByFile = [];
        foreach ($files as $file) {
            $sourcesByFile[(int) $file['file_id']] = $file;
        }
        $pending = [];
        $hasConfirmedFailure = false;
        foreach ($runFiles as $runFile) {
            $source = $sourcesByFile[(int) $runFile->file_id] ?? null;
            if ($source === null || (int) ($source['parse_id'] ?? 0) !== (int) $runFile->parse_id) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A receipt recovery file changed');
            }
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
            if ($result !== null) {
                if ((string) $runFile->analysis_state !== 'succeeded'
                    || (int) $runFile->last_invocation_id !== (int) $result->invocation_id
                    || (int) $runFile->attempt_count !== (int) $result->attempt_no
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A receipt recovery result is inconsistent');
                }
                continue;
            }
            if ($this->confirmedIndependentMaterialFileFailure($run, $task, $runFile)) {
                $hasConfirmedFailure = true;
                continue;
            }
            if ((string) $runFile->analysis_state !== 'recovery_pending') {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A receipt recovery file is not pending');
            }
            $chunk = (new MaterialChunkCheckpoint())->read((int) $task->id, (int) $runFile->id);
            if ($chunk !== null) {
                $profile = (new MaterialAnalysisProfilePolicy())->validate($this->analysisProfile($run));
                try {
                    $executed = (new MaterialChunkRunner())->execute($profile, $source, $run, $task, $runFile, $lease,
                        static function (): MaterialAnalysisCompletionClient {
                            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt recovery cannot dispatch a material node');
                        }, recovery: true);
                } catch (MaterialAnalysisOutputRejected|ApiProblem $problem) {
                    if (!(new MaterialChunkDispatchEvidence())->failed($run, $task, $runFile, $problem->errorCode)) throw $problem;
                    $this->recordRecoveredChunkFailure($run, $task, $runFile, $lease, $problem->errorCode);
                    $hasConfirmedFailure = true;
                    continue;
                }
                if ($executed !== null) {
                    (new MaterialAnalysisFileResultWriter())->store($lease, (int) $run->id, (int) $runFile->id,
                        $executed['invocation_id'], $executed['prompt'], $executed['manifest'], true, $executed['chunk_plan']);
                }
                continue;
            }
            $identity = new MaterialAnalysisFileAttemptIdentity(
                (string) $run->logical_execution_id, (int) $runFile->id, (int) $runFile->attempt_count,
            );
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)
                ->where('request_id', $identity->requestId())->where('state', 'succeeded')->find();
            $visible = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->where('parent_workflow_run_file_id', (int) $runFile->id)
                ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->order('id', 'desc')->find();
            if ($invocation !== null) {
                if ($visible === null || (int) $visible->invocation_id !== (int) $invocation->id
                    || (string) $visible->state !== 'succeeded'
                    || (int) $visible->attempt_no !== (int) $runFile->attempt_count) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material file dispatch cannot be recovered');
                }
                $pending[] = [$runFile, $source, $invocation];
            } elseif ($visible !== null && $this->confirmedFailedMaterialAttempt($run, $task, $runFile, $visible)) {
                // A different file can have a retained success receipt even
                // after this attempt was deterministically rejected.
                $hasConfirmedFailure = true;
            } elseif ($visible !== null) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'An unresolved material file dispatch has no terminal receipt');
            }
        }
        if (count($pending) > 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Several material file dispatches require recovery');
        }
        if (count($pending) === 1) {
            [$runFile, $source, $invocation] = $pending[0];
            if (!is_string($invocation->request_fingerprint) || $invocation->request_fingerprint === '') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material file recovery invocation is unavailable');
            }
            $receiptConfig = config('sand_ai_outcome_receipt');
            if (!is_array($receiptConfig)) {
                throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
            }
            $content = (new OutcomeReceiptService(
                new PostgresOutcomeReceiptStore(),
                new OutcomeReceiptCipher($receiptConfig),
                (int) ($receiptConfig['ttl_seconds'] ?? 0),
            ))->replay([
                'environment_id' => (int) $run->environment_id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint,
                'capability_code' => 'structured_extraction',
                'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
                'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
            ]);
            $content = StructuredProviderOutcome::content($content);
            $profile = (new MaterialAnalysisProfilePolicy())->validate($this->analysisProfile($run));
            $prompt = (new MaterialAnalysisPromptFactory())->build($profile, [$source]);
            try {
                $manifest = (new MaterialAnalysisOutputValidator())->validateProviderOutput(
                    $content, $profile, [$source], $prompt->sourceBlockIds,
                );
            } catch (MaterialAnalysisOutputRejected $problem) {
                $this->recordRecoveredInvalidMaterialFile(
                    $run, $task, $runFile, $lease, $problem->errorCode, count($runFiles) === 1,
                );
                if (count($runFiles) === 1) {
                    return ['workflow_run_id' => (int) $run->id,
                        'recovered_from' => 'outcome_receipt',
                        'state' => TaskState::FAILED, '_terminal_committed' => true];
                }
                $hasConfirmedFailure = true;
                $manifest = null;
            }
            if ($manifest !== null) {
                (new MaterialAnalysisFileResultWriter())->store(
                    $lease, (int) $run->id, (int) $runFile->id, (int) $invocation->id,
                    $prompt, $manifest, true,
                );
            }
        }
        if ($hasConfirmedFailure) {
            $this->completePartialMaterialRecovery($run, $task, $lease);
            return ['workflow_run_id' => (int) $run->id, 'recovered_from' => 'outcome_receipt',
                'state' => TaskState::FAILED, '_terminal_committed' => true];
        }
        $unfinished = WorkflowRunFile::where('workflow_run_id', (int) $run->id)
            ->where('analysis_state', '<>', 'succeeded')->count();
        if ($unfinished === 0) {
            if (count($runFiles) > 1) {
                $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
                $sealed = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find();
                if ($review === null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is missing');
                }
                if ($sealed === null && (string) $review->state === 'recovery_pending') {
                    $this->recoverCrossFileReview($run, $task, $lease, $review);
                    $sealed = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find();
                }
                if ($sealed === null) {
                    if ($receiptOnly) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt-only review recovery cannot enqueue model execution');
                    $this->resumeMaterialAfterReceipt($run, $task, $lease);
                    return ['workflow_run_id' => (int) $run->id,
                        'recovered_from' => 'outcome_receipt', 'state' => TaskState::RETRYING];
                }
            }
            $artifactId = (new MaterialAnalysisFileFinalizer())->commit($run, $task, $lease, true);
            return ['workflow_run_id' => (int) $run->id, 'artifact_id' => $artifactId,
                '_terminal_committed' => true, 'recovered_from' => 'outcome_receipt'];
        }
        if ($receiptOnly) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt-only review recovery cannot continue unfinished files');
        $this->resumeMaterialAfterReceipt($run, $task, $lease);
        return ['workflow_run_id' => (int) $run->id, 'recovered_from' => 'outcome_receipt', 'state' => TaskState::RETRYING];
    }

    /** A current, immutable rejection is distinct from an unresolved provider outcome. */
    private function confirmedFailedMaterialAttempt(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $runFile, InvocationDispatch $dispatch,
    ): bool {
        if ((string) $dispatch->state !== 'failed'
            || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->parent_workflow_run_id !== (int) $run->id
            || (int) $dispatch->parent_task_run_id !== (int) $task->id
            || (int) $dispatch->parent_workflow_run_file_id !== (int) $runFile->id
            || (int) $dispatch->attempt_no !== (int) $runFile->attempt_count
            || $runFile->last_invocation_id !== null
            || (int) $dispatch->application_id !== (int) $run->application_id
            || (string) $dispatch->source_kind !== 'structured_workflow'
            || (string) $dispatch->service_action !== 'sand_ai.workflow.run'
            || (string) $dispatch->workflow_node_code === 'cross_file_review'
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)) {
            return false;
        }
        $identity = new MaterialAnalysisFileAttemptIdentity(
            (string) $run->logical_execution_id, (int) $runFile->id, (int) $runFile->attempt_count,
        );
        $invocation = Invocation::where('id', (int) $dispatch->invocation_id)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        return $invocation !== null && (string) $invocation->state === 'failed'
            && $step !== null && (int) $step->id === (int) $dispatch->parent_task_step_id
            && (int) $invocation->status === 1 && $invocation->delete_time === null
            && (string) $invocation->error_code === 'SAND_AI_PROVIDER_REJECTED'
            && (int) $invocation->model_id === (int) $dispatch->model_id
            && (int) $invocation->deployment_id === (int) $dispatch->deployment_id
            && (int) $invocation->environment_id === (int) $run->environment_id
            && (int) $invocation->organization_id === (int) $run->organization_id
            && (int) $invocation->application_id === (int) $run->application_id
            && (int) $invocation->workload_client_id === (int) $run->workload_client_id
            && (int) $invocation->parent_workflow_run_id === (int) $run->id
            && (int) $invocation->parent_task_run_id === (int) $task->id
            && (int) $invocation->parent_task_step_id === (int) $dispatch->parent_task_step_id
            && (int) $invocation->parent_workflow_run_file_id === (int) $runFile->id
            && (int) $invocation->execution_attempt_no === (int) $runFile->attempt_count
            && (string) $invocation->request_id === $identity->requestId()
            && hash_equals($identity->dispatchLogicalExecutionId(), (string) $dispatch->logical_execution_id)
            && hash_equals($identity->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
            && hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256);
    }

    /** A valid, metered terminal call is required before rejecting its output as a file result. */
    private function confirmedSucceededMaterialAttempt(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $runFile,
        InvocationDispatch $dispatch, bool $requireAvailableReceipt = false,
    ): bool {
        $definition = is_object($run->definition_snapshot) ? (array) $run->definition_snapshot : $run->definition_snapshot;
        $fileNode = is_array($definition) && is_array($definition['steps'] ?? null)
            ? ($definition['steps'][0] ?? null) : null;
        if (!is_string($fileNode) || $fileNode === 'cross_file_review'
            || preg_match('/\A[a-z][a-z0-9_]{1,127}\z/D', $fileNode) !== 1
            || (int) $run->organization_id < 1
            || (int) $run->application_id < 1
            || (int) $run->workload_client_id < 1) {
            return false;
        }
        if ((string) $dispatch->state !== 'succeeded'
            || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->parent_workflow_run_id !== (int) $run->id
            || (int) $dispatch->parent_task_run_id !== (int) $task->id
            || (int) $dispatch->parent_workflow_run_file_id !== (int) $runFile->id
            || (int) $dispatch->attempt_no !== (int) $runFile->attempt_count
            || $runFile->last_invocation_id !== null
            || (int) $dispatch->application_id !== (int) $run->application_id
            || (string) $dispatch->source_kind !== 'structured_workflow'
            || (string) $dispatch->service_action !== 'sand_ai.workflow.run'
            || (string) $dispatch->workflow_node_code !== $fileNode
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)) {
            return false;
        }
        $identity = new MaterialAnalysisFileAttemptIdentity(
            (string) $run->logical_execution_id, (int) $runFile->id, (int) $runFile->attempt_count,
        );
        $invocation = Invocation::where('id', (int) $dispatch->invocation_id)->find();
        $receipt = InvocationOutcomeReceipt::where(
            'invocation_id', (int) $dispatch->invocation_id,
        )->find();
        $usage = UsageEvent::where('invocation_id', (int) $dispatch->invocation_id)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        return $invocation !== null && (string) $invocation->state === 'succeeded'
            && $receipt !== null && $usage !== null && $step !== null
            && (!$requireAvailableReceipt || (
                (string) $receipt->state === 'available'
                && (string) $receipt->expire_time > gmdate('Y-m-d H:i:s')
            ))
            && (int) $step->id === (int) $dispatch->parent_task_step_id
            && (int) $invocation->status === 1 && $invocation->delete_time === null
            && (int) $invocation->environment_id === (int) $run->environment_id
            && (int) $invocation->organization_id === (int) $run->organization_id
            && (int) $invocation->application_id === (int) $run->application_id
            && (int) $invocation->workload_client_id === (int) $run->workload_client_id
            && (int) $invocation->parent_workflow_run_id === (int) $run->id
            && (int) $invocation->parent_task_run_id === (int) $task->id
            && (int) $invocation->parent_task_step_id === (int) $dispatch->parent_task_step_id
            && (int) $invocation->parent_workflow_run_file_id === (int) $runFile->id
            && (string) $invocation->workflow_node_code === $fileNode
            && (int) $invocation->execution_attempt_no === (int) $runFile->attempt_count
            && (string) $invocation->request_id === $identity->requestId()
            && hash_equals($identity->dispatchLogicalExecutionId(), (string) $dispatch->logical_execution_id)
            && hash_equals(hash('sha256', $identity->requestId()), (string) $dispatch->logical_request_id)
            && hash_equals($identity->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
            && hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
            && (int) $receipt->environment_id === (int) $run->environment_id
            && hash_equals((string) $invocation->request_fingerprint, (string) $receipt->request_fingerprint)
            && (int) $receipt->model_id === (int) $invocation->model_id
            && (int) $receipt->deployment_id === (int) $invocation->deployment_id
            && (string) $receipt->capability_code === 'structured_extraction'
            && (int) $usage->environment_id === (int) $run->environment_id
            && (int) $usage->organization_id === (int) $run->organization_id
            && (int) $usage->application_id === (int) $run->application_id
            && (int) $usage->workload_client_id === (int) $run->workload_client_id
            && (int) $usage->model_id === (int) $invocation->model_id
            && (int) $usage->deployment_id === (int) $invocation->deployment_id
            && (int) $usage->provider_id === (int) $dispatch->provider_id
            && (int) $usage->model_id === (int) $dispatch->model_id
            && (int) $usage->deployment_id === (int) $dispatch->deployment_id
            && (string) $usage->source_kind === 'structured_workflow'
            && (string) $usage->service_action === 'sand_ai.workflow.run'
            && (string) $usage->capability_code === 'structured_extraction'
            && (string) $usage->idempotency_key === 'invocation:' . (int) $invocation->id
            && (string) $usage->usage_state === 'measured';
    }

    /** Caller holds the task/run/file lease locks; insert and file state share one transaction. */
    private function recordMaterialOutputFailureEvidence(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        InvocationDispatch $dispatch, string $errorCode,
    ): void {
        if (!in_array($errorCode, [
            'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
        ], true) || !$this->confirmedSucceededMaterialAttempt($run, $task, $file, $dispatch, true)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material output failure is not verified');
        }
        $receipt = InvocationOutcomeReceipt::where('invocation_id', (int) $dispatch->invocation_id)->find();
        $usage = UsageEvent::where('invocation_id', (int) $dispatch->invocation_id)->find();
        if ($receipt === null || $usage === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material output failure facts are unavailable');
        }
        Db::connect('pgsql')->table('sand_ai_workflow_file_output_failure')->insert([
            'workflow_run_id' => (int) $run->id,
            'workflow_run_file_id' => (int) $file->id,
            'task_run_id' => (int) $task->id,
            'attempt_no' => (int) $file->attempt_count,
            'invocation_id' => (int) $dispatch->invocation_id,
            'dispatch_id' => (int) $dispatch->id,
            'receipt_id' => (int) $receipt->id,
            'usage_event_id' => (int) $usage->id,
            'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
            'error_code' => $errorCode,
        ]);
    }

    private function recordRecoveredChunkFailure(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        TaskExecutionLease $lease, string $errorCode): void
    {
        Db::connect('pgsql')->transaction(function () use ($run, $task, $file, $lease, $errorCode): void {
            $active = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $current = WorkflowRunFile::where('id', (int) $file->id)->where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($active === null || $step === null || $current === null
                || (string) $active->state !== TaskState::RECOVERY_PENDING || (string) $step->state !== TaskState::FAILED
                || (string) $current->analysis_state !== 'recovery_pending'
                || (int) $current->attempt_count !== (int) $file->attempt_count
                || (string) $active->lease_owner !== $lease->owner || !hash_equals((string) $active->lease_token, $lease->token)
                || (int) $active->lease_fence !== $lease->fence || $active->lease_expire_time === null
                || (string) $active->lease_expire_time <= date('Y-m-d H:i:s')
                || !(new MaterialChunkDispatchEvidence())->failed($run, $active, $current, $errorCode)) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material node receipt failure lease changed');
            }
            if (WorkflowRunFile::where('id', (int) $current->id)->where('analysis_state', 'recovery_pending')
                ->where('attempt_count', (int) $file->attempt_count)->update(WorkflowRunFileState::forTask(TaskState::FAILED, $errorCode)) !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material node receipt failure changed');
            }
            (new WorkflowStateRevision())->changed((int) $run->id);
        });
    }

    /** Convert a replayed, invalid terminal response into a durable file failure. */
    private function recordRecoveredInvalidMaterialFile(
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $runFile,
        TaskExecutionLease $lease, string $errorCode, bool $terminalSingleFile,
    ): void {
        Db::connect('pgsql')->transaction(function () use ($run, $task, $runFile, $lease, $errorCode, $terminalSingleFile): void {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $file = WorkflowRunFile::where('id', (int) $runFile->id)->lock(true)->find();
            if ($activeTask === null || $step === null || $lockedRun === null || $file === null
                || (int) $activeTask->id !== (int) $task->id
                || (int) $lockedRun->task_id !== (int) $task->id
                || (string) $activeTask->state !== TaskState::RECOVERY_PENDING
                || (string) $step->state !== TaskState::FAILED
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || $activeTask->lease_expire_time === null
                || (string) $activeTask->lease_expire_time <= date('Y-m-d H:i:s')
                || $activeTask->content_purged_time !== null
                || (int) $lockedRun->environment_id !== (int) $activeTask->environment_id
                || (int) $lockedRun->status !== 1 || $lockedRun->delete_time !== null
                || $lockedRun->content_purged_time !== null
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $lockedRun->execution_snapshot_sha256)
                || (int) $file->workflow_run_id !== (int) $lockedRun->id
                || (int) $file->status !== 1 || $file->delete_time !== null
                || (string) $file->analysis_state !== 'recovery_pending'
                || (int) $file->attempt_count !== (int) $runFile->attempt_count
                || (int) $file->file_id !== (int) $runFile->file_id
                || (int) $file->parse_id !== (int) $runFile->parse_id
                || !hash_equals((string) $file->sha256, (string) $runFile->sha256)
                || $file->last_invocation_id !== null
                || WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material output recovery lease changed');
            }
            $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->where('parent_workflow_run_file_id', (int) $file->id)
                ->where('attempt_no', (int) $file->attempt_count)->find();
            if ($dispatch === null
                || !$this->confirmedSucceededMaterialAttempt($lockedRun, $activeTask, $file, $dispatch, true)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material output failure evidence changed');
            }
            $this->recordMaterialOutputFailureEvidence($lockedRun, $activeTask, $file, $dispatch, $errorCode);
            if (WorkflowRunFile::where('id', (int) $file->id)->where('analysis_state', 'recovery_pending')
                ->where('attempt_count', (int) $file->attempt_count)
                ->update(WorkflowRunFileState::forTask(TaskState::FAILED, $errorCode)) !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material output failure changed');
            }
            if ($terminalSingleFile) {
                if (WorkflowRunFile::where('workflow_run_id', (int) $lockedRun->id)->count() !== 1
                    || WorkflowCrossFileReview::where('workflow_run_id', (int) $lockedRun->id)->find() !== null
                    || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $lockedRun->id)->find() !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Single-file recovery scope changed');
                }
                $now = date('Y-m-d H:i:s');
                $summary = 'One or more material files need a separate retry';
                $taskUpdated = TaskRun::where('id', $lease->taskId)
                    ->where('state', TaskState::RECOVERY_PENDING)
                    ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                    ->where('lease_fence', $lease->fence)->where('lease_expire_time', '>', $now)->update([
                        'state' => TaskState::FAILED,
                        'error_code' => 'SAND_AI_MATERIAL_FILES_INCOMPLETE',
                        'error_summary' => $summary,
                        'completed_at' => $now, 'locked_at' => null, 'locked_by' => null,
                        'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null,
                        'heartbeat_time' => null, 'version' => Db::raw('version + 1'),
                    ]);
                $stepUpdated = TaskStep::where('id', (int) $step->id)
                    ->where('state', TaskState::FAILED)->update([
                        'completed_at' => $now,
                        'error_code' => 'SAND_AI_MATERIAL_FILES_INCOMPLETE',
                        'error_summary' => $summary,
                    ]);
                if ($taskUpdated !== 1 || $stepUpdated !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material output recovery task changed');
                }
                $this->audit((int) $lockedRun->environment_id, 'workflow.material_output_rejected',
                    (int) $lockedRun->id, 'Retained a measured material response for explicit file retry', [
                        'task_id' => (int) $task->id, 'file_count' => 1,
                        'error_code' => $errorCode, 'provider_called' => false,
                    ]);
                (new WorkflowStateRevision())->terminalChanged((int) $lockedRun->id, TaskState::FAILED);
            } else {
                (new WorkflowStateRevision())->changed((int) $lockedRun->id);
            }
        });
    }

    /** Preserve recovered successes and leave failed files for an explicit retry. */
    private function completePartialMaterialRecovery(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease): void
    {
        Db::connect('pgsql')->transaction(function () use ($run, $task, $lease): void {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $now = date('Y-m-d H:i:s');
            if ($activeTask === null || $step === null || $lockedRun === null
                || (int) $activeTask->id !== (int) $task->id
                || (int) $lockedRun->task_id !== (int) $task->id
                || (int) $lockedRun->environment_id !== (int) $activeTask->environment_id
                || (string) $activeTask->state !== TaskState::RECOVERY_PENDING
                || (string) $step->state !== TaskState::FAILED
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || $activeTask->lease_expire_time === null || (string) $activeTask->lease_expire_time <= $now
                || $activeTask->content_purged_time !== null
                || (int) $lockedRun->status !== 1 || $lockedRun->delete_time !== null
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $lockedRun->execution_snapshot_sha256)) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Partial material recovery lease changed');
            }
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            $results = [];
            $fileStates = [];
            $unfinished = [];
            $confirmedFailure = false;
            $preservedFailureCount = 0;
            foreach ($runFiles as $file) {
                $fileId = (int) $file->id;
                $result = WorkflowFileResult::where('workflow_run_file_id', $fileId)->find();
                $fileStates[$fileId] = [
                    'analysis_state' => $result === null ? 'failed' : (string) $file->analysis_state,
                    'file_attempt_no' => (int) $file->attempt_count,
                    'last_invocation_id' => $file->last_invocation_id === null ? null : (int) $file->last_invocation_id,
                    'failure_summary' => $file->failure_summary === null ? null : (string) $file->failure_summary,
                    'chunk_dispatch_fence' => (new MaterialChunkCheckpoint())->read((int) $task->id, $fileId) !== null,
                ];
                if ($result !== null) {
                    MaterialAnalysisFilePayloadDigest::verified(
                        $result->payload, (string) $result->payload_sha256,
                        $result->payload_digest_version, $result->content_purged_time,
                    );
                    if (!hash_equals((string) $lockedRun->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Recovered material result changed');
                    }
                    $results[$fileId] = [
                        'invocation_id' => (int) $result->invocation_id, 'attempt_no' => (int) $result->attempt_no,
                        'invocation_attempt_no' => (new MaterialChunkDispatchEvidence())->resultAttempt($lockedRun, $activeTask, $file, $result),
                        ...$fileStates[$fileId],
                    ];
                    continue;
                }
                if ($this->confirmedIndependentMaterialFileFailure($lockedRun, $activeTask, $file)) {
                    $confirmedFailure = true;
                    ++$preservedFailureCount;
                    continue;
                }
                if ((string) $file->analysis_state !== 'recovery_pending' || $file->last_invocation_id !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Unfinished material recovery file changed');
                }
                $visible = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('parent_workflow_run_file_id', $fileId)
                    ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->order('id', 'desc')->find();
                if ($visible !== null) {
                    $chunk = (new MaterialChunkCheckpoint())->read((int) $task->id, (int) $file->id);
                    if (($chunk === null || (new MaterialChunkDispatchEvidence())->unresolved($lockedRun, $activeTask, $file))
                        && !$this->confirmedFailedMaterialAttempt($lockedRun, $activeTask, $file, $visible)) {
                        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material recovery still has an unresolved outcome');
                    }
                    $confirmedFailure = true;
                }
                $unfinished[] = $file;
            }
            if (!$confirmedFailure || count($runFiles) < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Partial material recovery has no confirmed failed file');
            }
            $review = null;
            if (count($runFiles) > 1) {
                $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
                if ($review === null || (string) $review->state !== 'pending' || $review->last_invocation_id !== null
                    || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Partial material recovery review changed');
                }
            } elseif (WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find() !== null
                || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Single-file recovery has unexpected review evidence');
            }
            $dispatches = [];
            foreach (InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->order('id', 'desc')->select() as $dispatch) {
                $dispatches[] = [
                    'id' => (int) $dispatch->id, 'invocation_id' => (int) $dispatch->invocation_id,
                    'parent_workflow_run_id' => (int) $dispatch->parent_workflow_run_id,
                    'parent_task_run_id' => (int) $dispatch->parent_task_run_id,
                    'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
                    'parent_workflow_run_file_id' => $dispatch->parent_workflow_run_file_id === null
                        ? null : (int) $dispatch->parent_workflow_run_file_id,
                    'workflow_node_code' => (string) $dispatch->workflow_node_code,
                    'attempt_no' => (int) $dispatch->attempt_no, 'state' => (string) $dispatch->state,
                ];
            }
            $reviewFacts = $review === null ? null : [
                'state' => 'pending', 'attempt_count' => (int) $review->attempt_count,
                'last_invocation_id' => null, 'result_attempt_no' => null, 'result_invocation_id' => null,
            ];
            if ($reviewFacts !== null) $reviewFacts['chunk_dispatch_fence'] = (new MaterialCrossFileReviewChunkRunner())->active($lockedRun, $activeTask);
            if (MaterialFileDispatchFence::unresolved(
                (int) $run->id, $dispatches, $results, $reviewFacts, $fileStates,
                MaterialFileOutputFailureEvidence::byDispatchIds(array_column($dispatches, 'id')),
                (new MaterialChunkDispatchEvidence())->settled($lockedRun, $activeTask)
                    + (new MaterialCrossFileReviewChunkRunner())->settledDispatchIds($lockedRun, $activeTask),
            ) !== null) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Partial material recovery has an unresolved dispatch');
            }
            $errorCode = 'SAND_AI_MATERIAL_FILES_INCOMPLETE';
            foreach ($unfinished as $file) {
                if (WorkflowRunFile::where('id', (int) $file->id)->where('analysis_state', 'recovery_pending')
                    ->where('attempt_count', (int) $file->attempt_count)
                    ->update(WorkflowRunFileState::forTask(TaskState::FAILED, $errorCode)) !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A partial recovery file changed');
                }
            }
            $summary = 'One or more material files need a separate retry';
            $taskUpdated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RECOVERY_PENDING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->where('lease_expire_time', '>', date('Y-m-d H:i:s'))->update([
                    'state' => TaskState::FAILED, 'error_code' => $errorCode, 'error_summary' => $summary,
                    'completed_at' => $now, 'locked_at' => null, 'locked_by' => null, 'lease_owner' => null,
                    'lease_token' => null, 'lease_expire_time' => null, 'heartbeat_time' => null,
                    'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', TaskState::FAILED)->update([
                'completed_at' => $now, 'error_code' => $errorCode, 'error_summary' => $summary,
            ]);
            if ($taskUpdated !== 1 || $stepUpdated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Partial material recovery task changed');
            }
            $this->audit((int) $run->environment_id, 'workflow.material_partial_recovered', (int) $run->id,
                'Preserved recovered material successes for explicit failed-file retry', [
                    'task_id' => (int) $task->id,
                    'failed_file_count' => count($unfinished) + $preservedFailureCount,
                    'provider_called' => false,
                ]);
            (new WorkflowStateRevision())->terminalChanged((int) $run->id, TaskState::FAILED);
        });
    }

    private function recoverCrossFileReview(
        WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, WorkflowCrossFileReview $review,
    ): void {
        $materialInput = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (($materialInput['material_chunk_policy'] ?? null) === MaterialChunkCheckpoint::VERSION) {
            $executed = (new MaterialCrossFileReviewChunkRunner())->execute($run, $task, $lease,
                static function (): MaterialAnalysisCompletionClient {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt recovery cannot dispatch a cross-file batch');
                }, recovery: true);
            if ($executed === null) return;
            $invocation = Invocation::where('id', $executed['invocation_id'])->find();
            if ($invocation === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Cross-file recovery original invocation is unavailable');
            $this->storeCrossFileReviewOutcome($lease, $run, $task, $invocation, $executed['input'], $executed['raw_json'], true);
            return;
        }
        $identity = new MaterialCrossFileReviewAttemptIdentity(
            (string) $run->logical_execution_id, (int) $review->attempt_count,
        );
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('request_id', $identity->requestId())->where('state', 'succeeded')->find();
        $visible = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
            ->where('workflow_node_code', 'cross_file_review')
            ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())
            ->order('id', 'desc')->find();
        if ($invocation === null || $visible === null
            || (int) $visible->invocation_id !== (int) $invocation->id
            || (int) $visible->attempt_no !== (int) $review->attempt_count
            || (string) $visible->state !== 'succeeded'
            || !is_string($invocation->request_fingerprint)
            || $invocation->request_fingerprint === '') {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Cross-file review dispatch cannot be recovered');
        }
        $receiptConfig = config('sand_ai_outcome_receipt');
        if (!is_array($receiptConfig)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
        }
        $content = (new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($receiptConfig),
            (int) ($receiptConfig['ttl_seconds'] ?? 0),
        ))->replay([
            'environment_id' => (int) $run->environment_id,
            'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => (string) $invocation->request_fingerprint,
            'capability_code' => 'structured_extraction',
            'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id,
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ]);
        $content = StructuredProviderOutcome::content($content);
        $input = (new MaterialCrossFileReviewInputLoader())->load((int) $run->id, $lease);
        $this->storeCrossFileReviewOutcome($lease, $run, $task, $invocation, $input, $content, true);
    }

    /** @param array<string,mixed> $input */
    private function storeCrossFileReviewOutcome(
        TaskExecutionLease $lease, WorkflowRun $run, TaskRun $task, Invocation $invocation,
        array $input, string $content, bool $recovery = false,
    ): void {
        try {
            (new MaterialCrossFileReviewResultWriter())->store(
                $lease, (int) $run->id, (int) $invocation->id, $input, $content, $recovery,
            );
        } catch (MaterialReviewOutputRejected $failure) {
            MaterialOutputFailureDiagnostics::record($failure, (int) $task->id,
                (int) $invocation->parent_task_step_id, (int) $run->id, 'cross_file_review_output_validation');
            $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
            if ($review === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Review rejection identity is unavailable');
            throw $failure->atPaidOutcome((int) $run->id, (int) $invocation->id, (int) $review->attempt_count, $content);
        } catch (\Throwable $failure) {
            MaterialOutputFailureDiagnostics::record($failure, (int) $task->id,
                (int) $invocation->parent_task_step_id, (int) $run->id, 'cross_file_review_result_persistence');
            throw $failure;
        }
    }

    private function resumeMaterialAfterReceipt(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease): void
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (is_array($input) && is_array($input['_receipt_only_review'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Receipt-only intent cannot enqueue a Provider continuation');
        }
        Db::connect('pgsql')->transaction(function () use ($run, $task, $lease): void {
            $activeTask = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $lockedRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            if ($activeTask === null || $step === null || $lockedRun === null
                || (int) $activeTask->id !== (int) $task->id
                || (int) $lockedRun->task_id !== (int) $task->id
                || (string) $activeTask->state !== TaskState::RECOVERY_PENDING
                || (string) $step->state !== TaskState::FAILED
                || (string) $activeTask->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $activeTask->lease_token)
                || (int) $activeTask->lease_fence !== $lease->fence
                || (int) $activeTask->attempt_count >= (int) $activeTask->max_attempts) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material file recovery cannot resume this task');
            }
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            $remaining = 0;
            foreach ($runFiles as $runFile) {
                if ((string) $runFile->analysis_state === 'succeeded') {
                    $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find();
                    if ($result === null || (int) $runFile->last_invocation_id !== (int) $result->invocation_id
                        || (int) $runFile->attempt_count !== (int) $result->attempt_no) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A recovered file result changed');
                    }
                    continue;
                }
                if ((string) $runFile->analysis_state !== 'recovery_pending'
                    || WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->find() !== null
                    || ((new MaterialChunkCheckpoint())->read((int) $task->id, (int) $runFile->id) === null
                        ? InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                            ->where('parent_workflow_run_file_id', (int) $runFile->id)
                            ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->find() !== null
                        : (new MaterialChunkDispatchEvidence())->unresolved($lockedRun, $activeTask, $runFile))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'An unfinished file cannot resume');
                }
                ++$remaining;
                if (WorkflowRunFile::where('id', (int) $runFile->id)
                    ->where('analysis_state', 'recovery_pending')
                    ->update(WorkflowRunFileState::forTask(TaskState::RETRYING)) !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A material file changed during recovery');
                }
            }
            if (count($runFiles) > 1) {
                $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
                if ($review === null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is missing');
                }
                $crossChunks = new MaterialCrossFileReviewChunkRunner();
                if ($crossChunks->active($lockedRun, $activeTask) && (string) $review->state === 'recovery_pending'
                    && WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() === null) {
                    if (!$crossChunks->canResume($lockedRun, $activeTask)) {
                        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Cross-file original batch outcome remains unresolved');
                    }
                    if (WorkflowCrossFileReview::where('id', (int) $review->id)->where('state', 'recovery_pending')
                        ->where('attempt_count', (int) $review->attempt_count)->update(['state' => 'running', 'error_code' => null, 'failure_summary' => null]) !== 1) {
                        throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Cross-file batch continuation changed');
                    }
                    ++$remaining;
                } elseif ((string) $review->state === 'pending'
                    && WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() === null
                    && InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                        ->where('workflow_node_code', 'cross_file_review')
                        ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->find() === null) {
                    ++$remaining;
                }
            }
            if ($remaining < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery has no unfinished file');
            }
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RECOVERY_PENDING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->update([
                    'state' => TaskState::RETRYING, 'available_at' => $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null,
                    'error_code' => null, 'error_summary' => null, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', TaskState::FAILED)->update([
                'state' => TaskState::RETRYING, 'completed_at' => null,
                'error_code' => null, 'error_summary' => null,
            ]);
            if ($updated !== 1 || $stepUpdated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material file recovery state changed');
            }
            (new WorkflowStateRevision())->changed((int) $run->id);
        });
    }

    private function terminalInvocationId(WorkflowRun $run): int
    {
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('request_id', 'structured_' . (string) $run->logical_execution_id)->where('state', 'succeeded')->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The terminal material-analysis invocation is unavailable');
        }
        return (int) $invocation->id;
    }

    /** @param array<string,mixed> $legal @param list<array<string,mixed>> $files @return array{sources:list<array<string,mixed>>} */
    private function assertLegalSourcesCurrent(int $environmentId, string $ownerScope, array $legal, array $files, bool $requirePublishedProfile = true): array
    {
        $profile = $legal['profile'] ?? null;
        if (!is_array($profile) || !is_string($profile['code'] ?? null) || !is_string($profile['version'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The legal analysis profile changed');
        }
        if ($requirePublishedProfile) {
            (new LegalAnalysisProfileRegistry())->assertSnapshotCurrent($environmentId, $profile);
        } else {
            (new LegalAnalysisProfileRegistry())->validate($profile);
        }
        if (!is_array($legal['sources'] ?? null) || !array_is_list($legal['sources']) || $legal['sources'] === []) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The legal analysis source list is invalid');
        }
        $byFile = [];
        foreach ($files as $file) {
            $byFile[$file['file_id']] = $file;
        }
        foreach ($legal['sources'] as $source) {
            $file = is_array($source) ? ($byFile[$source['file_id'] ?? 0] ?? null) : null;
            if ($file === null || $source['file_sha256'] !== $file['sha256']
                || $source['version_ref'] !== $file['version_ref']
                || $source['parse_id'] !== $file['parse_id']) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The legal source is outside the fixed workflow file snapshot');
            }
        }
        return (new LegalSourceGateway())->resolve($environmentId, $ownerScope, $legal['sources']);
    }

    /** @param array{sources:list<array<string,mixed>>} $resolved @param list<array<string,mixed>> $files */
    private function assertResolvedLegalSourcesMatchFiles(array $resolved, array $files): void
    {
        $frozen = [];
        foreach ($files as $file) {
            foreach ($file['blocks'] as $block) {
                $frozen[$block['id']] = ['file_id' => $file['file_id'], 'parse_id' => $file['parse_id'], ...$block];
            }
        }
        foreach ($resolved['sources'] as $source) {
            foreach ($source['blocks'] as $block) {
                $saved = $frozen[$block['source_block_id']] ?? null;
                if ($saved === null || $saved['file_id'] !== $source['file_id']
                    || $saved['parse_id'] !== $source['parse_id']
                    || $saved['content_sha256'] !== $block['content_sha256']
                    || $saved['locator_type'] !== $block['locator_type']
                    || $saved['locator'] !== $block['locator']) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A legal source changed after execution snapshot verification');
                }
            }
        }
    }

    /** @param list<array{file_id:int,sha256:string,version_ref:string}> $files @return list<array<string, mixed>> */
    private function verifyFiles(int $environmentId, array $files, string $trustedIdentityScope): array
    {
        // File deletion holds the same rows before checking unfinished runs.
        // Lock in a stable order so a deletion cannot land between validation
        // and creation of the authorization-pending workflow task.
        $fileIds = array_values(array_unique(array_column($files, 'file_id')));
        sort($fileIds, SORT_NUMERIC);
        foreach ($fileIds as $fileId) {
            Db::connect('pgsql')->query(
                'SELECT id FROM sand_ai_file WHERE environment_id=? AND id=? FOR UPDATE',
                [$environmentId, $fileId],
            );
        }
        $frozen = [];
        foreach ($files as $fileSnapshot) {
            $file = AiFile::where('environment_id', $environmentId)->where('id', $fileSnapshot['file_id'])
                ->where('owner_scope', $trustedIdentityScope)->where('status', 1)->find();
            if ($file === null || (string) $file->state !== 'parsed') {
                throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_NOT_PARSED', 'Every workflow file must already be parsed in the current environment');
            }
            if (!hash_equals((string) $file->sha256, $fileSnapshot['sha256'])) {
                throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'A workflow file hash does not match its fixed snapshot');
            }
            $parse = AiFileParse::where('file_id', (int) $file->id)->where('state', 'succeeded')->order('id', 'desc')->find();
            if ($parse === null || SourceBlock::where('file_id', (int) $file->id)->where('parse_id', (int) $parse->id)->where('status', 1)->count() === 0) {
                throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_NOT_PARSED', 'A workflow file has no successful parsed source blocks');
            }
            $frozen[] = [
                'file_id' => (int) $file->id,
                'parse_id' => (int) $parse->id,
                'sha256' => (string) $file->sha256,
                'version_ref' => $fileSnapshot['version_ref'],
            ];
        }
        return $frozen;
    }

    private function assertFrozenRunFilesCurrent(WorkflowRun $run): void
    {
        $files = [];
        $parseIds = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('file_id')->select() as $runFile) {
            if ((int) $runFile->status !== 1 || $runFile->delete_time !== null) {
                throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_NOT_PARSED', 'A workflow source file is unavailable');
            }
            $fileId = (int) $runFile->file_id;
            $files[] = [
                'file_id' => $fileId,
                'sha256' => (string) $runFile->sha256,
                'version_ref' => (string) $runFile->version_ref,
            ];
            $parseIds[$fileId] = (int) $runFile->parse_id;
        }
        if ($files === []) {
            throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_NOT_PARSED', 'A workflow source file is unavailable');
        }
        $current = $this->verifyFiles(
            (int) $run->environment_id, $files, $this->scopeForRun($run),
        );
        foreach ($current as $file) {
            if (($parseIds[$file['file_id']] ?? null) !== $file['parse_id']) {
                throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'A workflow file parse changed before enqueue');
            }
        }
    }

    /** @return list<array{file_id:int,parse_id:int,sha256:string,version_ref:string}> */
    private function frozenRunFileSnapshots(WorkflowRun $run): array
    {
        $files = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('file_id')->select() as $runFile) {
            if ((int) $runFile->status !== 1 || $runFile->delete_time !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A legal source file is unavailable');
            }
            $files[] = [
                'file_id' => (int) $runFile->file_id,
                'parse_id' => (int) $runFile->parse_id,
                'sha256' => (string) $runFile->sha256,
                'version_ref' => (string) $runFile->version_ref,
            ];
        }
        return $files;
    }

    /** @return list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> */
    private function frozenSourceFiles(int $runId, string $trustedIdentityScope): array
    {
        $files = [];
        foreach (WorkflowRunFile::where('workflow_run_id', $runId)->order('id')->select() as $runFile) {
            $file = AiFile::where('id', (int) $runFile->file_id)->where('owner_scope', $trustedIdentityScope)
                ->where('status', 1)->find();
            if ($file === null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A workflow source file is unavailable to this caller');
            }
            $blocks = [];
            foreach (SourceBlock::where('file_id', (int) $runFile->file_id)->where('parse_id', (int) $runFile->parse_id)->where('status', 1)->order('sequence_no')->select() as $block) {
                $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                $blocks[] = [
                    'id' => (int) $block->id,
                    'sequence_no' => (int) $block->sequence_no,
                    'locator_type' => (string) $block->locator_type,
                    'locator' => is_array($locator) ? $locator : [],
                    'content' => (string) $block->content,
                    'content_sha256' => (string) $block->content_sha256,
                ];
            }
            $files[] = [
                'file_id' => (int) $runFile->file_id,
                'parse_id' => (int) $runFile->parse_id,
                'sha256' => (string) $runFile->sha256,
                'version_ref' => (string) $runFile->version_ref,
                'blocks' => $blocks,
            ];
        }
        return $files;
    }

    private function scopeForRun(WorkflowRun $run): string
    {
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($snapshot) ? $snapshot : null, (int) $run->environment_id,
                'structured_workflow', 'sand_ai.workflow.run',
            );
            return FileOwnershipGuard::scope($attribution);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The trusted workflow attribution snapshot is invalid');
        }
    }

    /** @param list<array{file_id:int,parse_id:int,sha256:string,version_ref:string}> $frozen */
    private function snapshotSourceFiles(array $frozen): array
    {
        $files = [];
        foreach ($frozen as $file) {
            $blocks = [];
            foreach (SourceBlock::where('file_id', $file['file_id'])->where('parse_id', $file['parse_id'])->where('status', 1)->order('sequence_no')->select() as $block) {
                $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                $content = (string) $block->content;
                if (!hash_equals((string) $block->content_sha256, hash('sha256', $content))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A source block content hash is inconsistent');
                }
                $blocks[] = ['id' => (int) $block->id, 'sequence_no' => (int) $block->sequence_no, 'locator_type' => (string) $block->locator_type, 'locator' => is_array($locator) ? $locator : [], 'content' => $content, 'content_sha256' => (string) $block->content_sha256];
            }
            $files[] = ['file_id' => $file['file_id'], 'parse_id' => $file['parse_id'], 'sha256' => $file['sha256'], 'version_ref' => $file['version_ref'], 'blocks' => $blocks];
        }
        return $files;
    }

    /** @param list<array<string,mixed>> $files @return list<array<string,mixed>> */
    private function snapshotFilesFromExecution(array $files): array
    {
        return array_map(static fn (array $file): array => [
            'file_id' => $file['file_id'], 'parse_id' => $file['parse_id'], 'sha256' => $file['sha256'], 'version_ref' => $file['version_ref'], 'blocks' => $file['blocks'],
        ], $files);
    }

    /** @param array<string,mixed> $definition @param list<array<string,mixed>> $files @return array{snapshot:array<string,mixed>,sha256:string} */
    private function rebuildExecutionSnapshot(WorkflowRun $run, array $definition, array $files): array
    {
        $persistedAttribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        try {
            $attribution = UsageAttribution::fromPersisted(is_array($persistedAttribution) ? $persistedAttribution : null, (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run');
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The trusted workflow attribution snapshot is invalid');
        }
        if (!is_array($input)
            || $attribution->environmentId !== (int) $run->environment_id
            || $attribution->applicationId !== ($run->application_id === null ? null : (int) $run->application_id)
            || $attribution->organizationId !== ($run->organization_id === null ? null : (int) $run->organization_id)
            || $attribution->workloadClientId !== ($run->workload_client_id === null ? null : (int) $run->workload_client_id)
        ) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The trusted workflow attribution snapshot is invalid');
        }
        $material = (string) $run->workflow_code === 'material_analysis';
        $legal = (string) $run->workflow_code === 'legal_analysis';
        $stored = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        $reviewOutput = null;
        if (is_array($stored) && array_key_exists('material_review_output_contract', $stored)) {
            if (!$material || ($input['material_execution_mode'] ?? null) !== 'single_file_v1'
                || count($files) < 2 || ($stored['version'] ?? null) !== ExecutionSnapshotBuilder::VERSION
                || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($stored))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen review output contract is outside its execution scope');
            }
            MaterialReviewOutputContract::fromSnapshot($stored);
            // Preserve the validated frozen contract; never regenerate it from current configuration.
            $reviewOutput = $stored['material_review_output_contract'];
        }
        $legalOutput = null;
        if (is_array($stored) && array_key_exists('legal_output_contract', $stored)) {
            if (!$legal || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($stored))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Legal output contract is outside its frozen scope');
            }
            \app\Application\Legal\LegalOutputContract::fromSnapshot($stored);
            $legalOutput = $stored['legal_output_contract'];
        }
        return (new ExecutionSnapshotBuilder())->build([
            'environment_id' => (int) $run->environment_id,
            'attribution' => $attribution->toPersisted(),
            'workflow_definition' => $definition,
            'business_scope' => $input['business_scope'] ?? null,
            'workflow' => ['code' => (string) $run->workflow_code, 'version' => (string) $run->workflow_version],
            'output_schema' => ['code' => (string) $run->output_schema_code, 'version' => (string) $run->output_schema_version],
            'analysis_profile' => $input['analysis_profile'] ?? null,
            ...($reviewOutput === null ? [] : ['material_review_output_contract' => $reviewOutput]),
            ...($legal ? ['legal_analysis' => $input['legal_analysis'] ?? null] : []),
            ...($legalOutput === null ? [] : ['legal_output_contract' => $legalOutput]),
            ...($material && isset($input['material_execution_mode']) ? ['material_execution_mode' => $input['material_execution_mode']] : []),
            ...($material && isset($input['material_chunk_policy']) ? ['material_chunk_policy' => $input['material_chunk_policy']] : []),
            ...(isset($input['material_profile_sample']) ? ['material_profile_sample' => $input['material_profile_sample']] : []),
            'review_required' => (bool) $run->review_required,
            'logical_execution_id' => (string) $run->logical_execution_id,
            'files' => $this->snapshotFilesFromExecution($files),
            'capability_code' => $material || $legal ? 'structured_extraction' : 'source_manifest',
            'route' => $material || $legal ? ['state' => 'pending_pre_dispatch', 'receipt_policy' => 'not_enabled'] : ['state' => 'not_required'],
            'prompt_builder_version' => $material ? 'material_analysis_prompt@1' : ($legal ? 'legal_analysis_prompt@1' : null),
            'price_decision' => ['state' => 'pending_pre_dispatch'],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(WorkflowRun $run, bool $withLegacyArtifact = false, bool $idempotentReplay = false): array
    {
        $task = TaskRun::where('environment_id', (int) $run->environment_id)->where('id', (int) $run->task_id)
            ->where('resource_type', 'workflow_run')->where('resource_id', (int) $run->id)->find();
        $result = [
            'id' => (int) $run->id,
            'state_version' => (int) $run->state_version,
            'workflow' => ['code' => (string) $run->workflow_code, 'version' => (string) $run->workflow_version],
            'output_schema' => ['code' => (string) $run->output_schema_code, 'version' => (string) $run->output_schema_version],
            'snapshot_sha256' => (string) $run->snapshot_sha256,
            'state' => $task === null ? 'failed' : (string) $task->state,
            'task' => $task === null ? null : (new TaskGateway())->summary($task),
            'review_required' => (bool) $run->review_required,
            'content_purged' => $run->content_purged_time !== null,
            'analysis_profile' => $this->analysisProfileIdentity($run),
        ];
        $result['file_states'] = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('file_id')->select() as $runFile) {
            $fileState = WorkflowRunFileState::present(
                (int) $runFile->file_id,
                (string) $runFile->analysis_state,
                (int) $runFile->attempt_count,
                $runFile->failure_summary === null ? null : (string) $runFile->failure_summary,
            );
            if ($task !== null && (string) $runFile->analysis_state === 'failed'
                && in_array($fileState['error_code'], [
                    'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
                ], true)
                && MaterialFileOutputFailureEvidence::schemaAvailable()
                && $this->confirmedIndependentMaterialFileFailure($run, $task, $runFile)) {
                $fileState['failure_stage'] = 'model_output_validation';
                $fileState['failure_summary'] = '模型已返回并产生用量，但成果的字段格式或来源校验未通过；请检查配置与提示后，只重试失败文件。';
            }
            if ($task !== null && $run->content_purged_time === null) {
                $chunk = (new MaterialChunkCheckpoint())->read((int) $task->id, (int) $runFile->id);
                if ($chunk !== null) $fileState['chunk_progress'] = (new MaterialChunkCheckpoint())->progress($chunk, (string) $runFile->analysis_state);
            }
            $result['file_states'][] = $fileState;
        }
        $artifactProjection = null;
        if ($run->content_purged_time === null) {
            $artifact = Artifact::where('workflow_run_id', (int) $run->id)->order('id', 'desc')->find();
            $artifactProjection = $artifact === null ? null : $this->artifact($artifact, $run);
        }
        $usageSummary = $this->serviceUsageSummary($run);
        if ($withLegacyArtifact) {
            $result['artifact'] = $artifactProjection;
            // Material-analysis callers need a bounded, non-sensitive cost
            // trace next to the artifact.  The run's logical request id is
            // server generated; neither provider payloads nor credentials are
            // surfaced here.
            if (in_array((string) $run->workflow_code, ['material_analysis', 'legal_analysis'], true)) {
                if (($usageSummary['aggregation'] ?? null) === 'single_file_v1') {
                    $result['usage_cost'] = $usageSummary;
                } else {
                    $invocation = Invocation::where('environment_id', (int) $run->environment_id)
                        ->where('request_id', 'structured_' . (string) $run->logical_execution_id)
                        ->order('id', 'desc')->find();
                    $usage = $invocation === null ? null : UsageEvent::where('invocation_id', (int) $invocation->id)->find();
                    $result['usage_cost'] = $usage === null ? null : [
                        'invocation_id' => (int) $invocation->id,
                        ...((new UsageMeter())->summary($usage)),
                    ];
                }
            }
        }
        $service = (new WorkflowServiceCatalog())->service((string) $run->workflow_code, (string) $run->workflow_version);
        return (new StructuredServiceRunPresenter())->present(
            $result,
            $service,
            $artifactProjection,
            $usageSummary,
            $this->queuedAuditRef($run),
            $idempotentReplay,
        );
    }

    /** @return array<string, mixed> */
    private function artifact(Artifact $artifact, WorkflowRun $run): array
    {
        if ((int) $artifact->workflow_run_id !== (int) $run->id) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The artifact is not associated with the structured workflow run');
        }
        $citations = [];
        $scope = $this->scopeForRun($run);
        $frozenFiles = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select() as $runFile) {
            $file = AiFile::where('environment_id', (int) $run->environment_id)
                ->where('id', (int) $runFile->file_id)->where('owner_scope', $scope)
                ->where('status', 1)->whereNull('delete_time')->find();
            $parse = AiFileParse::where('id', (int) $runFile->parse_id)
                ->where('file_id', (int) $runFile->file_id)->where('state', 'succeeded')
                ->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null || (string) $file->state !== 'parsed' || $parse === null
                || (int) $runFile->status !== 1 || $runFile->delete_time !== null
                || !hash_equals((string) $runFile->sha256, (string) $file->sha256)) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'An artifact source file changed before reading');
            }
            (new FileOwnershipGuard())->assert($file, $scope);
            $frozenFiles[(int) $runFile->file_id] = (int) $runFile->parse_id;
        }
        foreach (ArtifactFieldSource::where('artifact_id', (int) $artifact->id)->order('id')->select() as $source) {
            $block = SourceBlock::where('id', (int) $source->source_block_id)
                ->where('status', 1)->whereNull('delete_time')->find();
            if ($block === null || !isset($frozenFiles[(int) $block->file_id])
                || $frozenFiles[(int) $block->file_id] !== (int) $block->parse_id
                || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'An artifact citation no longer resolves to a source block');
            }
            $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
            if (!is_array($locator)) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'An artifact citation locator is invalid');
            }
            $this->assertFrozenBlock($run, $block, $locator);
            $citations[] = [
                'field_path' => (string) $source->field_path,
                'source_block_id' => (int) $block->id,
                'file_id' => (int) $block->file_id,
                'locator_type' => (string) $block->locator_type,
                'locator' => $locator,
            ];
        }
        $payload = is_object($artifact->payload) ? (array) $artifact->payload : $artifact->payload;
        if ((string) $run->workflow_code === 'material_analysis'
            && (string) $run->output_schema_code === 'sand_ai.material_analysis'
            && (string) $run->output_schema_version === '1.0.0'
            && (string) $artifact->schema_code === 'sand_ai.material_analysis'
            && (string) $artifact->schema_version === '1.0.0'
            && is_array($payload)
            && is_array($payload['schema'] ?? null)
            && ($payload['schema']['code'] ?? null) === 'sand_ai.material_analysis'
            && ($payload['schema']['version'] ?? null) === '1.0.0'
            && ($payload['extensions'] ?? null) === []) {
            // Associative ORM JSON decoding loses an empty object's shape.
            // Restore only this schema's object field in the read projection.
            $payload['extensions'] = new \stdClass();
        }
        return [
            'id' => (int) $artifact->id,
            'schema' => ['code' => (string) $artifact->schema_code, 'version' => (string) $artifact->schema_version],
            'state' => (string) $artifact->state,
            'review_required' => (bool) $artifact->review_required,
            'payload' => $payload,
            'citations' => $citations,
        ];
    }

    /** @param array<string,mixed> $locator */
    private function assertFrozenBlock(WorkflowRun $run, SourceBlock $block, array $locator): void
    {
        $snapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        if (!is_array($snapshot)
            || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($snapshot))
            || !is_array($snapshot['files'] ?? null)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The frozen source snapshot is invalid');
        }
        foreach ($snapshot['files'] as $file) {
            if (!is_array($file) || ($file['file_id'] ?? null) !== (int) $block->file_id
                || ($file['parse_id'] ?? null) !== (int) $block->parse_id) {
                continue;
            }
            foreach ($file['blocks'] ?? [] as $saved) {
                if (is_array($saved) && ($saved['id'] ?? null) === (int) $block->id
                    && ($saved['content_sha256'] ?? null) === (string) $block->content_sha256
                    && ($saved['locator_type'] ?? null) === (string) $block->locator_type
                    && ($saved['locator'] ?? null) === $locator) {
                    return;
                }
            }
        }
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A cited source changed since the frozen execution');
    }

    /** @return array<string,mixed>|null */
    private function serviceUsageSummary(WorkflowRun $run): ?array
    {
        if ((string) $run->workflow_code === 'source_manifest') {
            return null;
        }
        if (!in_array((string) $run->workflow_code, ['material_analysis', 'legal_analysis'], true)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_SERVICE_CATALOG_INVALID', 'The structured workflow service usage is unsupported');
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (is_array($input) && ($input['material_execution_mode'] ?? null) === 'single_file_v1') {
            return $this->fileUsageSummary($run);
        }
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('parent_workflow_run_id', (int) $run->id)
            ->where('request_id', 'structured_' . (string) $run->logical_execution_id)->order('id', 'desc')->find();
        if ($invocation === null) {
            return null;
        }
        $usage = UsageEvent::where('environment_id', (int) $run->environment_id)->where('invocation_id', (int) $invocation->id)->find();
        return $usage === null ? null : (new UsageMeter())->summary($usage);
    }

    /** @return array<string,mixed>|null */
    private function fileUsageSummary(WorkflowRun $run): ?array
    {
        $definition = is_object($run->definition_snapshot) ? (array) $run->definition_snapshot : $run->definition_snapshot;
        $fileNode = is_array($definition) && is_array($definition['steps'] ?? null)
            ? ($definition['steps'][0] ?? null) : null;
        if (!is_string($fileNode) || $fileNode === 'cross_file_review'
            || preg_match('/\A[a-z][a-z0-9_]{1,127}\z/D', $fileNode) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage file node is invalid');
        }
        $runFiles = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select() as $runFile) {
            if ((int) $runFile->status !== 1 || $runFile->delete_time !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage file is unavailable');
            }
            $runFiles[(int) $runFile->id] = true;
        }
        if ($runFiles === []) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Material usage files are missing');
        }
        // One PostgreSQL statement observes invocation, dispatch and usage at
        // the same snapshot. Raw rows also expose soft-deleted invocations,
        // which must not silently disappear from a monetary aggregate.
        // Select by parent before checking environment: filtering a malformed
        // attempt out would incorrectly present the remaining cost as complete.
        $rows = Db::connect('pgsql')->query(
            'SELECT i.id AS invocation_id, i.parent_workflow_run_file_id, i.workflow_node_code, i.parent_task_run_id, '
            . 'i.environment_id AS invocation_environment_id, i.organization_id AS invocation_organization_id, '
            . 'i.application_id AS invocation_application_id, '
            . 'i.workload_client_id AS invocation_workload_client_id, '
            . 'i.execution_attempt_no, i.status AS invocation_status, i.delete_time AS invocation_delete_time, '
            . 'i.request_id, i.logical_execution_id, i.execution_snapshot_sha256, i.state AS invocation_state, '
            . 'd.id AS dispatch_id, d.environment_id AS dispatch_environment_id, d.application_id AS dispatch_application_id, '
            . 'd.execution_scope AS dispatch_execution_scope, d.source_kind AS dispatch_source_kind, '
            . 'd.service_action AS dispatch_service_action, d.capability_code AS dispatch_capability_code, '
            . 'd.logical_execution_id AS dispatch_logical_execution_id, d.logical_request_id AS dispatch_logical_request_id, '
            . 'd.parent_workflow_run_id AS dispatch_workflow_run_id, d.parent_task_run_id AS dispatch_task_run_id, '
            . 'd.workflow_node_code AS dispatch_node_code, '
            . 'd.parent_workflow_run_file_id AS dispatch_run_file_id, d.attempt_no AS dispatch_attempt_no, '
            . 'd.execution_snapshot_sha256 AS dispatch_snapshot_sha256, d.state AS dispatch_state, '
            . 'u.id AS usage_event_id, u.environment_id AS usage_environment_id, '
            . 'u.organization_id AS usage_organization_id, u.application_id AS usage_application_id, '
            . 'u.workload_client_id AS usage_workload_client_id, '
            . 'u.service_action, u.source_kind, u.capability_code, '
            . 'u.usage_state, u.pricing_state, u.input_tokens, u.output_tokens, u.total_tokens, '
            . 'u.metered_amount, u.currency '
            . 'FROM sand_ai_invocation i '
            . 'LEFT JOIN sand_ai_invocation_dispatch d ON d.invocation_id = i.id '
            . 'LEFT JOIN sand_ai_usage_event u ON u.invocation_id = i.id '
            . 'WHERE i.parent_workflow_run_id = ? ORDER BY i.id',
            [(int) $run->id],
        );
        $attempts = [];
        foreach ($rows as $row) {
            $hasUsage = $row['usage_event_id'] !== null;
            // An attempt's parent and snapshot do not prove monetary ownership.
            // Reject missing or mismatched caller facts before aggregating any
            // successful, failed or cross-file review attempt.
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                $owner = (int) $run->getAttr($field);
                if ($owner < 1 || (int) ($row['invocation_' . $field] ?? 0) !== $owner
                    || ($hasUsage && (int) ($row['usage_' . $field] ?? 0) !== $owner)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage attempt has invalid ownership');
                }
            }
            $reviewAttempt = $row['parent_workflow_run_file_id'] === null
                && ($row['workflow_node_code'] ?? null) === 'cross_file_review';
            $chunkNode = !$reviewAttempt && is_string($row['workflow_node_code'] ?? null)
                && preg_match('/\Amaterial_(leaf_[0-9]{6}|merge_[0-9]{6}_review_[0-9]{6})\z/D', $row['workflow_node_code'], $chunkMatch) === 1
                ? $chunkMatch[1] : null;
            if ($chunkNode !== null && !(new MaterialChunkCheckpoint())->knowsNode((int) $run->task_id, (int) $row['parent_workflow_run_file_id'], $chunkNode)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage node is outside its frozen plan');
            }
            $expectedNode = $reviewAttempt ? 'cross_file_review' : ($chunkNode === null ? $fileNode : 'material_' . $chunkNode);
            $runFileId = $reviewAttempt ? 0 : (int) ($row['parent_workflow_run_file_id'] ?? 0);
            $attemptNo = (int) ($row['execution_attempt_no'] ?? 0);
            if ((!$reviewAttempt && !isset($runFiles[$runFileId]))
                || ($reviewAttempt && count($runFiles) < 2)
                || ($row['workflow_node_code'] ?? null) !== $expectedNode
                || $attemptNo < 1
                || (int) ($row['parent_task_run_id'] ?? 0) !== (int) $run->task_id
                || (int) ($row['invocation_status'] ?? 0) !== 1 || $row['invocation_delete_time'] !== null
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) ($row['execution_snapshot_sha256'] ?? ''))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage invocation is invalid');
            }
            $reviewChunkIdentity = $reviewAttempt ? MaterialCrossFileReviewChunkAttemptIdentity::fromRequestId((string) $run->logical_execution_id, (string) $row['request_id']) : null;
            if ($reviewChunkIdentity !== null && $reviewChunkIdentity->attemptNo !== $attemptNo) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A cross-file usage batch attempt changed');
            }
            if ($reviewChunkIdentity !== null && !(new MaterialCrossFileReviewChunkRunner())->knowsBatch((int) $run->task_id, $reviewChunkIdentity->batchId)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A cross-file usage batch is outside its frozen plan');
            }
            $identity = $reviewAttempt
                ? ($reviewChunkIdentity ?? new MaterialCrossFileReviewAttemptIdentity((string) $run->logical_execution_id, $attemptNo))
                : ($chunkNode === null ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, $runFileId, $attemptNo)
                    : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, $runFileId, $chunkNode, $attemptNo));
            if (!hash_equals($identity->requestId(), (string) ($row['request_id'] ?? ''))
                || !hash_equals($identity->dispatchLogicalExecutionId(), (string) ($row['logical_execution_id'] ?? ''))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage attempt identity is invalid');
            }
            $hasDispatch = $row['dispatch_id'] !== null;
            if ($hasDispatch
                && ((int) ($row['dispatch_environment_id'] ?? 0) !== (int) $run->environment_id
                    || (int) ($row['dispatch_application_id'] ?? 0) !== (int) $run->application_id
                    || ($row['dispatch_execution_scope'] ?? null) !== 'structured_workflow'
                    || ($row['dispatch_source_kind'] ?? null) !== 'structured_workflow'
                    || ($row['dispatch_service_action'] ?? null) !== 'sand_ai.workflow.run'
                    || ($row['dispatch_capability_code'] ?? null) !== 'structured_extraction'
                    || !hash_equals($identity->dispatchLogicalExecutionId(), (string) ($row['dispatch_logical_execution_id'] ?? ''))
                    || !hash_equals(hash('sha256', $identity->requestId()), (string) ($row['dispatch_logical_request_id'] ?? ''))
                    || (int) ($row['dispatch_workflow_run_id'] ?? 0) !== (int) $run->id
                    || (int) ($row['dispatch_task_run_id'] ?? 0) !== (int) $run->task_id
                    || ($row['dispatch_node_code'] ?? null) !== $expectedNode
                    || ($reviewAttempt
                        ? $row['dispatch_run_file_id'] !== null
                        : (int) ($row['dispatch_run_file_id'] ?? 0) !== $runFileId)
                    || (int) ($row['dispatch_attempt_no'] ?? 0) !== $attemptNo
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) ($row['dispatch_snapshot_sha256'] ?? '')))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage dispatch is invalid');
            }
            if ($hasUsage && ((int) ($row['usage_environment_id'] ?? 0) !== (int) $run->environment_id
                || ($row['service_action'] ?? null) !== 'sand_ai.workflow.run'
                || ($row['source_kind'] ?? null) !== 'structured_workflow'
                || ($row['capability_code'] ?? null) !== 'structured_extraction')) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage event has invalid attribution');
            }
            if (!$hasDispatch && ($hasUsage || (string) ($row['invocation_state'] ?? '') === 'succeeded')) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material usage dispatch is missing');
            }
            $measured = $hasUsage && ($row['usage_state'] ?? null) === 'measured';
            $attempts[] = [
                'invocation_id' => (int) $row['invocation_id'],
                'file_id' => $runFileId,
                'node' => $reviewAttempt ? ($reviewChunkIdentity === null ? 'cross_file_review' : 'cross_file_review_' . $reviewChunkIdentity->batchId) : ($chunkNode ?? 'file'),
                'attempt_no' => $attemptNo,
                'dispatch_state' => $hasDispatch ? (string) $row['dispatch_state'] : 'prepared',
                'usage' => !$hasUsage ? null : [
                    'usage_state' => (string) $row['usage_state'],
                    'pricing_state' => (string) $row['pricing_state'],
                    'tokens' => [
                        'input_tokens' => $measured && $row['input_tokens'] !== null ? (int) $row['input_tokens'] : null,
                        'output_tokens' => $measured && $row['output_tokens'] !== null ? (int) $row['output_tokens'] : null,
                        'total_tokens' => $measured && $row['total_tokens'] !== null ? (int) $row['total_tokens'] : null,
                    ],
                    'metered_amount' => $row['metered_amount'] === null ? null : (string) $row['metered_amount'],
                    'currency' => $row['currency'] === null ? null : (string) $row['currency'],
                ],
            ];
        }
        return (new MaterialWorkflowUsageAggregator())->summarize($attempts);
    }

    private function queuedAuditRef(WorkflowRun $run): ?string
    {
        $environmentId = $run->environment_id;
        if ($environmentId === null || (int) $environmentId <= 0 || !is_string($run->logical_execution_id) || $run->logical_execution_id === '') {
            return null;
        }
        $audit = AuditLog::where('environment_id', (int) $environmentId)->where('resource_type', 'workflow_run')
            ->where('resource_id', (int) $run->id)->where('action', 'workflow.queued')->order('id', 'asc')->find();
        if ($audit === null || (int) $audit->environment_id !== (int) $environmentId) {
            return null;
        }

        return 'sai_audit_v1_' . hash('sha256', implode('/', [(string) $environmentId, (string) $run->id, (string) $audit->id, (string) $run->logical_execution_id]));
    }

    /** Only verified frozen identity is public; old or purged runs remain independently readable.
     * @return null|array{code:string,version:string,origin:string,revision:?int}
     */
    private function analysisProfileIdentity(WorkflowRun $run): ?array
    {
        if ((string) $run->workflow_code !== 'material_analysis' || $run->content_purged_time !== null) {
            return null;
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $snapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        if (!is_array($input) || !is_array($snapshot)
            || ($snapshot['version'] ?? null) !== ExecutionSnapshotBuilder::VERSION
            || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($snapshot))) {
            return null;
        }
        $profile = $input['analysis_profile'] ?? null;
        $frozenProfile = $snapshot['analysis_profile'] ?? null;
        if (!is_array($profile) || !is_array($frozenProfile)
            || !hash_equals(StructuredWorkflowPolicy::fingerprint($profile), StructuredWorkflowPolicy::fingerprint($frozenProfile))
            || !is_string($profile['code'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $profile['code']) !== 1
            || !is_string($profile['version'] ?? null) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $profile['version']) !== 1
            || !is_array($profile['snapshot'] ?? null)
            || ($profile['snapshot']['code'] ?? null) !== $profile['code']
            || ($profile['snapshot']['version'] ?? null) !== $profile['version']) {
            return null;
        }
        $sample = $input['material_profile_sample'] ?? null;
        $frozenSample = $snapshot['material_profile_sample'] ?? null;
        if ($sample === null && $frozenSample === null
            && !array_key_exists('material_profile_sample', $input)
            && !array_key_exists('material_profile_sample', $snapshot)) {
            return ['code' => $profile['code'], 'version' => $profile['version'], 'origin' => 'published', 'revision' => null];
        }
        if (!is_array($sample) || !is_array($frozenSample)
            || !hash_equals(StructuredWorkflowPolicy::fingerprint($sample), StructuredWorkflowPolicy::fingerprint($frozenSample))
            || !is_int($sample['profile_id'] ?? null) || $sample['profile_id'] < 1
            || !is_int($sample['revision'] ?? null) || $sample['revision'] < 1
            || !is_string($sample['config_sha256'] ?? null)
            || !hash_equals($sample['config_sha256'], StructuredWorkflowPolicy::fingerprint($profile['snapshot']))) {
            return null;
        }
        return ['code' => $profile['code'], 'version' => $profile['version'], 'origin' => 'draft_sample', 'revision' => $sample['revision']];
    }

    /** @return array<string,mixed> */
    private function analysisProfile(WorkflowRun $run): array
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $profile = is_array($input) ? $input['analysis_profile'] ?? null : null;
        if (!is_array($profile) || !is_string($profile['code'] ?? null) || !is_string($profile['version'] ?? null) || !is_array($profile['snapshot'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen material analysis profile is invalid');
        }
        if (($profile['snapshot']['code'] ?? null) !== $profile['code'] || ($profile['snapshot']['version'] ?? null) !== $profile['version']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen material analysis profile is inconsistent');
        }
        return $profile['snapshot'];
    }

    /** @return array{profile:array<string,mixed>,context:array<string,mixed>,facts:list<array<string,mixed>>,sources:list<array<string,mixed>>} */
    private function legalAnalysisInput(WorkflowRun $run): array
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $legal = is_array($input) ? ($input['legal_analysis'] ?? null) : null;
        if (!is_array($legal) || !is_array($legal['profile'] ?? null)
            || !is_array($legal['context'] ?? null) || !is_array($legal['facts'] ?? null)
            || !is_array($legal['sources'] ?? null) || $legal['sources'] === []) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen legal analysis input is invalid');
        }
        return $legal;
    }

    /** @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} */
    private function executeLegalAnalysis(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $iamAuthorization, array $files): array
    {
        $legal = $this->legalAnalysisInput($run);
        $resolved = $this->assertLegalSourcesCurrent(
            (int) $run->environment_id, $this->scopeForRun($run), $legal, $this->frozenRunFileSnapshots($run),
        );
        $this->assertResolvedLegalSourcesMatchFiles($resolved, $files);
        $client = $this->structuredCompletionClient($run, $task, $lease, $iamAuthorization);
        try {
            return (new LegalAnalysisExecutor($client, outputContract: \app\Application\Legal\LegalOutputContract::fromSnapshot((array) $run->execution_snapshot)))->execute($legal, $resolved);
        } catch (\app\Application\Legal\LegalOutputRejected $failure) {
            $invocation = Invocation::where('parent_task_run_id', (int) $task->id)->where('workflow_node_code', 'analyze_legal_sources')->where('state', 'succeeded')->find();
            if ($invocation === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Legal rejection outcome is unavailable');
            throw new \app\Application\Legal\LegalOutputRejected($failure->errorCode, (int) $run->id,
                (int) $invocation->id, 1, $failure->contentSha256);
        }
    }

    private function materialAnalysisExecutor(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $iamAuthorization, ?WorkflowRunFile $selectedFile = null): MaterialAnalysisExecutor
    {
        if ($this->materialAnalysisExecutor !== null) {
            return $this->materialAnalysisExecutor;
        }
        return new MaterialAnalysisExecutor($this->structuredCompletionClient($run, $task, $lease, $iamAuthorization, $selectedFile));
    }

    private function structuredCompletionClient(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, array $iamAuthorization, ?WorkflowRunFile $selectedFile = null, ?string $materialNodeId = null, ?int $materialAttempt = null): MaterialAnalysisCompletionClient
    {
        $route = (new CapabilityProfileResolver())->primary((int) $run->environment_id, 'structured_extraction');
        $deployment = is_array($route) ? ModelDeployment::where('id', (int) ($route['deployment_id'] ?? 0))->where('status', 1)->find() : null;
        $model = $deployment === null ? null : AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        $runFile = $selectedFile ?? WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->find();
        $persistedAttribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromPersisted(is_array($persistedAttribution) ? $persistedAttribution : null, (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run');
            $applicationScope = $attribution->applicationScope();
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The trusted workflow attribution snapshot is invalid');
        }
        if ($route === null || $deployment === null || $model === null || $step === null || $runFile === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Material analysis has no complete published execution route');
        }
        $context = new StructuredCompletionContext(
            (int) $run->environment_id,
            (int) $run->id,
            (int) $task->id,
            (int) $step->id,
            (int) $runFile->id,
            $materialNodeId === null ? (string) $step->step_code : 'material_' . $materialNodeId,
            $lease,
            (string) $run->execution_snapshot_sha256,
            (string) $run->logical_execution_id,
            $attribution,
            $applicationScope,
            (string) $model->code,
            $iamAuthorization,
            $selectedFile === null ? 1 : ($materialAttempt ?? (int) $selectedFile->attempt_count),
            $selectedFile !== null,
            legalOutput: (string) $run->workflow_code === 'legal_analysis'
                ? \app\Application\Legal\LegalOutputContract::fromSnapshot((array) $run->execution_snapshot) : null,
            materialNodeId: $materialNodeId,
        );

        return ($this->materialAnalysisClients ?? new ProductionMaterialAnalysisCompletionClientFactory())->create($context);
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $runId, string $summary, array $context): void
    {
        AuditLog::create([
            'environment_id' => $environmentId,
            'actor_type' => 'api_credential',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'workflow_run',
            'resource_id' => $runId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
