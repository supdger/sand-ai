<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentFileUsageGateway;
use app\Application\Agent\AgentGateway;
use app\Application\Agent\AgentEmbeddingCostProjection;
use app\Application\Audio\MeetingSummaryEvidence;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Audio\MeetingSummaryPlan;
use app\Application\Audio\AudioTranscriptSealLedger;
use app\Application\Audio\PostgresMeetingTokenCountLedger;
use app\Application\File\FileParseGateway;
use app\Application\Workflow\StructuredWorkflowGateway;
use app\Application\Workflow\MaterialChunkDispatchEvidence;
use app\Application\Workflow\MaterialChunkCheckpoint;
use app\Application\Workflow\MaterialCrossFileReviewChunkRunner;
use app\Application\Workflow\MaterialFileOutputFailureEvidence;
use app\Application\Workflow\WorkflowRunFileState;
use app\Application\Workflow\WorkflowStateRevision;
use app\Application\Usage\AudioTaskCostProjection;
use app\Application\Usage\PostgresAudioUsageEventStore;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageGateway;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;
use Throwable;

final class TaskGateway
{
    private bool $audioSliceYielded = false;

    public function audioSliceYielded(): bool { return $this->audioSliceYielded; }
    /**
     * @param null|\Closure(Throwable):void $unexpectedFailureObserver test-only failure probe; production leaves it null.
     */
    public function __construct(
        private readonly ?StructuredWorkflowGateway $structuredWorkflows = null,
        private readonly ?\Closure $unexpectedFailureObserver = null,
        private readonly ?AiTaskGateway $aiTasks = null,
    )
    {
    }

    public function runOne(string $workerId): bool
    {
        $this->audioSliceYielded = false;
        TaskLeasePolicy::runningTimezone();
        if ((new \app\Application\Agent\AgentExpiredReceiptFinalizer())->runOne($workerId)) return true;
        if ($this->finalizeExhaustedMeetingRejection($workerId)) return true;
        $chunkRunner = new \app\Application\Audio\Chunk\AudioChunkTaskRunner();
        $task = $chunkRunner->claimContinuation($workerId);
        if ($task === null) {
            $this->recoverStaleAuthorizations();
            $this->recoverStaleRuns();
            $task = $this->claimNext($workerId);
        }
        if ($task === null) {
            return false;
        }
        $lease = TaskExecutionLease::fromTask($task);
        try {
            $this->heartbeat($lease);
            if ($chunkRunner->owns($task)) {
                $outcome = $chunkRunner->advance($task, $lease);
                $this->audioSliceYielded = ($outcome['_continuation_pending'] ?? false) === true;
                return true;
            }
            if ((new TaskAudioReceiptContinuation())->ready($task)) {
                (new TaskAudioReceiptContinuation())->recover($task, $lease);
                return true;
            }
            if ($this->durableMeeting($task)) {
                $result = ($this->aiTasks ?? new AiTaskGateway())->executeTask($task, $lease);
                if (($result['_meeting_durable_continue'] ?? false) === true) {
                    $this->yieldDurableMeeting($task, $lease);
                    $this->audioSliceYielded = true;
                } else {
                    $this->heartbeat($lease);
                    $this->succeed($task, $result, $lease);
                }
                return true;
            }
            if ($this->neuronAgent($task)) {
                // Each stable round independently replays its private outcome or fails closed.
                // This branch may continue a new round only after the preceding receipt is valid.
                $result = (new AgentGateway())->executeTask($task, $lease);
                $this->finalizeProviderVisibleOutcome(
                    function () use ($lease): void { $this->heartbeat($lease); },
                    function () use ($task, $result, $lease): void { $this->succeed($task, $result, $lease); },
                    function () use ($task, $lease): void { $this->markRecoveryPending($task, $lease); },
                );
                return true;
            }
            $providerExecutor = match ((string) $task->task_type) {
                'ai_completion' => fn (): array => ($this->aiTasks ?? new AiTaskGateway())->executeTask($task, $lease),
                'agent_run' => fn (): array => (new AgentGateway())->executeTask($task, $lease),
                'structured_workflow' => fn (): array => ($this->structuredWorkflows ?? new StructuredWorkflowGateway())->executeTask($task, $lease, function () use ($lease): void {
                    $this->heartbeat($lease);
                }),
                'audio_transcription' => fn (): array => (new AudioTaskGateway())->executeTask($task, $lease),
                'file_parse' => null,
                default => throw new ApiProblem('SAND_AI_TASK_UNSUPPORTED', 'The queued task type is not supported'),
            };
            if ($providerExecutor !== null) {
                $result = $this->executeProviderVisibleTask(
                    $this->providerVisibleDispatchStateForTask((int) $task->id),
                    $providerExecutor,
                    function () use ($lease): void { $this->heartbeat($lease); },
                    function (array $result) use ($task, $lease): void { $this->succeed($task, $result, $lease); },
                    function () use ($task, $lease): void { $this->markRecoveryPending($task, $lease); },
                );
                $this->audioSliceYielded = ($result['_continuation_pending'] ?? false) === true;
            } else {
                $result = (new FileParseGateway())->executeTask($task, $lease);
                if (($result['_terminal_committed'] ?? false) !== true) {
                    $this->heartbeat($lease);
                    $this->succeed($task, $result, $lease);
                }
            }
        } catch (\app\Application\Legal\LegalOutputRejected $failure) {
            try {
                (new \app\Application\Legal\LegalOutputFailureWriter())->reject($task, $lease, $failure);
            } catch (\Throwable $persistenceFailure) {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $persistenceFailure, (int) $task->id, null, $failure->workflowRunId, 'legal_rejection_persistence');
                if ($persistenceFailure instanceof ApiProblem && $persistenceFailure->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $persistenceFailure;
                $this->markRecoveryPending($task, $lease);
            }
        } catch (\app\Application\Workflow\MaterialReviewOutputRejected $failure) {
            try {
                (new \app\Application\Workflow\MaterialReviewOutputFailureWriter())->reject($task, $lease, $failure);
            } catch (Throwable $persistenceFailure) {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $persistenceFailure, (int) $task->id, null, $failure->workflowRunId, 'review_rejection_persistence');
                if ($persistenceFailure instanceof ApiProblem && $persistenceFailure->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $persistenceFailure;
                $this->markRecoveryPending($task, $lease);
            }
        } catch (\app\Application\Audio\MeetingOutputRejected $failure) {
            try {
                $this->failRejectedMeetingOutput($task, $lease, $failure);
            } catch (ApiProblem $problem) {
                if ($problem->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $problem;
                $this->markRecoveryPending($task, $lease);
            } catch (Throwable) {
                $this->markRecoveryPending($task, $lease);
            }
        } catch (ApiProblem $problem) {
            if ((string) $task->task_type === 'structured_workflow') {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $problem, (int) $task->id, null, (int) $task->resource_id, 'workflow_task_execution');
            }
            if ($problem->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $problem;
            if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)
                && TaskAuthorizationContinuation::authorizationFailure($problem->errorCode)) {
                $this->pauseAuthorization($task, $lease, $problem->errorCode);
                return true;
            }
            if ((new TaskAuthorizationContinuation())->supported($task)
                && TaskAuthorizationContinuation::authorizationFailure($problem->errorCode)) {
                $this->pauseAuthorization($task, $lease, $problem->errorCode);
                return true;
            }
            if ($chunkRunner->owns($task)) {
                $chunkRunner->stop($task, $lease, $problem->errorCode);
                return true;
            }
            // A provider executor can reject before it has crossed the
            // dispatching boundary (for example a frozen route, lease or
            // price mismatch).  Only a persisted provider-visible ledger
            // state is allowed to divert the task into receipt recovery.
            if ($this->providerVisibleDispatchStateForTask((int) $task->id) !== null
                || $this->materialFinalizationPending($task)
                || $problem->errorCode === 'SAND_AI_LOCAL_RECOVERY_PENDING') {
                $this->markRecoveryPending($task, $lease);
            } elseif ($problem->errorCode !== 'SAND_AI_TASK_LEASE_LOST') {
                $this->fail($task, $problem->errorCode, $problem->getMessage(), $problem->retryable, $lease);
            }
        } catch (Throwable $exception) {
            if ((string) $task->task_type === 'structured_workflow') {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $exception, (int) $task->id, null, (int) $task->resource_id, 'workflow_task_execution');
            }
            if ($chunkRunner->owns($task)) {
                $chunkRunner->stop($task, $lease, 'SAND_AI_TASK_FAILED');
                return true;
            }
            // Keep the public error generic: provider output and exception messages can
            // contain user data. The audit trail may retain only the PHP class to make
            // an unexpected worker failure diagnosable without retaining that data.
            if ($this->unexpectedFailureObserver !== null) {
                ($this->unexpectedFailureObserver)($exception);
            }
            if ($this->providerVisibleDispatchStateForTask((int) $task->id) !== null
                || $this->materialFinalizationPending($task)) {
                $this->markRecoveryPending($task, $lease);
            } else {
                $this->fail($task, 'SAND_AI_TASK_FAILED', 'Task execution failed', true, $lease, $exception::class);
            }
        }

        return true;
    }

    /**
     * After the provider can have observed a request, no local completion
     * failure is retryable. Keeping this boundary executable also lets its
     * failure policy be exercised without a database worker.
     *
     * @param \Closure():void $heartbeat
     * @param \Closure():void $succeed
     * @param \Closure():void $recover
     */
    public function finalizeProviderVisibleOutcome(\Closure $heartbeat, \Closure $succeed, \Closure $recover): void
    {
        try {
            $heartbeat();
            $succeed();
        } catch (Throwable $error) {
            if ($error instanceof ApiProblem && $error->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $error;
            $recover();
        }
    }

    /**
     * The worker's single provider-visible execution boundary. It is shared by
     * the production task loop and the no-state test path so a recovery retry
     * is rejected before the provider collaborator can issue another POST.
     *
     * @param \Closure():array<string,mixed> $providerDispatch
     * @param \Closure(array<string,mixed>):void $succeed
     */
    public function executeProviderVisibleTask(?string $priorDispatchState, \Closure $providerDispatch, \Closure $heartbeat, \Closure $succeed, \Closure $recover): array
    {
        DispatchRetryPolicy::assertRetryable($priorDispatchState);
        $result = $providerDispatch();
        if (($result['_terminal_committed'] ?? false) !== true && ($result['_continuation_pending'] ?? false) !== true) {
            $this->finalizeProviderVisibleOutcome(
                $heartbeat,
                fn (): mixed => $succeed($result),
                $recover,
            );
        }

        return $result;
    }

    private function providerVisibleDispatchStateForTask(int $taskId, bool $lock = false): ?string
    {
        $dispatch = $this->providerVisibleDispatchForTask($taskId, $lock);
        $toolState = $this->agentChildToolDispatchState($taskId, $lock);
        return $toolState ?? ($dispatch === null ? null : (string) $dispatch->state);
    }

    /** Child transport/receipt facts are authoritative even before a parent model receipt exists. */
    private function agentChildToolDispatchState(int $taskId, bool $lock = false): ?string
    {
        $task = TaskRun::where('id', $taskId)->find();
        if ($task === null || (string) $task->task_type !== 'agent_run'
            || !is_array($task->input) || empty($task->input['_agent_readonly_tools'])) return null;
        $query = \app\Infrastructure\Persistence\Model\ToolRun::where('parent_task_run_id', $taskId)
            ->where('environment_id', (int) $task->environment_id)->order('id', 'asc');
        if ($lock) $query->lock(true);
        foreach ($query->select() as $run) {
            $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', $taskId)->find();
            if ($step === null) return TaskState::RECOVERY_PENDING;
            $attempt = \app\Infrastructure\Persistence\Model\ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->find();
            $receipt = \app\Infrastructure\Persistence\Model\ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->find();
            if ($receipt !== null || ($attempt !== null && (string) $attempt->state === 'dispatched')) {
                return in_array((string) $run->state, ['succeeded', 'failed', 'ambiguous'], true)
                    ? (string) $run->state : TaskState::RECOVERY_PENDING;
            }
            // Retain the original authorization boundary: even a prepared child cannot
            // be rebound to a new parent context through manual retry.
            if ($run->queued_authorization !== null) return TaskState::RECOVERY_PENDING;
        }
        return null;
    }

    private function materialFinalizationPending(TaskRun $task): bool
    {
        if ((string) $task->task_type !== 'structured_workflow'
            || (string) $task->resource_type !== 'workflow_run'
            || (string) $task->state !== TaskState::RUNNING) {
            return false;
        }
        $run = WorkflowRun::where('id', (int) $task->resource_id)
            ->where('environment_id', (int) $task->environment_id)
            ->where('task_id', (int) $task->id)->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null) {
            return false;
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $files = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select() as $file) {
            $files[] = ['id' => (int) $file->id, 'analysis_state' => (string) $file->analysis_state];
        }
        return MaterialFileFinalizationRecoveryPolicy::needed((int) $task->id, [
            'task_id' => (int) $run->task_id,
            'workflow_code' => (string) $run->workflow_code,
            'material_execution_mode' => is_array($input) ? ($input['material_execution_mode'] ?? null) : null,
        ], $files, $this->crossFileReviewFacts($run, count($files)));
    }

    private function providerVisibleDispatchForTask(int $taskId, bool $lock = false): ?InvocationDispatch
    {
        $probeTask = TaskRun::where('id', $taskId)->find();
        if ($probeTask !== null && $this->durableMeeting($probeTask)) {
            $query = InvocationDispatch::alias('d')
                ->join('sand_ai_task_step step', 'step.id=d.parent_task_step_id AND step.task_id=d.parent_task_run_id')
                ->join('sand_ai_meeting_summary_node node', 'node.task_step_id=step.id AND node.task_id=step.task_id AND node.node_code=d.workflow_node_code')
                ->where('step.task_id', $taskId)->where('step.state', '<>', TaskState::SUCCEEDED)
                ->whereIn('d.state', DispatchRetryPolicy::providerVisibleStates())->order('d.id', 'desc')->limit(1)->field('d.*');
            return $lock ? $query->lock(true)->find() : $query->find();
        }
        $query = InvocationDispatch::where('parent_task_run_id', $taskId)
            ->whereIn('state', DispatchRetryPolicy::providerVisibleStates())->order('id', 'desc');
        $visible = ($lock ? $query->lock(true) : $query)->select();
        if (count($visible) < 1) {
            return null;
        }
        $latest = $visible[0];
        $task = TaskRun::where('id', $taskId)->find();
        $taskInput = $task === null ? null : (is_object($task->input) ? (array) $task->input : $task->input);
        if ($task !== null && (string) $task->task_type === 'ai_completion'
            && is_array($taskInput) && is_array($taskInput['_meeting_summary'] ?? null)
            && MeetingSummaryNode::where('task_id', $taskId)->count() > 0) {
            foreach ($visible as $dispatch) {
                $node = MeetingSummaryNode::where('task_id', $taskId)
                    ->where('task_step_id', (int) $dispatch->parent_task_step_id)
                    ->where('node_code', (string) $dispatch->workflow_node_code)->find();
                if ($node === null || (string) $dispatch->execution_scope !== 'ai_task') {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting dispatch has no matching node');
                }
                $step = TaskStep::where('id', (int) $node->task_step_id)->find();
                if ($step === null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting dispatch step is missing');
                }
                if ($node->result_snapshot !== null && (string) $step->state === TaskState::SUCCEEDED) {
                    continue;
                }
                return $dispatch;
            }
            return null;
        }
        if ($task === null || (string) $task->task_type !== 'structured_workflow'
            || (string) $task->resource_type !== 'workflow_run') {
            return $latest;
        }
        $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', $taskId)->find();
        if ($run === null || (string) $run->workflow_code !== 'material_analysis') {
            return $latest;
        }
        $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select();
        $fileIds = [];
        $fileStates = [];
        $filesById = [];
        foreach ($runFiles as $runFile) {
            $fileId = (int) $runFile->id;
            $fileIds[] = $fileId;
            $filesById[$fileId] = $runFile;
            $fileStates[$fileId] = [
                'analysis_state' => (string) $runFile->analysis_state,
                'file_attempt_no' => (int) $runFile->attempt_count,
                'last_invocation_id' => $runFile->last_invocation_id === null ? null : (int) $runFile->last_invocation_id,
                'failure_summary' => $runFile->failure_summary === null ? null : (string) $runFile->failure_summary,
            ];
        }
        if ($fileIds === []) {
            return $latest;
        }
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if (!is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
            return $latest;
        }
        $chunkCheckpoints = new MaterialChunkCheckpoint();
        foreach ($filesById as $stateFileId => $runFile) {
            $fileStates[$stateFileId]['chunk_dispatch_fence'] = $chunkCheckpoints->read($taskId, $stateFileId) !== null;
        }
        $chunkEvidence = new MaterialChunkDispatchEvidence();
        $results = [];
        foreach (WorkflowFileResult::whereIn('workflow_run_file_id', $fileIds)->select() as $result) {
            $resultFileId = (int) $result->workflow_run_file_id;
            $results[$resultFileId] = [
                'invocation_id' => (int) $result->invocation_id,
                'attempt_no' => (int) $result->attempt_no,
                'invocation_attempt_no' => $chunkEvidence->resultAttempt($run, $task, $filesById[$resultFileId], $result),
                ...$fileStates[$resultFileId],
            ];
        }
        $dispatches = [];
        foreach ($visible as $dispatch) {
            $dispatches[] = [
                'id' => (int) $dispatch->id,
                'invocation_id' => (int) $dispatch->invocation_id,
                'parent_workflow_run_id' => (int) $dispatch->parent_workflow_run_id,
                'parent_task_run_id' => (int) $dispatch->parent_task_run_id,
                'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
                'parent_workflow_run_file_id' => $dispatch->parent_workflow_run_file_id === null
                    ? null : (int) $dispatch->parent_workflow_run_file_id,
                'workflow_node_code' => (string) $dispatch->workflow_node_code,
                'attempt_no' => (int) $dispatch->attempt_no,
                'state' => (string) $dispatch->state,
            ];
        }
        $crossFileChunks = new MaterialCrossFileReviewChunkRunner();
        $reviewFacts = $this->crossFileReviewFacts($run, count($runFiles));
        if ($reviewFacts !== null) {
            $reviewFacts['chunk_dispatch_fence'] = $crossFileChunks->active($run, $task);
            if ($reviewFacts['chunk_dispatch_fence'] && is_int($reviewFacts['result_invocation_id'])) {
                $reviewFacts['result_invocation_attempt_no'] = $crossFileChunks->rootInvocationAttemptNo(
                    $run, $task, $reviewFacts['result_invocation_id'],
                );
            }
        }
        $unresolved = MaterialFileDispatchFence::unresolved(
            (int) $run->id, $dispatches, $results, $reviewFacts,
            $fileStates, MaterialFileOutputFailureEvidence::byDispatchIds(array_column($dispatches, 'id')),
            $chunkEvidence->settled($run, $task)
                + $crossFileChunks->settledDispatchIds($run, $task),
        );
        if ($unresolved === null) {
            return null;
        }
        foreach ($visible as $dispatch) {
            if ((int) $dispatch->id === $unresolved['id']) {
                return $dispatch;
            }
        }
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The unresolved material file dispatch is unavailable');
    }

    /** @return array<string,mixed>|null */
    private function crossFileReviewFacts(WorkflowRun $run, int $fileCount): ?array
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if ($fileCount < 2 || !is_array($input)
            || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
            return null;
        }
        $state = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        $result = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find();
        if ($state === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is unavailable');
        }
        return [
            'state' => (string) $state->state,
            'attempt_count' => (int) $state->attempt_count,
            'last_invocation_id' => $state->last_invocation_id === null ? null : (int) $state->last_invocation_id,
            'result_attempt_no' => $result === null ? null : (int) $result->attempt_no,
            'result_invocation_id' => $result === null ? null : (int) $result->invocation_id,
        ];
    }

    /** @return array<string, mixed> */
    public function read(int $environmentId, int $taskId, ?string $trustedIdentityScope = null, ?\Closure $authorizeTaskRead = null, bool $allowChannelTask = false, ?\Closure $authorizeMeetingResult = null): array
    {
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        (new TaskInputAccessGuard())->assert($task, $trustedIdentityScope);
        (new StructuredTaskAccessGuard())->assert($task, $trustedIdentityScope);
        $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
        if (!$allowChannelTask && is_array($taskInput) && array_key_exists('_channel', $taskInput)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if ($authorizeTaskRead === null) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Task read authorization is unavailable');
        }
        $authorizeTaskRead($task);

        return $this->summary($task, true, $authorizeMeetingResult);
    }

    /** @return array<string, mixed> */
    public function cancel(int $environmentId, int $taskId, ?string $trustedIdentityScope = null, ?\Closure $authorizeTaskCancel = null,
        ?int $expectedAttemptCount = null, ?string $expectedState = null): array
    {
        $this->assertActionExpectation($expectedAttemptCount, $expectedState);
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        (new TaskInputAccessGuard())->assert($task, $trustedIdentityScope);
        (new StructuredTaskAccessGuard())->assert($task, $trustedIdentityScope);
        $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
        if (is_array($taskInput) && array_key_exists('_channel', $taskInput)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if (TaskState::terminal((string) $task->state)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A completed task cannot be canceled');
        }
        if ($authorizeTaskCancel === null) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Task cancellation authorization is unavailable');
        }
        $authorizeTaskCancel($task);

        $now = date('Y-m-d H:i:s');
        return Db::connect('pgsql')->transaction(function () use ($task, $environmentId, $now, $trustedIdentityScope, $expectedAttemptCount, $expectedState): array {
        $preparedCancellation = TaskPreparedDispatchCancellation::lockBeforeTask($task);
        $current = TaskRun::where('id', (int) $task->id)->where('environment_id', $environmentId)->lock(true)->find();
        if ($current === null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task changed before cancellation');
        }
        (new TaskInputAccessGuard())->assert($current, $trustedIdentityScope);
        (new StructuredTaskAccessGuard())->assert($current, $trustedIdentityScope);
        $this->assertCurrentActionExpectation($current, $expectedAttemptCount, $expectedState);
        if (!in_array((string) $current->state, [
            TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING,
            TaskState::RUNNING, TaskState::RECOVERY_PENDING,
        ], true)) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task changed before cancellation');
        }
        $preparedCancellation->closeUnsent($current, $now);
        if ($this->durableMeeting($current)) {
            Db::connect('pgsql')->query("SELECT set_config('sand_ai.running_timezone',?,true)", [TaskLeasePolicy::runningTimezone()]);
        }
        if ($this->durableMeeting($current) && (string) $current->state === TaskState::RUNNING) {
            (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease(TaskExecutionLease::fromTask($current));
        }
        $recoveryPending = (string) $current->state === TaskState::RECOVERY_PENDING;
        $updated = TaskRun::where('id', (int) $task->id)
            ->where('state', (string) $current->state)->where('version', (int) $current->version)
            ->update([
                'state' => TaskState::CANCELED,
                'locked_at' => null,
                'locked_by' => null,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expire_time' => null,
                'heartbeat_time' => null,
                'lease_fence' => \think\facade\Db::raw('lease_fence + 1'),
                'version' => \think\facade\Db::raw('version + 1'),
                'completed_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task changed before cancellation');
        }
        $currentInput = is_object($current->input) ? (array) $current->input : $current->input;
        $meetingTask = (string) $current->task_type === 'ai_completion'
            && is_array($currentInput) && is_array($currentInput['_meeting_summary'] ?? null);
        $cancelSteps = TaskStep::where('task_id', (int) $task->id);

        $stepsUpdated = $cancelSteps
            ->whereIn('state', $meetingTask ? [
                TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING,
                TaskState::RUNNING, TaskState::FAILED,
            ] : ($recoveryPending ? [TaskState::FAILED] : [
                TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING,
            ]))
            ->update(['state' => TaskState::CANCELED, 'completed_at' => $now]);
        if ($stepsUpdated < 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task step changed before cancellation');
        }
        $this->updateWorkflowFiles($task, TaskState::CANCELED);
        if ((string) $task->task_type === 'file_parse') {
            (new FileParseGateway())->cancelTask($task, (string) $trustedIdentityScope);
        }

        $task = TaskRun::where('id', (int) $task->id)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        $this->audit($environmentId, 'task.cancel', (int) $task->id, 'Canceled SandAI task', ['task_type' => (string) $task->task_type]);

        return $this->summary($task, true);
        });
    }

    /** @return array<string, mixed> */
    public function retry(int $environmentId, int $taskId, ?string $trustedIdentityScope = null, ?\Closure $authorizeResourceRetry = null, ?\Closure $authorizeTaskRetry = null, ?string $target = null, ?string $mode = null, ?\Closure $revalidateTaskRetry = null, ?string $continuationKey = null,
        ?int $expectedAttemptCount = null, ?string $expectedState = null, ?array $expectedFailedFiles = null, ?int $expectedReviewAttemptCount = null,
        ?string $ocrRetryKey = null, ?string $meetingCorrectionKey = null, ?string $meetingCorrectionNode = null, ?int $meetingCorrectionAttempt = null): array
    {
        $this->assertActionExpectation($expectedAttemptCount, $expectedState);
        if ($mode !== \app\Application\Audio\MeetingOutputCorrection::MODE) $this->assertMaterialRetryExpectation($target, $mode, $expectedAttemptCount, $expectedFailedFiles, $expectedReviewAttemptCount);
        $task = TaskRun::where('environment_id', $environmentId)->where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        (new TaskInputAccessGuard())->assert($task, $trustedIdentityScope);
        (new StructuredTaskAccessGuard())->assert($task, $trustedIdentityScope);
        $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
        if (is_array($taskInput) && array_key_exists('_channel', $taskInput)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if ($mode === \app\Application\Audio\MeetingOutputCorrection::MODE) {
            if ($target !== null || $expectedFailedFiles !== null || $expectedReviewAttemptCount !== null || $ocrRetryKey !== null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Meeting correction cannot replace another retry target');
            }
            return $this->correctMeetingOutput($task, $trustedIdentityScope, $authorizeResourceRetry, $authorizeTaskRetry,
                $revalidateTaskRetry, $meetingCorrectionKey, $meetingCorrectionNode, $meetingCorrectionAttempt, $expectedAttemptCount, $expectedState);
        }
        if ($meetingCorrectionKey !== null || $meetingCorrectionNode !== null || $meetingCorrectionAttempt !== null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Meeting correction fields require their explicit mode');
        }
        $ocrRetry = $target === FileParseGateway::BEFORE_DISPATCH_RETRY_TARGET;
        if ($ocrRetry) {
            if ($mode !== null || $expectedAttemptCount === null || $expectedState !== TaskState::FAILED
                || $expectedFailedFiles !== null || $expectedReviewAttemptCount !== null
                || !is_string($ocrRetryKey) || preg_match('/\Aocr_parse_retry:[a-f0-9]{64}\z/', $ocrRetryKey) !== 1
                || !is_string($trustedIdentityScope) || $trustedIdentityScope === ''
                || $authorizeTaskRetry === null || $authorizeResourceRetry === null || $revalidateTaskRetry === null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Before-dispatch OCR recovery requires its original failed attempt, key and current authorization');
            }
            if ((new FileParseGateway())->beforeDispatchRetryReceipt($task) !== null) {
                $authorizeTaskRetry($task);
                $authorization = $authorizeResourceRetry($task);
                if (!is_array($authorization)) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current file parse authorization is unavailable');
                }
                return Db::connect('pgsql')->transaction(function () use ($task, $trustedIdentityScope, $authorization,
                    $revalidateTaskRetry, $ocrRetryKey, $expectedAttemptCount, $expectedState): array {
                    $current = TaskRun::where('id', (int) $task->id)->where('environment_id', (int) $task->environment_id)->lock(true)->find();
                    if ($current === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                    return $this->replayBeforeDispatchRetry($current, $trustedIdentityScope, $authorization,
                        $revalidateTaskRetry, $ocrRetryKey, $expectedAttemptCount, $expectedState)
                        ?? throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original OCR retry receipt changed');
                });
            }
        } elseif ($ocrRetryKey !== null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'OCR retry keys require the before-dispatch target');
        }
        if ($mode === TaskAuthorizationContinuation::MODE) {
            if ($target !== null) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Authorization continuation cannot replace a retry target');
            return $this->continueAuthorization($task, $trustedIdentityScope, $authorizeResourceRetry, $authorizeTaskRetry, $revalidateTaskRetry, $continuationKey);
        }
        if ($mode !== null) {
            if ($mode !== 'receipt_only' || $target !== 'cross_file_review') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Receipt-only mode requires the cross-file review target');
            }
            if ($authorizeTaskRetry === null || $authorizeResourceRetry === null || $revalidateTaskRetry === null
                || !is_string($trustedIdentityScope) || $trustedIdentityScope === '') {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Receipt-only retry authorization is unavailable');
            }
            (new \app\Application\Workflow\MaterialReviewReceiptOnlyGuard())->assert($task);
            $authorizeTaskRetry($task);
            $freshAuthorization = $authorizeResourceRetry($task);
            if (!is_array($freshAuthorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current workflow authorization is unavailable');
            }
            $current = (new LocalOutcomeRecoveryService($this->structuredWorkflows))->recoverReview(
                $task, $trustedIdentityScope, $freshAuthorization, $revalidateTaskRetry,
            );
            return $this->summary($current, true);
        }
        if (((new TaskAuthorizationContinuation())->present($task)['required'] ?? false) === true) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Use explicit current-authorization continuation for this original task');
        }
        $materialBudgetRecovery = in_array((string) $task->state, [TaskState::FAILED, TaskState::RECOVERY_PENDING], true)
            && $this->materialBudgetStopRecovery($task, $target) !== null;
        if ($materialBudgetRecovery && ($expectedAttemptCount === null || $expectedState !== (string) $task->state
            || ($target === 'cross_file_review' && $expectedReviewAttemptCount === null)
            || ($target === 'failed_files' && $expectedFailedFiles === null) || $revalidateTaskRetry === null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Material budget recovery requires the original task and target attempts');
        }
        if ((string) $task->state !== TaskState::FAILED && !$materialBudgetRecovery) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only a failed task can be retried');
        }
        if ($target !== null && !$materialBudgetRecovery) {
            if ($target === 'cross_file_review') {
                $this->assertCrossFileReviewRetryTarget($task, false);
            } elseif ($target === 'failed_files') {
                $this->assertFailedMaterialFilesRetryTarget($task, false);
            } elseif ($ocrRetry) {
                if ((string) $task->task_type !== 'file_parse' || (string) $task->resource_type !== 'file') {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no local OCR parse to recover');
                }
            } else {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task retry target is invalid');
            }
        }
        if ($authorizeTaskRetry === null) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Task retry authorization is unavailable');
        }
        $authorizeTaskRetry($task);
        $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
        $freshAuthorization = null;
        if (in_array((string) $task->task_type, ['file_parse', 'audio_transcription', 'ai_completion', 'agent_run', 'structured_workflow'], true)) {
            if ($authorizeResourceRetry === null || !is_array($taskInput)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Resource authorization is required before retry');
            }
            if ($target === null && !TaskAttemptPolicy::manualRetryAllowed((int) $task->attempt_count, (int) $task->max_attempts)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'An exhausted task requires an explicit new execution, not an implicit retry limit reset');
            }
            if ($this->agentChildToolDispatchState($taskId) !== null) {
                throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', '请保留原任务及工具请求，确认已有工具结果后继续恢复');
            }
            DispatchRetryPolicy::assertRetryable($this->providerVisibleDispatchStateForTask($taskId));
            $freshAuthorization = $authorizeResourceRetry($task);
            if (!is_array($freshAuthorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Resource authorization is required before retry');
            }
        }
        $now = date('Y-m-d H:i:s');
        return Db::connect('pgsql')->transaction(function () use ($task, $taskId, $environmentId, $now, $trustedIdentityScope, $taskInput, $freshAuthorization, $target,
            $ocrRetry, $ocrRetryKey, $revalidateTaskRetry,
            $expectedAttemptCount, $expectedState, $expectedFailedFiles, $expectedReviewAttemptCount, $materialBudgetRecovery): array {
        if ($this->agentChildToolDispatchState($taskId, true) !== null) {
            throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', '请保留原任务及工具请求，确认已有工具结果后继续恢复');
        }
        DispatchRetryPolicy::assertRetryable($this->providerVisibleDispatchStateForTask($taskId, true));
        if ((string) $task->task_type === 'structured_workflow') {
            $this->assertWorkflowSourcesRetryable($environmentId, $taskId);
        }
        if ((string) $task->task_type === 'agent_run') {
            (new AgentFileUsageGateway())->assertRetryableSources($environmentId, $taskId);
        }
        if ((string) $task->task_type === 'ai_completion'
            && is_array($taskInput) && !isset($taskInput['_meeting_summary'])) {
            AiTaskGateway::lockSourceFiles($environmentId, $taskInput['source_block_ids'] ?? []);
            AiTaskGateway::assertCurrentSourceFacts(
                $task, AiTaskGateway::attributionFromPersistedInput($taskInput, $environmentId),
            );
        }
        $audioFileId = null;
        $requiresAudioFile = false;
        if ((string) $task->task_type === 'audio_transcription') {
            $requiresAudioFile = true;
            $audioFileId = is_array($taskInput) ? ($taskInput['file_id'] ?? null) : null;
        } elseif ((string) $task->task_type === 'ai_completion'
            && is_array($taskInput) && isset($taskInput['_meeting_summary'])) {
            $requiresAudioFile = true;
            $meeting = $taskInput['_meeting_summary'];
            $audioFileId = is_array($meeting) ? ($meeting['file_id'] ?? null) : null;
        }
        if ($requiresAudioFile) {
            if (!is_int($audioFileId) || $audioFileId < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Audio retry source is invalid');
            }
            $audioFile = AiFile::where('id', $audioFileId)
                ->where('environment_id', $environmentId)->lock(true)->find();
            if ($audioFile === null || (string) $audioFile->state !== 'uploaded'
                || (int) $audioFile->status !== 1 || $audioFile->delete_time !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
            }
        }
        $current = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)->lock(true)->find();
        if ($ocrRetry && $current !== null) {
            $replayed = $this->replayBeforeDispatchRetry($current, $trustedIdentityScope, $freshAuthorization,
                $revalidateTaskRetry, $ocrRetryKey, $expectedAttemptCount, $expectedState);
            if ($replayed !== null) return $replayed;
        }
        if ($current === null || (string) $current->state !== ($materialBudgetRecovery ? (string) $task->state : TaskState::FAILED)
            || ($materialBudgetRecovery && (int) $current->version !== (int) $task->version)
            || (int) $current->attempt_count !== (int) $task->attempt_count
            || (int) $current->max_attempts !== (int) $task->max_attempts
            || $current->content_purged_time !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Task state changed before it could be retried');
        }
        $this->assertCurrentActionExpectation($current, $expectedAttemptCount, $expectedState);
        if ($materialBudgetRecovery) {
            $revalidateTaskRetry($current);
            $this->restoreMaterialBudgetStop($current, $target, $freshAuthorization);
        }
        if ($target === 'cross_file_review') {
            $this->assertCrossFileReviewRetryTarget($current, true, $expectedReviewAttemptCount);
        } elseif ($target === 'failed_files') {
            $this->assertFailedMaterialFilesRetryTarget($current, true, $expectedFailedFiles);
        }
        $ocrRetryStep = $ocrRetry ? (new FileParseGateway())->assertBeforeDispatchRetryTask($current, $trustedIdentityScope) : null;
        if ($target === null && !TaskAttemptPolicy::manualRetryAllowed((int) $task->attempt_count, (int) $task->max_attempts)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'An exhausted task requires an explicit new execution, not an implicit retry limit reset');
        }
        $retryLimit = $target === null ? (int) $task->max_attempts
            : TaskAttemptPolicy::explicitMaterialRetryLimit((int) $task->attempt_count, (int) $task->max_attempts);
        if ($ocrRetryStep !== null) {
            $this->revalidateBeforeDispatchRetry($current, $trustedIdentityScope, $freshAuthorization, $revalidateTaskRetry);
            $context = $ocrRetryStep->context;
            $context['_ocr_before_dispatch_retry'] = [
                'version' => 1, 'target' => FileParseGateway::BEFORE_DISPATCH_RETRY_TARGET,
                'request_key' => $ocrRetryKey,
                'request_fingerprint' => $this->beforeDispatchRetryFingerprint($current, $expectedAttemptCount, $expectedState),
                'previous_attempt_count' => (int) $current->attempt_count, 'retry_attempt_limit' => $retryLimit,
            ];
            $ocrRetryStep->save(['context' => $context]);
        }
        $retryInput = $taskInput;
        if ($freshAuthorization !== null && is_array($retryInput)) {
            if (isset($retryInput['_meeting_summary'])) {
                if (!is_array($retryInput['_meeting_summary'])
                    || !is_array($freshAuthorization['task_authorization'] ?? null)
                    || !is_array($freshAuthorization['audio_authorization'] ?? null)) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Meeting retry authorization is unavailable');
                }
                $retryInput['_meeting_summary']['audio_authorization'] = $freshAuthorization['audio_authorization'];
                $retryInput['iam_authorization'] = $freshAuthorization['task_authorization'];
            } else {
                $retryInput['iam_authorization'] = $freshAuthorization;
            }
        }
        if (is_array($retryInput) && $freshAuthorization !== null) {
            unset($retryInput['_receipt_only_review']);
        }
        $updated = TaskRun::where('id', $taskId)->where('state', TaskState::FAILED)
            ->where('attempt_count', (int) $task->attempt_count)
            ->where('max_attempts', (int) $task->max_attempts)->update([
            'state' => TaskState::QUEUED,
            'max_attempts' => $retryLimit,
            ...($freshAuthorization === null ? [] : ['input' => json_encode(
                $retryInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )]),
            'available_at' => $now,
            'locked_at' => null,
            'locked_by' => null,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expire_time' => null,
            'heartbeat_time' => null,
            'lease_fence' => \think\facade\Db::raw('lease_fence + 1'),
            'version' => \think\facade\Db::raw('version + 1'),
            'started_at' => null,
            'completed_at' => null,
            'error_code' => null,
            'error_summary' => null,
        ]);
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Task state changed before it could be retried');
        }
        $retrySteps = TaskStep::where('task_id', $taskId)->where('state', TaskState::FAILED);
        if ($this->durableMeeting($current)) $retrySteps->where('sequence_no', 1);
        $stepsUpdated = $retrySteps->update([
            'state' => TaskState::QUEUED,
            'started_at' => null,
            'completed_at' => null,
            'error_code' => null,
            'error_summary' => null,
        ]);
        if ($stepsUpdated < 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task step changed before it could be retried');
        }
        $this->updateWorkflowFiles($task, TaskState::QUEUED);
        if ((string) $task->task_type === 'file_parse') {
            (new FileParseGateway())->retryTask($task, (string) $trustedIdentityScope);
        }
        $task = TaskRun::where('id', $taskId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        $this->audit($environmentId, 'task.retry_queued', $taskId, 'Requeued SandAI task', [
            'task_type' => (string) $task->task_type,
            'previous_attempt_count' => (int) $task->attempt_count,
            'retry_attempt_limit' => (int) $task->max_attempts,
            'target' => $target,
            ...($ocrRetry ? ['request_fingerprint' => $this->beforeDispatchRetryFingerprint($current, $expectedAttemptCount, $expectedState)] : []),
        ]);
        if ($ocrRetry) {
            $this->revalidateBeforeDispatchRetry($task, $trustedIdentityScope, $freshAuthorization, $revalidateTaskRetry);
        }
        return $this->summary($task, true);
        });
    }

    private function beforeDispatchRetryFingerprint(TaskRun $task, int $attempt, string $state): string
    {
        return \app\Application\Gateway\RequestPayloadFingerprint::fingerprint([
            'target' => FileParseGateway::BEFORE_DISPATCH_RETRY_TARGET, 'task_id' => (int) $task->id,
            'file_id' => (int) $task->resource_id, 'file_parse_id' => (int) $task->file_parse_id,
            'expected_attempt_count' => $attempt, 'expected_state' => $state,
        ]);
    }

    private function revalidateBeforeDispatchRetry(TaskRun $task, string $scope, array $authorization, \Closure $revalidate): void
    {
        (new TaskInputAccessGuard())->assert($task, $scope);
        $revalidate($task);
        (new \app\Identity\Infrastructure\FileParseAccessGuard())->revalidateQueued($authorization, $task);
    }

    private function replayBeforeDispatchRetry(TaskRun $task, string $scope, array $authorization, \Closure $revalidate,
        string $key, int $attempt, string $state): ?array
    {
        $receipt = (new FileParseGateway())->beforeDispatchRetryReceipt($task, true);
        if ($receipt === null) return null;
        $this->revalidateBeforeDispatchRetry($task, $scope, $authorization, $revalidate);
        if (!hash_equals($receipt['request_key'], $key)
            || !hash_equals($receipt['request_fingerprint'], $this->beforeDispatchRetryFingerprint($task, $attempt, $state))) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original OCR task already used its one before-dispatch recovery');
        }
        return $this->summary($task, true);
    }

    /** Enqueue one explicit new node attempt without changing any retained provider outcome. */
    private function correctMeetingOutput(TaskRun $expected, ?string $scope, ?\Closure $authorizeResource,
        ?\Closure $authorizeTask, ?\Closure $revalidate, ?string $key, ?string $code, ?int $nodeAttempt,
        ?int $taskAttempt, ?string $expectedState): array
    {
        if ($authorizeResource === null || $authorizeTask === null || $revalidate === null || !is_string($scope) || $scope === ''
            || !is_string($key) || preg_match('/\Ameeting_correction:[a-f0-9]{64}\z/D', $key) !== 1
            || !is_string($code) || !is_int($nodeAttempt) || $nodeAttempt < 1 || $nodeAttempt >= 3
            || !is_int($taskAttempt) || $expectedState !== TaskState::FAILED) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Meeting correction requires current authorization and original failed attempts');
        }
        return Db::connect('pgsql')->transaction(function () use ($expected, $scope, $authorizeResource, $authorizeTask,
            $revalidate, $key, $code, $nodeAttempt, $taskAttempt): array {
            $task = TaskRun::where('id', (int)$expected->id)->where('environment_id', (int)$expected->environment_id)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
            (new TaskInputAccessGuard())->assert($task, $scope);
            (new StructuredTaskAccessGuard())->assert($task, $scope);
            $authorizeTask($task);
            $fresh = $authorizeResource($task);
            if (!is_array($fresh) || !is_array($fresh['task_authorization'] ?? null) || !is_array($fresh['audio_authorization'] ?? null)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current meeting authorization is unavailable');
            }
            $input = is_object($task->input) ? (array)$task->input : $task->input;
            if (!is_array($input)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting input is unavailable');
            // Migration 127 freezes business input after planning. Correction intent belongs
            // to the existing parent lifecycle context; only IAM may refresh in task.input.
            $parentStep = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)
                ->where('step_code', 'invoke_model')->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($parentStep === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Meeting parent lifecycle is unavailable');
            $parentContext = is_object($parentStep->context) ? (array)$parentStep->context : $parentStep->context;
            if ($parentContext === null) $parentContext = [];
            if (!is_array($parentContext)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting parent context is invalid');
            $entries = $parentContext[\app\Application\Audio\MeetingOutputCorrection::CONTEXT_KEY] ?? [];
            if (!is_array($entries) || !array_is_list($entries) || count($entries) > 256) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Meeting correction history is unavailable');
            foreach ($entries as $entry) {
                if (($entry['request_key'] ?? null) !== $key) continue;
                if (($entry['node_code'] ?? null) !== $code || ($entry['previous_attempt'] ?? null) !== $nodeAttempt
                    || ($entry['previous_task_attempt'] ?? null) !== $taskAttempt) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'This correction key belongs to another node attempt');
                $revalidate($task);
                // Replaying the accepted intent refreshes only its current caller's
                // resource decisions. Frozen input, attempts and dispatches stay owned.
                $replacement = (new TaskAuthorizationContinuation())->replace($task, $fresh);
                $input['iam_authorization'] = $replacement['iam_authorization'];
                $input['_meeting_summary']['audio_authorization'] = $replacement['_meeting_summary']['audio_authorization'];
                $changed = TaskRun::where('id', (int)$task->id)->where('environment_id', (int)$task->environment_id)
                    ->where('version', (int)$task->version)->where('state', (string)$task->state)
                    ->where('attempt_count', (int)$task->attempt_count)->update([
                        'input'=>json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'version'=>Db::raw('version + 1'),
                    ]);
                if ($changed !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The accepted meeting correction changed before authorization refresh');
                $current = TaskRun::where('id', (int)$task->id)->find();
                if ($current === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                return $this->summary($current);
            }
            if ((string)$task->task_type !== 'ai_completion' || (string)$task->state !== TaskState::FAILED
                || (string)$task->error_code !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'
                || (int)$task->attempt_count !== $taskAttempt
                || !TaskAttemptPolicy::manualRetryAllowed($taskAttempt, (int)$task->max_attempts)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original failed meeting attempt changed or is exhausted');
            }
            if (count($entries) >= 256 || $task->lease_owner !== null || $task->lease_token !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The meeting has an active lease or exhausted correction history');
            }
            $nodes = MeetingSummaryNode::where('task_id', (int)$task->id)->where('node_code', $code)->lock(true)->select();
            if (count($nodes) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original meeting node is unavailable');
            $node = $nodes[0];
            $step = TaskStep::where('id', (int)$node->task_step_id)->where('task_id', (int)$task->id)->lock(true)->find();
            if ($step === null || (int)$step->attempt_count !== $nodeAttempt) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original meeting node attempt changed');
            $firstPending = Db::connect('pgsql')->query(
                "SELECT n.id FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step s ON s.id=n.task_step_id AND s.task_id=n.task_id "
                . "WHERE n.task_id=? AND s.state<>'succeeded' ORDER BY s.sequence_no LIMIT 1", [(int)$task->id]);
            if (count($firstPending) !== 1 || (int)$firstPending[0]['id'] !== (int)$node->id) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only the current first rejected meeting node can be corrected');
            }
            $marker = \app\Application\Audio\MeetingOutputCorrection::evidence($task, $node, $step);
            $entries[] = ['version'=>1, 'request_key'=>$key, 'previous_task_attempt'=>$taskAttempt] + $marker;
            $parentContext[\app\Application\Audio\MeetingOutputCorrection::CONTEXT_KEY] = $entries;
            if (is_array($parentContext['authorization_continuation'] ?? null)
                && isset($parentContext['authorization_continuation']['meeting_task_attempt'])) {
                if (($parentContext['authorization_continuation']['required'] ?? false) === true) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current authorization must be formally continued before meeting correction');
                }
                // This distinct, explicitly authorized paid correction supersedes the
                // consumed stage permission while retaining its intent history.
                $parentContext['authorization_continuation']['meeting_retired'] = true;
            }
            $input['iam_authorization'] = $fresh['task_authorization'];
            $input['_meeting_summary']['audio_authorization'] = $fresh['audio_authorization'];
            $revalidate($task);
            $changed = TaskRun::where('id', (int)$task->id)->where('state', TaskState::FAILED)
                ->where('attempt_count', $taskAttempt)->where('version', (int)$task->version)->update([
                    'input'=>json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'state'=>TaskState::QUEUED,
                    'available_at'=>date('Y-m-d H:i:s'), 'completed_at'=>null, 'started_at'=>null,
                    'error_code'=>null, 'error_summary'=>null, 'lease_owner'=>null, 'lease_token'=>null,
                    'lease_expire_time'=>null, 'heartbeat_time'=>null, 'locked_at'=>null, 'locked_by'=>null,
                    'lease_fence'=>Db::raw('lease_fence + 1'), 'version'=>Db::raw('version + 1'),
                ]);
            $parentChanged = TaskStep::where('id', (int)$parentStep->id)->where('task_id', (int)$task->id)->where('state', TaskState::FAILED)
                ->update(['state'=>TaskState::QUEUED,
                    'context'=>json_encode($parentContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'started_at'=>null, 'completed_at'=>null, 'error_code'=>null, 'error_summary'=>null]);
            if ($changed !== 1 || $parentChanged !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Meeting correction changed before enqueue');
            $this->audit((int)$task->environment_id, 'task.meeting_output_correction_queued', (int)$task->id,
                'Explicit rejected meeting node correction queued', ['request_key'=>$key] + $marker);
            return $this->summary(TaskRun::where('id', (int)$task->id)->find());
        });
    }

    /** Same task, current caller intent. Provider-visible identities remain in receipt recovery. */
    private function continueAuthorization(TaskRun $expected, ?string $scope, ?\Closure $authorizeResource,
        ?\Closure $authorizeTask, ?\Closure $revalidate, ?string $key): array
    {
        if ($authorizeResource === null || $authorizeTask === null || $revalidate === null
            || !is_string($scope) || $scope === '' || !is_string($key)
            || preg_match('/\Atask_auth_continue:[a-f0-9]{64}\z/D', $key) !== 1) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current task continuation authorization is required');
        }
        return Db::connect('pgsql')->transaction(function () use ($expected, $scope, $authorizeResource, $authorizeTask, $revalidate, $key): array {
            $task = TaskRun::where('id', (int) $expected->id)->where('environment_id', (int) $expected->environment_id)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
            (new TaskInputAccessGuard())->assert($task, $scope);
            (new StructuredTaskAccessGuard())->assert($task, $scope);
            $policy = new TaskAuthorizationContinuation();
            if (!$policy->supported($task)) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task uses its existing domain continuation');
            $authorizeTask($task);
            $fresh = $authorizeResource($task);
            if (!is_array($fresh)) throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Current resource authorization is unavailable');
            // The audit intent is atomic with the task mutation. Replaying an accepted
            // key reads it, even if the worker already advanced or paused again.
            $applied = AuditLog::where('resource_type', 'task')->where('resource_id', (int) $task->id)
                ->where('action', 'task.authorization_continued')->whereRaw("context->>'continuation_key'=?", [$key])->find();
            if ($applied !== null) {
                $revalidate($task);
                return $this->summary($task);
            }
            $availability = $policy->present($task);
            if (($availability['available'] ?? false) !== true || (int) $task->version !== (int) $expected->version) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Task authorization continuation changed or is unavailable');
            }
            $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->whereIn('state', [TaskState::FAILED, TaskState::QUEUED, TaskState::RETRYING])
                ->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($step === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Paused task step is unavailable');
            if (TaskRun::where('id', (int) $task->id)->whereRaw(
                'lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskAuthorizationContinuation::leaseTimezone($task)],
            )->value('id') !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'An active task lease cannot be replaced by continuation');
            }
            $previous = TaskAuthorizationContinuation::input($task);
            if ((string) $task->task_type === 'structured_workflow') $this->assertWorkflowSourcesRetryable((int) $task->environment_id, (int) $task->id);
            $replacement = $policy->replace($task, $fresh);
            $receiptPending = (string) $task->state === TaskState::RECOVERY_PENDING
                || $this->providerVisibleDispatchStateForTask((int) $task->id, true) !== null
                || ((string) $task->task_type === 'audio_transcription' && (new TaskAudioReceiptContinuation())->visible($task));
            $state = $receiptPending ? TaskState::RECOVERY_PENDING : TaskState::QUEUED;
            $context = TaskAuthorizationContinuation::context($step);
            $context['authorization_continuation'] = [
                'version' => TaskAuthorizationContinuation::VERSION, 'required' => false, 'intent_key' => $key,
            ];
            $revalidate($task);
            // New decisions are checked after all source/ledger locks, adjacent to write.
            $replacement = $policy->replace($task, $fresh);
            $identityValues = $policy->workflowIdentityValues($task, $scope);
            $meetingContinuation = $this->durableMeeting($task);
            if ($meetingContinuation) {
                // Only the two mutable authorization fields enter frozen meeting input.
                $replacement = $previous;
                $replacement['iam_authorization'] = $fresh['task_authorization'];
                $replacement['_meeting_summary']['audio_authorization'] = $fresh['audio_authorization'];
                $context = TaskAuthorizationContinuation::acceptMeeting($task, $context, $replacement, $key, $scope);
            }
            $updated = TaskRun::where('id', (int) $task->id)->where('version', (int) $task->version)
                ->where('state', (string) $task->state)->update([
                    ...$identityValues,
                    'input' => json_encode($replacement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'state' => $state, 'available_at' => date('Y-m-d H:i:s'),
                    ...($meetingContinuation ? [] : [
                        'max_attempts' => max((int) $task->max_attempts, (int) $task->attempt_count + 1),
                        'local_recovery_max' => max((int) $task->local_recovery_max, (int) $task->local_recovery_count + 1),
                    ]),
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'completed_at' => null,
                    'error_code' => $receiptPending ? 'SAND_AI_LOCAL_RECOVERY_PENDING' : null,
                    'error_summary' => $receiptPending ? 'Current authorization accepted; only the original outcome may be recovered' : null,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', (string) $step->state)->update([
                'state' => $receiptPending ? TaskState::FAILED : TaskState::QUEUED,
                'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'completed_at' => null, 'error_code' => null, 'error_summary' => null,
            ]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'current authorization continuation');
            // Do not reset child steps or original file/node generations here.
            if ((string) $task->task_type === 'file_parse') (new FileParseGateway())->retryTask($task, $scope);
            $this->audit((int) $task->environment_id, 'task.authorization_continued', (int) $task->id,
                'Current caller explicitly continued the original task', [
                    'continuation_key' => $key, 'task_type' => (string) $task->task_type,
                    'previous_authorization_ids' => TaskAuthorizationContinuation::authorizationIds($previous),
                    'current_authorization_ids' => TaskAuthorizationContinuation::authorizationIds($replacement),
                    'previous_task_version' => (int) $task->version, 'receipt_only_until_settled' => $receiptPending,
                    'original_task_request_id' => (string) $task->request_id, 'provider_reissued' => false,
                ]);
            $this->reviseWorkflowTask($task, $state);
            $current = TaskRun::where('id', (int) $task->id)->find();
            if ($current === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
            return $this->summary($current);
        });
    }

    /** Stop new work without rewriting any child dispatch, sealed result or fee. */
    private function pauseAuthorization(TaskRun $expected, TaskExecutionLease $lease, string $code): void
    {
        Db::connect('pgsql')->transaction(function () use ($expected, $lease, $code): void {
            $this->heartbeat($lease);
            $task = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->lock(true)->find();
            if ($task === null || $step === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Authorization pause lease changed');
            if ($this->durableMeeting($task)) {
                (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
            }
            $visible = $this->providerVisibleDispatchStateForTask($lease->taskId, true) !== null || $this->materialFinalizationPending($task);
            if ((string) $task->task_type === 'audio_transcription') $visible = $visible || (new TaskAudioReceiptContinuation())->visible($task);
            $state = $visible ? TaskState::RECOVERY_PENDING : TaskState::FAILED;
            $context = TaskAuthorizationContinuation::context($step);
            if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)) {
                $context = \app\Application\Channel\ChannelReceiptRecoveryGateway::pauseAuthorizationContext($task, $context, $code);
            } else {
                $context['authorization_continuation'] = [
                    ...(is_array($context['authorization_continuation'] ?? null) ? $context['authorization_continuation'] : []),
                    'version' => TaskAuthorizationContinuation::VERSION, 'required' => true,
                    ...($this->durableMeeting($task) ? ['meeting_claim_pending'=>false] : []),
                ];
            }
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])->update([
                    'state' => $state, 'error_code' => $code, 'error_summary' => 'Execution authorization ended; current caller must explicitly continue this task',
                    'available_at' => $now, 'completed_at' => $visible ? null : $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', TaskState::RUNNING)->update([
                'state' => TaskState::FAILED, 'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'error_code' => $code,
                'error_summary' => 'Current authorization is required before continuing', 'completed_at' => $now,
            ]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'authorization pause');
            if (!$this->durableMeeting($task) && (string) $task->task_type === 'ai_completion') {
                TaskStep::where('task_id', $lease->taskId)->where('sequence_no', '>', 1)->where('state', TaskState::RUNNING)
                    ->update(['state' => TaskState::FAILED, 'error_code' => $code, 'error_summary' => 'Current authorization required', 'completed_at' => $now]);
            }
            $this->updateWorkflowFiles($task, TaskState::RECOVERY_PENDING, $code);
            $pauseInput = TaskAuthorizationContinuation::input($task);
            if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)) {
                $pauseInput['iam_authorization'] = $pauseInput['_channel_receipt_recovery']['authorization']['task_authorization'] ?? null;
            }
            $this->audit((int) $task->environment_id, 'task.authorization_paused', $lease->taskId,
                'Stopped new dispatches; retained original outcomes and fees', [
                    'error_code' => $code, 'task_type' => (string) $task->task_type,
                    'authorization_ids' => TaskAuthorizationContinuation::authorizationIds($pauseInput),
                    'receipt_retained' => $visible, 'provider_reissued' => false,
                ]);
        });
    }

    private function assertWorkflowSourcesRetryable(int $environmentId, int $taskId): void
    {
        $db = Db::connect('pgsql');
        $runs = $db->query(
            'SELECT id FROM sand_ai_workflow_run
             WHERE task_id=? AND environment_id=?',
            [$taskId, $environmentId],
        );
        if (count($runs) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow run is unavailable');
        }
        $files = $db->query(
            'SELECT f.id,f.state,f.status,f.delete_time
             FROM sand_ai_workflow_run_file rf
             JOIN sand_ai_file f ON f.id=rf.file_id
             WHERE rf.workflow_run_id=? AND f.environment_id=?
             ORDER BY f.id FOR UPDATE OF f',
            [(int) $runs[0]['id'], $environmentId],
        );
        if ($files === []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow source files are unavailable');
        }
        foreach ($files as $file) {
            if ($file['state'] !== 'parsed' || (int) $file['status'] !== 1
                || $file['delete_time'] !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow source files are unavailable');
            }
        }
        $current = $db->query(
            'SELECT content_purged_time FROM sand_ai_workflow_run
             WHERE id=? AND environment_id=? FOR SHARE',
            [(int) $runs[0]['id'], $environmentId],
        );
        if (count($current) !== 1 || $current[0]['content_purged_time'] !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Workflow content has been purged');
        }
    }

    private function assertActionExpectation(?int $attemptCount, ?string $state): void
    {
        if ($attemptCount === null && $state === null) return;
        if ($attemptCount === null || $state === null || $attemptCount < 0 || $attemptCount > 9007199254740991
            || !in_array($state, [
                TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RUNNING,
                TaskState::RETRYING, TaskState::RECOVERY_PENDING, TaskState::SUCCEEDED,
                TaskState::FAILED, TaskState::CANCELED,
            ], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task action expectations are invalid');
        }
    }

    private function assertCurrentActionExpectation(TaskRun $task, ?int $attemptCount, ?string $state): void
    {
        if ($attemptCount !== null && ((int) $task->attempt_count !== $attemptCount || (string) $task->state !== $state)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original task attempt or state changed before this action');
        }
    }

    private function assertMaterialRetryExpectation(?string $target, ?string $mode, ?int $taskAttempt,
        ?array $files, ?int $reviewAttempt): void
    {
        if ($mode !== null && ($taskAttempt !== null || $files !== null || $reviewAttempt !== null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task action expectations require an ordinary retry');
        }
        if ($files !== null) {
            if ($target !== 'failed_files' || $taskAttempt === null || $reviewAttempt !== null
                || !array_is_list($files) || $files === []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected failed material files are invalid');
            }
            $previousId = 0;
            foreach ($files as $file) {
                if (!is_array($file) || count($file) !== 2
                    || !is_int($file['file_id'] ?? null) || $file['file_id'] <= $previousId
                    || $file['file_id'] > 9007199254740991
                    || !is_int($file['attempt_count'] ?? null) || $file['attempt_count'] < 0
                    || $file['attempt_count'] > 9007199254740991) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected failed material files are invalid');
                }
                $previousId = $file['file_id'];
            }
        }
        if ($reviewAttempt !== null && ($target !== 'cross_file_review' || $taskAttempt === null
            || $reviewAttempt < 0 || $reviewAttempt > 9007199254740991)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected material review attempt is invalid');
        }
    }

    /** Read-only proof; this never prepares or dispatches a call. */
    private function materialBudgetStopRecovery(TaskRun $task, ?string $target): ?array
    {
        if (!in_array($target, [null, 'cross_file_review', 'failed_files'], true)
            || (string) $task->task_type !== 'structured_workflow' || (string) $task->resource_type !== 'workflow_run') return null;
        $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null || $run->content_purged_time !== null || (string) $run->workflow_code !== 'material_analysis') return null;
        $chunks = new \app\Application\Workflow\MaterialChunkRunner();
        $currentChunkBudgetStop = $chunks->hasCurrentBudgetStop($run, $task);
        if ($currentChunkBudgetStop && $target !== 'failed_files') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The current material budget stop requires an explicit failed-file retry');
        }
        $projection = $target === 'cross_file_review'
            ? (new MaterialCrossFileReviewChunkRunner())->budgetStopRecovery($run, $task)
            : ($target === 'failed_files' ? $chunks->budgetStopRecovery($run, $task) : null);
        if ($projection === null && $currentChunkBudgetStop) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The current material budget stop requires its original rejection proof');
        }
        return $projection === null ? null : ['run' => $run, 'projection' => $projection];
    }

    /** Called only under the existing retry transaction and original task CAS. */
    private function restoreMaterialBudgetStop(TaskRun $task, string $target, ?array $authorization): void
    {
        $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)->lock(true)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->lock(true)->find();
        $review = $run === null ? null : WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
        $files = $run === null ? [] : WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
        if ($run === null || $step === null || !is_array($authorization) || (string) $step->state !== TaskState::FAILED
            || (int) $task->status !== 1 || $task->delete_time !== null
            || $task->canceled_at !== null || $task->content_purged_time !== null
            || $task->lease_owner !== null || $task->lease_token !== null || $task->lease_expire_time !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original material budget stop changed before retry');
        }
        (new \app\Identity\Infrastructure\WorkflowRunAccessGuard())->revalidateQueued($authorization, $run);
        $recovery = $this->materialBudgetStopRecovery($task, $target);
        if ($recovery === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original material budget rejection cannot be proved');
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        if (!is_array($context)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original material journal changed');
        if ($target === 'cross_file_review') {
            if ($review === null || !in_array((string) $review->state, ['failed', 'recovery_pending'], true)
                || $review->last_invocation_id !== null
                || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original material review changed before retry');
            }
            $context['material_cross_file_chunks'] = $recovery['projection'];
            $review->save(['state' => 'failed', 'error_code' => 'SAND_AI_BUDGET_EXCEEDED', 'failure_summary' => null]);
        } else {
            foreach ($recovery['projection']['journals'] as $id => $journal) {
                $context['material_chunks']['file_' . $id] = $journal;
            }
            foreach ($files as $file) {
                $attempt = $recovery['projection']['files'][(int) $file->id] ?? null;
                if ($attempt === null) continue;
                if ((int) $file->attempt_count !== $attempt || $file->last_invocation_id !== null
                    || WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find() !== null
                    || !in_array((string) $file->analysis_state, ['running', 'failed', 'recovery_pending'], true)) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original material file changed before retry');
                }
                $file->save(['analysis_state' => 'failed', 'failure_summary' => 'SAND_AI_BUDGET_EXCEEDED']);
            }
        }
        $step->save(['context' => $context, 'error_code' => 'SAND_AI_BUDGET_EXCEEDED']);
        $task->save(['state' => TaskState::FAILED, 'error_code' => 'SAND_AI_BUDGET_EXCEEDED',
            'error_summary' => 'Original material call stopped before provider send because its budget was insufficient']);
    }

    private function assertCrossFileReviewRetryTarget(TaskRun $task, bool $lock, ?int $expectedAttemptCount = null): void
    {
        if ((string) $task->task_type !== 'structured_workflow'
            || (string) $task->resource_type !== 'workflow_run') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no cross-file review to retry');
        }
        $run = WorkflowRun::where('id', (int) $task->resource_id)
            ->where('environment_id', (int) $task->environment_id)
            ->where('task_id', (int) $task->id)->find();
        $input = $run === null ? null : (is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot);
        if ($run === null || (string) $run->workflow_code !== 'material_analysis'
            || !is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no cross-file review to retry');
        }
        $query = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id);
        $review = ($lock ? $query->lock(true) : $query)->find();
        $files = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->select();
        if ($review === null || !in_array((string) $review->state, ['pending', 'failed'], true)
            || $review->last_invocation_id !== null
            || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null
            || count($files) < 2) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The cross-file review is not eligible for retry');
        }
        if ($expectedAttemptCount !== null && (int) $review->attempt_count !== $expectedAttemptCount) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original cross-file review attempt changed before retry');
        }
        foreach ($files as $file) {
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find();
            if ($result === null || (string) $file->analysis_state !== 'succeeded'
                || (int) $file->attempt_count !== (int) $result->attempt_no
                || (int) $file->last_invocation_id !== (int) $result->invocation_id
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A sealed material file changed before review retry');
            }
        }
    }

    private function assertFailedMaterialFilesRetryTarget(TaskRun $task, bool $lock, ?array $expectedFailedFiles = null): void
    {
        if ((string) $task->task_type !== 'structured_workflow'
            || (string) $task->resource_type !== 'workflow_run') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no failed material files to retry');
        }
        $run = WorkflowRun::where('id', (int) $task->resource_id)
            ->where('environment_id', (int) $task->environment_id)
            ->where('task_id', (int) $task->id)
            ->where('status', 1)->whereNull('delete_time')->find();
        $input = $run === null ? null : (is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot);
        if ($run === null || (string) $run->workflow_code !== 'material_analysis'
            || !is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no failed material files to retry');
        }
        $query = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id');
        $files = ($lock ? $query->lock(true) : $query)->select();
        $failed = 0;
        $failedFiles = [];
        foreach ($files as $file) {
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find();
            if ($result !== null) {
                if ((string) $file->analysis_state !== 'succeeded'
                    || (int) $file->attempt_count !== (int) $result->attempt_no
                    || (int) $file->last_invocation_id !== (int) $result->invocation_id
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A sealed material file changed before retry');
                }
                continue;
            }
            if ((string) $file->analysis_state !== 'failed') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A material file is not eligible for retry');
            }
            ++$failed;
            $failedFiles[] = ['file_id' => (int) $file->file_id, 'attempt_count' => (int) $file->attempt_count];
        }
        if ($failed < 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no failed material files to retry');
        }
        if ($expectedFailedFiles !== null) {
            usort($failedFiles, static fn (array $left, array $right): int => $left['file_id'] <=> $right['file_id']);
            if ($failedFiles !== $expectedFailedFiles) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original failed material file set changed before retry');
            }
        }
        if (count($files) > 1) {
            $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
            if ($review === null || (string) $review->state !== 'pending'
                || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review changed before file retry');
            }
        }
    }

    /** @return array<string, mixed> */
    public function summary(TaskRun $task, bool $withSteps = false, ?\Closure $authorizeMeetingResult = null): array
    {
        $result = [
            'id' => (int) $task->id,
            'task_type' => (string) $task->task_type,
            'resource_type' => (string) $task->resource_type,
            'resource_id' => (int) $task->resource_id,
            'file_parse_id' => $task->file_parse_id === null ? null : (int) $task->file_parse_id,
            'state' => (string) $task->state,
            'attempt_count' => (int) $task->attempt_count,
            'max_attempts' => (int) $task->max_attempts,
            'available_at' => $task->available_at,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'canceled_at' => (string) $task->state === TaskState::CANCELED ? $task->completed_at : null,
            'error_code' => $task->error_code,
            'error_summary' => $task->error_summary,
            'cleanup' => (new \app\Application\File\CleanupStatusProjection())->task($task),
        ];
        $authorizationContinuation = (new TaskAuthorizationContinuation())->present($task);
        if ($authorizationContinuation !== null) $result['authorization_continuation'] = $authorizationContinuation;
        if ((string) $task->task_type === 'file_parse') {
            $result['parse_restart'] = (new FileParseGateway())->restartPermission($task);
            $result['ocr_before_dispatch_retry'] = (new FileParseGateway())->beforeDispatchRetryPermission($task);
        }
        if ($this->neuronAgent($task) && !isset($task->input['_channel'])) {
            $result['continuation'] = (new \app\Application\Agent\AgentContinuationGateway())->availability($task);
        }
        $channelRecovery = (new \app\Application\Channel\ChannelReceiptRecoveryGateway())->projection($task);
        if ($channelRecovery !== null) $result['channel_receipt_recovery'] = $channelRecovery;
        $channelMetadataOnly = $channelRecovery !== null
            && in_array((string) $task->state, [TaskState::FAILED, TaskState::RECOVERY_PENDING], true);
        if ((string) $task->task_type === 'structured_workflow'
            && (string) $task->resource_type === 'workflow_run'
            && (string) $task->state === TaskState::RECOVERY_PENDING) {
            $run = WorkflowRun::where('id', (int) $task->resource_id)
                ->where('task_id', (int) $task->id)->where('environment_id', (int) $task->environment_id)
                ->where('status', 1)->whereNull('delete_time')->find();
            $frozenInput = $run === null ? null : (is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot);
            if ($run !== null && (string) $run->workflow_code === 'material_analysis'
                && is_array($frozenInput) && ($frozenInput['material_execution_mode'] ?? null) === 'single_file_v1') {
                $result['provider_outcome_state'] = null;
                $unknown = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('environment_id', (int) $task->environment_id)
                    ->where('state', 'ambiguous')->find();
                if ($unknown !== null) {
                    $invocation = Invocation::where('id', (int) $unknown->invocation_id)
                        ->where('environment_id', (int) $task->environment_id)->find();
                    if ($invocation === null
                        || (int) $invocation->model_id !== (int) $unknown->model_id
                        || (int) $invocation->deployment_id !== (int) $unknown->deployment_id) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Provider outcome attribution is unavailable');
                    }
                    $result['provider_outcome_state'] = 'unconfirmed';
                }
                $result['receipt_only_recovery'] = [
                    'target' => 'cross_file_review',
                    'available' => (new \app\Application\Workflow\MaterialReviewReceiptOnlyGuard())->available($task),
                    'attempt_count' => (int) $task->local_recovery_count,
                    'max_attempts' => (int) $task->local_recovery_max,
                ];
            }
        }
        if ($withSteps) {
            $result['steps'] = TaskStep::where('task_id', (int) $task->id)->order('sequence_no')->select()->map(static fn (TaskStep $step): array => [
                'code' => (string) $step->step_code,
                'state' => (string) $step->state,
                'attempt_count' => (int) $step->attempt_count,
                'started_at' => $step->started_at,
                'completed_at' => $step->completed_at,
                'error_code' => $step->error_code,
                'error_summary' => $step->error_summary,
            ])->all();
            $audioAssembly = null;
            if ((string) $task->task_type === 'audio_transcription'
                && \app\Application\Audio\Chunk\PostgresAudioChunkAggregation::marked($task->result)) {
                $audioAssembly = (new \app\Application\Audio\Chunk\PostgresAudioChunkAggregation())->read($task);
            }
            $result['result'] = $channelMetadataOnly ? null : ($audioAssembly === null
                ? $this->safeResult($task->result, $task->input, $task, $authorizeMeetingResult) : $audioAssembly['transcript']);
            if ((string) $task->task_type === 'agent_run') {
                $result += (new AgentEmbeddingCostProjection())->present($task);
                if ($this->neuronAgent($task)) {
                    $result['agent_invocations'] = $this->agentInvocationSummaries($task);
                    $definition = $task->input['_agent_definition'] ?? null;
                    if (is_array($definition)) {
                        $versions = Db::connect('pgsql')->query('SELECT version_no,snapshot_sha256 FROM sand_ai_agent_version WHERE id=? AND agent_definition_id=? AND state=\'published\'', [$definition['version_id'] ?? 0, $definition['definition_id'] ?? 0]);
                        if ($versions === [] || !is_string($definition['snapshot_sha256'] ?? null)
                            || !hash_equals($definition['snapshot_sha256'], (string) $versions[0]['snapshot_sha256'])) {
                            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Actual agent version is unavailable');
                        }
                        $result['agent_definition_version'] = ['code' => $definition['code'], 'version_no' => (int) $versions[0]['version_no'], 'snapshot_sha256' => $definition['snapshot_sha256']];
                    }

                    if (!$channelMetadataOnly && !empty($task->input['_agent_readonly_tools'])) $result['readonly_tool_runs'] = $this->agentReadonlyToolRunSummaries($task);
                    $streamInput = is_object($task->input) ? (array) $task->input : $task->input;
                    $result['streaming_enabled'] = is_array($streamInput) && ($streamInput['_agent_stream'] ?? false) === true;
                    if (is_array($streamInput) && is_array($streamInput['_agent_conversation'] ?? null)) {
                        $context = $streamInput['_agent_conversation'];
                        $result['conversation_context'] = [
                            'conversation_id' => \app\Application\Agent\AgentConversationGateway::conversationId($streamInput['conversation_id'] ?? null),
                            'history_task_ids' => $context['history_task_ids'] ?? [],
                            'omitted_prior_turn_count' => $context['omitted_prior_turn_count'] ?? null,
                            'context_turn_limit' => $streamInput['context_turn_limit'] ?? null,
                        ];
                    }
                    $result += $this->agentSummaryProjection($task);
                }
            }
            if ((string) $task->task_type === 'audio_transcription'
                && (string) $task->state === TaskState::SUCCEEDED
                && is_array($result['result'])) {
                $input = is_object($task->input) ? (array) $task->input : $task->input;
                if (!is_array($input)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Audio task input is unavailable');
                }
                if ($audioAssembly !== null) {
                    $result['audio_usage_cost'] = $audioAssembly['audio_usage_cost'];
                } else {
                    $event = (new PostgresAudioUsageEventStore())->findByTaskRunId((int) $task->id);
                    $result['audio_usage_cost'] = (new AudioTaskCostProjection())->present(
                        (int) $task->id, (int) $task->environment_id, $input, $result['result'], $event,
                    );
                }
            }
            $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
            if ((string) $task->task_type === 'ai_completion'
                && is_array($taskInput) && is_array($taskInput['_meeting_summary'] ?? null)) {
                if (($taskInput['_meeting_summary']['planner_version'] ?? null) === MeetingSummaryPlan::CAPACITY_VERSION
                    && $task->content_purged_time === null) {
                    $attribution = UsageAttribution::fromCurrentPersisted($taskInput['_usage_attribution'] ?? null,
                        (int) $task->environment_id, 'capability', 'sand_ai.audio.transcription');
                    AiTaskGateway::assertCurrentSourceFacts($task, $attribution);
                    $capacity = \app\Application\Audio\MeetingExecutionGateway::object($taskInput['_meeting_capacity'] ?? []);
                    if (\app\Application\Audio\MeetingLocalPlanning::enabled($capacity)) {
                        \app\Application\Audio\MeetingLocalPlanning::assertNoCounterRequests($task);
                        $result['meeting_token_count_cost'] = [
                            'request_count' => 0, 'succeeded_count' => 0, 'rejected_count' => 0, 'unknown_count' => 0,
                            'metering_unit' => 'external_request', 'metering_quantity' => '0',
                            'pricing_state' => 'not_applicable', 'metered_amount' => null, 'currency' => null,
                            'token_count' => null, 'token_count_state' => 'not_measured',
                        ];
                    } else {
                        $result['meeting_token_count_cost'] = PostgresMeetingTokenCountLedger::project($task, $capacity);
                    }
                }
                if (MeetingSummaryNode::where('task_id', (int) $task->id)->count() > 0
                    || (string) $task->state !== TaskState::SUCCEEDED) {
                    $result['summary_progress'] = $this->meetingProgress($task, $taskInput);
                    $result['meeting_output_correction'] = \app\Application\Audio\MeetingOutputCorrection::availability($task);
                    $result['meeting_summary_cost'] = (new MeetingSummaryCostProjection())->present($task);
                }
            }
            if ((string) $task->task_type === 'ai_completion'
                && MeetingSummaryNode::where('task_id', (int) $task->id)->count() === 0
                && (string) $task->state === TaskState::SUCCEEDED
                && is_array($result['result'])
                && array_key_exists('meeting_summary', $result['result'])) {
                $invocationRequestId = $result['result']['invocation']['request_id'] ?? null;
                if (!is_string($invocationRequestId) || $invocationRequestId === '') {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting summary cost is unavailable');
                }
                $cost = (new UsageGateway())->invocationCost(
                    (int) $task->environment_id, $invocationRequestId,
                    static function (Invocation $invocation) use ($task): void {
                        $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)
                            ->where('parent_task_run_id', (int) $task->id)
                            ->where('execution_scope', 'ai_task')->find();
                        if ($dispatch === null || (int) $invocation->environment_id !== (int) $task->environment_id
                            || (int) $invocation->organization_id !== (int) $task->organization_id
                            || (int) $invocation->application_id !== (int) $task->application_id
                            || (int) $invocation->workload_client_id !== (int) $task->workload_client_id) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting summary invocation changed');
                        }
                    },
                );
                $result['meeting_summary_cost'] = [
                    'usage_state' => $cost['usage']['state'],
                    'input_tokens' => $cost['tokens']['input_tokens'],
                    'output_tokens' => $cost['tokens']['output_tokens'],
                    'total_tokens' => $cost['tokens']['total_tokens'],
                    'pricing_state' => $cost['price']['pricing_state'],
                    'metered_amount' => $cost['price']['metered_amount'],
                    'currency' => $cost['price']['currency'],
                    'price_revision_id' => $cost['price']['revision_id'],
                ];
            }

        }

        return $result;
    }

    /** @param array<string,mixed> $taskInput @return array<string,mixed> */
    private function meetingProgress(TaskRun $task, array $taskInput): array
    {
        if (($taskInput['_meeting_durable'] ?? false) === true) {
            $counts = Db::connect('pgsql')->query(
                "SELECT count(*) AS planned,count(*) FILTER(WHERE s.state='succeeded') AS completed,
                    count(*) FILTER(WHERE s.state='failed') AS failed,min(n.node_code) FILTER(WHERE s.state='running') AS active
                 FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step s ON s.id=n.task_step_id AND s.task_id=n.task_id WHERE n.task_id=?",
                [(int) $task->id]);
            $count = $counts[0];
            $plan = is_array($taskInput['_meeting_plan'] ?? null) ? $taskInput['_meeting_plan'] : null;
            return ['planned_nodes' => (int) $count['planned'], 'completed_nodes' => (int) $count['completed'],
                'failed_nodes' => (int) $count['failed'], 'active_node_code' => $count['active'],
                'covered_segments' => (string) $task->state === TaskState::SUCCEEDED ? (int) ($plan['eligible_segments'] ?? 0) : 0,
                'total_segments' => (int) ($taskInput['_meeting_summary']['total_segments'] ?? 0),
                'planning_state' => $plan === null ? 'pending' : 'sealed',
                'tokenizer_request_limit' => $taskInput['_meeting_capacity']['max_tokenizer_requests'] ?? null];
        }
        $nodes = MeetingSummaryNode::where('task_id', (int) $task->id)
            ->order('level_no')->order('sequence_no')->select();
        $steps = [];
        foreach (TaskStep::where('task_id', (int) $task->id)->select() as $step) {
            $steps[(int) $step->id] = $step;
        }
        $complete = 0;
        $failed = 0;
        $active = null;
        $segmentParts = [];
        foreach ($nodes as $node) {
            $step = $steps[(int) $node->task_step_id] ?? null;
            if ($step === null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting progress step is missing');
            }
            $state = (string) $step->state;
            if ($state === TaskState::SUCCEEDED) ++$complete;
            if ($state === TaskState::FAILED) ++$failed;
            if ($state === TaskState::RUNNING) $active = (string) $node->node_code;
            if ((string) $node->node_kind !== 'chunk') continue;
            $input = is_object($node->input_snapshot) ? (array) $node->input_snapshot : $node->input_snapshot;
            if (!is_array($input) || !is_array($input['segment_ids'] ?? null)) {
                if ($task->content_purged_time !== null) continue;
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting progress input changed');
            }
            foreach ($input['segment_ids'] as $id) {
                if (!is_string($id)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting progress source changed');
                }
                $segmentParts[$id] = ($segmentParts[$id] ?? true) && $state === TaskState::SUCCEEDED;
            }
        }
        $meeting = $taskInput['_meeting_summary'];
        $total = is_int($meeting['total_segments'] ?? null) ? $meeting['total_segments'] : 0;
        if ($total === 0 && $task->content_purged_time === null) {
            $identity = UsageAttribution::fromCurrentPersisted(
                $taskInput['_usage_attribution'] ?? null, (int) $task->environment_id,
                'capability', 'sand_ai.audio.transcription',
            );
            $source = (new MeetingSummarySource())->load(
                (int) $task->environment_id, (int) $meeting['audio_task_id'], $identity,
                (string) $meeting['sha256'], (int) $meeting['review_revision'],
            );
            $total = count($source['transcript']['segments']);
        }
        return [
            'planned_nodes' => count($nodes), 'completed_nodes' => $complete,
            'failed_nodes' => $failed, 'active_node_code' => $active,
            'covered_segments' => $task->content_purged_time !== null
                && (string) $task->state === TaskState::SUCCEEDED
                && is_int($meeting['eligible_segments'] ?? null)
                    ? $meeting['eligible_segments'] : count(array_filter($segmentParts)),
            'total_segments' => $total,
            'planning_state' => ($meeting['planner_version'] ?? null) !== MeetingSummaryPlan::CAPACITY_VERSION
                ? 'legacy' : (is_array($taskInput['_meeting_plan'] ?? null) ? 'sealed' : 'pending'),
            'tokenizer_request_limit' => $taskInput['_meeting_capacity']['max_tokenizer_requests'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function safeResult(mixed $value, mixed $taskInput, TaskRun $task, ?\Closure $authorizeMeetingResult = null): ?array
    {
        $value = is_object($value) ? (array) $value : $value;
        if (!is_array($value)) {
            return null;
        }
        if (($value['schema']['code'] ?? null) === 'sand_ai.audio_transcription') {
            $taskInput = is_object($taskInput) ? (array) $taskInput : $taskInput;
            $driver = is_array($taskInput) ? ($taskInput['execution_driver'] ?? null) : null;
            $driver = is_object($driver) ? (array) $driver : $driver;
            $driverCode = is_array($driver) ? ($driver['code'] ?? null) : null;
            $driverVersion = is_array($driver) ? ($driver['version'] ?? null) : null;
            $request = is_array($taskInput) ? ($taskInput['request'] ?? null) : null;
            $request = is_object($request) ? (array) $request : $request;
            $fileRef = is_array($request) ? ($request['file_ref'] ?? null) : null;
            $fileSha256 = is_array($taskInput) ? ($taskInput['file_sha256'] ?? null) : null;
            if (!in_array($driverCode, ['local_whisper_cpp', 'openai_compatible_audio', 'glm_asr_audio'], true)
                || $driverVersion !== '1.0.0'
                || !is_string($fileRef) || !is_string($fileSha256)) {
                return null;
            }
            try {
                $result = (new AudioTaskResultPresenter())->present($value, $driverCode, $driverVersion);
                foreach ($result['segments'] as $segment) {
                    if (!hash_equals($fileRef, $segment['source']['file_ref'])
                        || !hash_equals($fileSha256, $segment['source']['content_sha256'])) {
                        return null;
                    }
                }
                return $result;
            }
            catch (ApiProblem) { return null; }
        }
        if (array_key_exists('meeting_summary', $value)) {
            $taskInput = is_object($taskInput) ? (array) $taskInput : $taskInput;
            $meeting = is_array($taskInput) ? ($taskInput['_meeting_summary'] ?? null) : null;
            if (!is_array($meeting) || !is_int($meeting['audio_task_id'] ?? null)
                || !is_string($meeting['sha256'] ?? null)
                || !is_int($meeting['review_revision'] ?? 0)
                || ($value['audio_review_revision'] ?? 0) !== ($meeting['review_revision'] ?? 0)
                || !is_string($value['output']['content'] ?? null)) {
                return null;
            }
            try {
                $attribution = UsageAttribution::fromCurrentPersisted(
                    $taskInput['_usage_attribution'] ?? null,
                    (int) ($taskInput['_usage_attribution']['environment_id'] ?? 0),
                    'capability', 'sand_ai.audio.transcription',
                );
                $durable = ($taskInput['_meeting_durable'] ?? false) === true;
                $source = $durable
                    ? (new MeetingSummarySource())->current($attribution->environmentId, $meeting['audio_task_id'], $attribution,
                        $meeting['sha256'], $meeting['review_revision'] ?? 0,
                        AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal'] ?? null),
                        is_array($meeting['aggregation_source'] ?? null) ? $meeting['aggregation_source'] : null)
                    : (new MeetingSummarySource())->load(
                        $attribution->environmentId, $meeting['audio_task_id'], $attribution, $meeting['sha256'],
                        $meeting['review_revision'] ?? 0,
                        false, $taskInput['_meeting_plan'] ?? null, $taskInput['_meeting_capacity'] ?? null);
                if ($source['file_id'] !== ($meeting['file_id'] ?? null)
                    || $source['data_class'] !== ($meeting['data_class'] ?? null)
                    || $source['data_egress_policy'] !== ($meeting['data_egress_policy'] ?? null)
                    || $source['data_egress_policy'] !== 'approved_cloud') {
                    return null;
                }
                if ($durable) {
                    // Published reads require the current caller's file authorization.
                    // Queued execution authorization remains in the worker path.
                    if ($authorizeMeetingResult === null) return null;
                    $authorizeMeetingResult($source['task'], $source);
                }
                if (($value['meeting_summary']['schema']['version'] ?? null) === '1.0.0'
                    && MeetingSummaryNode::where('task_id', (int) $task->id)->count() === 0) {
                    $legacy = (new MeetingSummaryEvidence())->present(
                        $value['output']['content'], $source['prepared']['sources'],
                    );
                    if ($legacy != $value['meeting_summary']) return null;
                    $presented = $legacy;
                } else {
                    $presented = (new MeetingSummaryResultGuard())->present($task, $value, $source['prepared'] ?? []);
                }
                return [
                    'audio_task_id' => $meeting['audio_task_id'],
                    'audio_review_revision' => $meeting['review_revision'] ?? 0,
                    'meeting_summary' => $presented,
                    'meeting_summary_error' => null,
                    'unrecognized_audio_ranges' => $durable ? $this->durableUnrecognizedRanges($task)
                        : self::unrecognizedAudioRanges($source['transcript']['segments']),
                    'invocation' => is_array($value['invocation'] ?? null)
                        ? array_intersect_key($value['invocation'], array_flip(['request_id', 'state', 'latency_ms', 'error_code']))
                        : null,
                    'review_required' => true,
                ];
            } catch (\Throwable) {
                return null;
            }
        }
        $result = [];
        if (is_array($value['output'] ?? null) && is_string($value['output']['content'] ?? null)) {
            $result['output'] = ['content' => (string) $task->task_type === 'agent_run'
                ? \app\Application\Knowledge\KnowledgeConversationContextMask::text($value['output']['content'])
                : $value['output']['content']];
        }
        if (is_array($value['invocation'] ?? null)) {
            if ((string) $task->task_type === 'agent_run') {
                $requestId = $value['invocation']['request_id'] ?? null;
                if (!is_string($requestId) || $requestId === '') return null;
                // A sealed answer predates invocation finalization. Project its
                // current ledger state only through the same task/step and owner.
                $calls = Db::connect('pgsql')->query(
                    "SELECT i.request_id,i.state,i.latency_ms,i.error_code
                     FROM sand_ai_invocation i JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
                     JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=d.parent_task_run_id
                     JOIN sand_ai_model_deployment md ON md.id=i.deployment_id AND md.provider_id=d.provider_id AND md.model_id=i.model_id
                     WHERE i.request_id=? AND i.environment_id=? AND i.organization_id=? AND i.application_id=?
                       AND i.workload_client_id=? AND i.status=1 AND i.delete_time IS NULL
                       AND d.parent_task_run_id=? AND d.execution_scope='agent' AND d.workflow_node_code=s.step_code
                       AND i.model_id=d.model_id
                       AND i.deployment_id=d.deployment_id",
                    [$requestId, (int) $task->environment_id, (int) $task->organization_id,
                        (int) $task->application_id, (int) $task->workload_client_id, (int) $task->id],
                );
                if (count($calls) !== 1) return null;
                $result['invocation'] = $calls[0];
                $result['invocation']['latency_ms'] = $calls[0]['latency_ms'] === null ? null : (int) $calls[0]['latency_ms'];
            } else {
                $result['invocation'] = array_intersect_key($value['invocation'], array_flip(['request_id', 'state', 'latency_ms', 'error_code']));
            }
        }
        if (is_array($value['source_references'] ?? null)) {
            $result['source_references'] = array_values(array_filter(array_map(static function (mixed $reference): ?array {
                if (!is_array($reference)) {
                    return null;
                }
                return array_intersect_key($reference, array_flip(['source_block_id', 'file_id', 'locator_type', 'locator']));
            }, $value['source_references'])));
        }
        if (is_array($value['tool_calls'] ?? null)) {
            $result['tool_calls'] = array_values(array_filter(array_map(static function (mixed $toolCall): ?array {
                if (!is_array($toolCall)) {
                    return null;
                }
                return array_intersect_key($toolCall, array_flip(['tool', 'source_block_ids']));
            }, $value['tool_calls'])));
        }
        if (is_bool($value['review_required'] ?? null)) {
            $result['review_required'] = $value['review_required'];
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $segments @return list<array{start_ms:int,end_ms:int}> */
    private static function unrecognizedAudioRanges(array $segments): array
    {
        $ranges = [];
        foreach ($segments as $segment) {
            if (trim((string) ($segment['text'] ?? '')) !== ''
                || !in_array('no_recognized_speech', $segment['warnings'] ?? [], true)) {
                continue;
            }
            $start = (int) $segment['start_ms'];
            $end = (int) $segment['end_ms'];
            $last = count($ranges) - 1;
            if ($last >= 0 && $start <= $ranges[$last]['end_ms']) {
                $ranges[$last]['end_ms'] = max($ranges[$last]['end_ms'], $end);
                continue;
            }
            $ranges[] = ['start_ms' => $start, 'end_ms' => $end];
        }
        return $ranges;
    }

    /** HTTP projection reads only silent-range metadata, never old raw text.
     * @return list<array{start_ms:int,end_ms:int}>
     */
    private function durableUnrecognizedRanges(TaskRun $task): array
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT entry #>> '{private_segment,start_ms}' AS start_ms,entry #>> '{private_segment,end_ms}' AS end_ms
             FROM sand_ai_meeting_plan_page p JOIN sand_ai_meeting_plan_checkpoint c ON c.task_id=p.task_id
             CROSS JOIN LATERAL jsonb_array_elements(p.private_payload) WITH ORDINALITY selected(entry,position)
             WHERE p.task_id=? AND p.page_kind='source' AND c.phase='complete' AND c.content_purged_time IS NULL
                 AND entry #>> '{proof,exclusion}'='no_usable_text'
                 AND (entry #> '{private_segment,warnings}') @> '[\"no_recognized_speech\"]'::jsonb
             ORDER BY p.sequence_no,position LIMIT 10001", [(int) $task->id]);
        if (count($rows) > 10000) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议未识别范围已变化');
        $ranges = [];
        foreach ($rows as $row) {
            $start = (int) $row['start_ms']; $end = (int) $row['end_ms']; $last = count($ranges) - 1;
            if ($start < 0 || $end <= $start) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议未识别范围已变化');
            if ($last >= 0 && $start <= $ranges[$last]['end_ms']) {
                $ranges[$last]['end_ms'] = max($ranges[$last]['end_ms'], $end);
            } else {
                $ranges[] = ['start_ms' => $start, 'end_ms' => $end];
            }
        }
        return $ranges;
    }

    public function heartbeat(TaskExecutionLease $lease): void
    {
        $timezone = TaskLeasePolicy::runningTimezone();
        // Lock by identity first. The wall-clock expiry test must run after the
        // row lock returns; a waiting UPDATE predicate can otherwise be stale.
        $updated = Db::connect('pgsql')->execute(
            'WITH locked AS MATERIALIZED (SELECT id,lease_expire_time FROM sand_ai_task_run '
            . 'WHERE id=:task AND state=:state AND lease_owner=:owner AND lease_token=:token '
            . 'AND lease_fence=:fence AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL FOR UPDATE) '
            . 'UPDATE sand_ai_task_run task SET heartbeat_time=(clock_timestamp() AT TIME ZONE :heartbeat_timezone), '
            . 'lease_expire_time=(clock_timestamp() AT TIME ZONE :write_timezone)+make_interval(secs => :lease_seconds),version=task.version+1 '
            . 'FROM locked WHERE task.id=locked.id AND locked.lease_expire_time>(clock_timestamp() AT TIME ZONE :check_timezone)',
            ['task' => $lease->taskId, 'state' => TaskState::RUNNING, 'owner' => $lease->owner,
                'token' => $lease->token, 'fence' => $lease->fence, 'lease_seconds' => TaskLeasePolicy::seconds(),
                'heartbeat_timezone' => $timezone, 'write_timezone' => $timezone,
                'check_timezone' => $timezone],
        );
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task lease was lost');
        }
    }

    /** Parent-authorized safe projection; no vendor response, schema, binding or URL. */
    private function agentReadonlyToolRunSummaries(TaskRun $task): array
    {
        $rows = [];
        foreach (\app\Infrastructure\Persistence\Model\ToolRun::where('parent_task_run_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)->order('id', 'asc')->select() as $run) {
            $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
            $bindingStep = $step === null ? null : \app\Application\Agent\AgentReadonlyToolContinuationNode::originalStep($task, $step);
            $index = $bindingStep === null ? false : array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
            $binding = $index === false ? null : ($task->input['_agent_readonly_tools'][$index] ?? null);
            if (!is_array($binding) || (int) $run->tool_version_id !== ($binding['tool_version_id'] ?? null)) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent tool projection parent is unavailable');
            }
            $meter = $run->metering;
            $safeMeter = null;
            if (is_array($meter) && ($meter['unit'] ?? null) === 'external_request' && is_int($meter['quantity'] ?? null)
                && $meter['quantity'] >= 0 && ($meter['pricing_state'] ?? null) === 'unpriced') {
                $safeMeter = ['unit' => 'external_request', 'quantity' => $meter['quantity'], 'pricing_state' => 'unpriced',
                    'metered_amount' => null, 'reconciled_amount' => null, 'currency' => null];
            }
            $rows[] = ['run_id' => (int) $run->id, 'code' => $binding['selection']['code'],
                'version' => $binding['selection']['version'], 'state' => (string) $run->state,
                'review_required' => (bool) $run->review_required, 'metering' => $safeMeter];
        }
        return $rows;
    }

    /** @return list<array{request_id:string,state:string,latency_ms:?int,error_code:?string}> */
    private function agentInvocationSummaries(TaskRun $task): array
    {
        $calls = $this->agentModelInvocationRows($task);
        return array_map(static fn (array $call): array => [
            'request_id' => $call['request_id'], 'state' => $call['state'],
            'latency_ms' => $call['latency_ms'] === null ? null : (int) $call['latency_ms'], 'error_code' => $call['error_code'],
        ], $calls);
    }

    /** Every actual attempt remains visible; logical node limits do not discard failed calls or fees. */
    private function agentModelInvocationRows(TaskRun $task): array
    {
        $calls = Db::connect('pgsql')->query(
            "SELECT i.request_id,i.state,i.latency_ms,i.error_code,d.parent_task_run_id,d.parent_task_step_id,
                d.workflow_node_code,d.attempt_no,d.logical_request_id,d.logical_execution_id,d.execution_snapshot_sha256
             FROM sand_ai_invocation i JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
             JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=d.parent_task_run_id
             JOIN sand_ai_model_deployment md ON md.id=i.deployment_id AND md.provider_id=d.provider_id AND md.model_id=i.model_id
             WHERE i.environment_id=? AND i.organization_id=? AND i.application_id=? AND i.workload_client_id=?
               AND i.status=1 AND i.delete_time IS NULL AND d.parent_task_run_id=?
               AND d.execution_scope='agent' AND d.capability_code='chat'
               AND d.workflow_node_code=s.step_code
               AND s.step_code IN ('agent_round_1','agent_round_2','agent_round_3','agent_round_4','agent_summary_1')
               AND i.model_id=d.model_id AND i.deployment_id=d.deployment_id ORDER BY s.sequence_no,d.attempt_no,i.id",
            [(int) $task->environment_id, (int) $task->organization_id, (int) $task->application_id,
                (int) $task->workload_client_id, (int) $task->id],
        );
        $maximumRound = !empty($task->input['_agent_readonly_tools']) ? 4 : 3;
        if ($maximumRound === 4 && !\app\Application\Agent\AgentReadonlyToolProtocol::valid($task->input['_agent_readonly_protocol'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly round limit is unavailable');
        }
        $seen = [];
        foreach ($calls as $call) {
            $node = $call['workflow_node_code'];
            if ($node !== 'agent_summary_1' && !in_array($node, array_map(static fn (int $round): string => 'agent_round_' . $round, range(1, $maximumRound)), true)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent logical model coverage is invalid');
            }
            \app\Application\Agent\AgentGateway::assertModelDispatchIdentity($task, $call);
            $identity = $node . ':' . (int) $call['attempt_no'];
            if (isset($seen[$identity])) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent model attempt is duplicated');
            $seen[$identity] = true;
        }
        return $calls;
    }

    /** @return list<array{request_id:string,state:string,latency_ms:?int,error_code:?string}> */
    private function agentSummaryInvocations(TaskRun $task): array
    {
        return array_values(array_map(static fn (array $call): array => [
            'request_id' => $call['request_id'], 'state' => $call['state'],
            'latency_ms' => $call['latency_ms'] === null ? null : (int) $call['latency_ms'], 'error_code' => $call['error_code'],
        ], array_filter($this->agentModelInvocationRows($task), static fn (array $call): bool => $call['workflow_node_code'] === 'agent_summary_1')));
    }

    /** Safe coverage metadata and actual child invocation; never exposes the summary text. */
    private function agentSummaryProjection(TaskRun $task): array
    {
        $calls = $this->agentSummaryInvocations($task);
        $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'agent_summary_1')->find();
        if ($step === null) return ['summary_invocation' => null, 'summary_coverage' => null];
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        $coverage = is_array($context) ? ($context['coverage'] ?? null) : null;
        if (!is_array($coverage) || ($coverage['version'] ?? null) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary coverage is unavailable');
        }
        $safe = array_intersect_key($coverage, array_flip(['version', 'context_turn_limit', 'history_task_ids',
            'covered_task_ids', 'partially_covered_task_ids', 'retained_task_ids', 'excluded_task_ids',
            'omitted_prior_turn_count', 'closed_after_task_id']));
        foreach (['history_task_ids', 'covered_task_ids', 'partially_covered_task_ids', 'retained_task_ids', 'excluded_task_ids'] as $key) {
            if (!is_array($safe[$key] ?? null) || !array_is_list($safe[$key])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary task coverage is invalid');
            }
            foreach ($safe[$key] as $id) if (!is_int($id) || $id < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary task identity is invalid');
            }
        }
        $safe['state'] = (string) $step->state;
        return ['summary_invocation' => $calls === [] ? null : $calls[array_key_last($calls)], 'summary_coverage' => $safe];
    }

    private function neuronAgent(TaskRun $task): bool
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        return (string) $task->task_type === 'agent_run' && is_array($input)
            && ($input['_agent_neuron'] ?? false) === true;
    }

    private function claimNext(string $workerId): ?TaskRun
    {
        TaskWorkerIdentity::assertValid($workerId);
        // Single authoritative wall clock: the trusted host timezone
        // (app.default_timezone) backs every sand_ai_task_run lifecycle column,
        // matching create_time/update_time and the PostgreSQL server TimeZone.
        $zone = TaskLeasePolicy::runningTimezone();
        $businessNow = date('Y-m-d H:i:s');
        $leaseNow = date('Y-m-d H:i:s');
        $durableEnabled = \app\Application\Audio\MeetingExecutionRuntime::available();
        $durableScope = config('sand_ai_meeting_summary.durable_worker.enabled', false) === true
            ? 'TRUE' : "input->'_meeting_capacity'->>'version'='meeting_capacity@2'";
        $continuationAttempts = $durableEnabled ? '(' . $this->durableContinuationClaimSql() . ' AND (' . $durableScope . '))' : 'FALSE';
        $claimStates = $durableEnabled
            ? "(state IN ('queued','retrying') OR (state='recovery_pending' AND task_type='ai_completion'
                AND input->>'_meeting_durable'='true' AND ({$durableScope}) AND local_recovery_count<local_recovery_max
                AND (lease_expire_time IS NULL OR lease_expire_time <= (clock_timestamp() AT TIME ZONE '{$zone}'))))"
            : "state IN ('queued','retrying')";
        $channelAuthorizationClaims = \app\Application\Channel\ChannelReceiptRecoveryGateway::authorizationClaimSql();
        $agentRecovery = "state='recovery_pending' AND task_type='agent_run' AND input->>'_agent_neuron'='true'
            AND (NOT (pg_catalog.jsonb_exists(input, '_channel')) OR input->'_channel_receipt_recovery'->>'version'='channel_receipt_recovery@1')
            AND ((NOT (pg_catalog.jsonb_exists(input, '_channel')) AND local_recovery_count<local_recovery_max)
                OR (pg_catalog.jsonb_exists(input, '_channel') AND ({$channelAuthorizationClaims})))
            AND (lease_expire_time IS NULL OR lease_expire_time <= (clock_timestamp() AT TIME ZONE '{$zone}'))
            AND EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch agent_dispatch
                WHERE agent_dispatch.parent_task_run_id=sand_ai_task_run.id
                  AND agent_dispatch.execution_scope='agent' AND agent_dispatch.capability_code='chat'
                  AND (agent_dispatch.workflow_node_code IN ('agent_round_1','agent_round_2','agent_round_3','agent_summary_1')
                    OR (agent_dispatch.workflow_node_code='agent_round_4'
                      AND jsonb_array_length(COALESCE(sand_ai_task_run.input->'_agent_readonly_tools','[]'::jsonb)) BETWEEN 1 AND 2
                      AND sand_ai_task_run.input->'_agent_readonly_protocol' = '{\"version\":\"agent_readonly_tools@1\",\"max_tool_executions\":3,\"max_retrieval_executions\":1,\"max_readonly_executions_per_tool\":1,\"max_model_rounds\":4,\"max_output_tokens_per_round\":512,\"parallel_tool_calls\":false,\"implicit_retries\":0}'::jsonb))
                  AND EXISTS (SELECT 1 FROM sand_ai_invocation_outcome_receipt agent_receipt
                    WHERE agent_receipt.invocation_id=agent_dispatch.invocation_id
                      AND agent_receipt.environment_id=sand_ai_task_run.environment_id
                      AND agent_receipt.capability_code='agent_tool' AND agent_receipt.state='available'
                      AND agent_receipt.expire_time>(clock_timestamp() AT TIME ZONE 'UTC')))";
        $audioRecovery = TaskAudioReceiptContinuation::claimSql();
        $correctionClaims = $durableEnabled ? \app\Application\Audio\MeetingOutputCorrection::claimSql() : 'FALSE';
        $authorizationClaims = $durableEnabled
            ? '(' . TaskAuthorizationContinuation::meetingClaimSql() . ' AND (' . $durableScope . '))' : 'FALSE';
        $claimStates = "({$claimStates} OR ({$agentRecovery}) OR ({$audioRecovery}) OR ({$correctionClaims}) OR ({$authorizationClaims}))";
        $claimAttempts = $durableEnabled
            ? "(attempt_count<max_attempts OR (state='recovery_pending' AND input->>'_meeting_durable'='true' AND ({$durableScope})
                AND local_recovery_count<local_recovery_max) OR {$continuationAttempts})" : 'attempt_count<max_attempts';
        $claimAttempts = "({$claimAttempts} OR ({$agentRecovery}) OR ({$audioRecovery}) OR ({$correctionClaims}) OR ({$authorizationClaims}))";
        $claimAttempts = "({$claimAttempts} AND (
            NOT EXISTS (SELECT 1 FROM sand_ai_task_step authorization_parent
                WHERE authorization_parent.task_id=sand_ai_task_run.id AND authorization_parent.sequence_no=1
                    AND pg_catalog.jsonb_exists(authorization_parent.context->'authorization_continuation', 'meeting_task_attempt')
                    AND authorization_parent.context->'authorization_continuation'->>'meeting_retired' IS DISTINCT FROM 'true')
            OR ({$authorizationClaims})))";
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $claimedTask = Db::connect('pgsql')->transaction(function () use ($businessNow, $leaseNow, $workerId, $claimStates, $claimAttempts, $continuationAttempts, $correctionClaims, $authorizationClaims, $channelAuthorizationClaims): ?TaskRun {
                $candidate = TaskRun::whereRaw($claimStates)->whereRaw(TaskAuthorizationContinuation::claimSql())
                    ->whereRaw($channelAuthorizationClaims)
                    ->where('available_at', '<=', $businessNow)->whereRaw($claimAttempts)->where('status', 1)
                    ->whereNull('delete_time')->whereNull('content_purged_time')->order('available_at')->order('id')->lock(true)->find();
                if ($candidate === null) {
                    return null;
                }
                $authorizationClaim = $this->durableMeeting($candidate)
                    && TaskRun::where('id', (int)$candidate->id)->whereRaw($authorizationClaims)->find() !== null;
                $authorizationContext = null;
                if ($authorizationClaim) {
                    try {
                        $authorizationContext = TaskAuthorizationContinuation::claimMeeting($candidate);
                    } catch (ApiProblem $problem) {
                        if (!TaskAuthorizationContinuation::authorizationFailure($problem->errorCode)) throw $problem;
                        $this->pauseMeetingBeforeClaim($candidate, $problem->errorCode);
                        return null;
                    }
                }
                $correctionClaim = !$authorizationClaim && $this->durableMeeting($candidate)
                    && TaskRun::where('id', (int)$candidate->id)->whereRaw($correctionClaims)->find() !== null;
                $correctionPlan = $correctionClaim ? \app\Application\Audio\MeetingOutputCorrection::claimContext($candidate) : null;
                $nextAttempt = $authorizationClaim || ($correctionPlan['resume'] ?? false)
                    ? (int)$candidate->attempt_count : (int)$candidate->attempt_count + 1;
                $recoveryClaim = !$authorizationClaim && !$correctionClaim && (string) $candidate->state === TaskState::RECOVERY_PENDING
                    && ($this->durableMeeting($candidate) || $this->neuronAgent($candidate) || (new TaskAudioReceiptContinuation())->ready($candidate));
                $channelClaim = null;
                if ($recoveryClaim && \app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($candidate)) {
                    $channelClaim = \app\Application\Channel\ChannelReceiptRecoveryGateway::claimAuthorization($candidate);
                    $nextAttempt = (int) $candidate->attempt_count;
                }
                // Recheck the persisted continuation proof under the same task row
                // lock. It grants another successful stage, not a new failure budget.
                $continuationClaim = $this->durableMeeting($candidate)
                    && TaskRun::where('id', (int) $candidate->id)->whereRaw($continuationAttempts)->find() !== null;
                if (!$authorizationClaim && !$correctionClaim && !$recoveryClaim && !$continuationClaim
                    && !TaskAttemptPolicy::canClaim((int) $candidate->attempt_count, (int) $candidate->max_attempts)) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task attempt limit changed before it could be claimed');
                }
                $leaseSeconds = TaskLeasePolicy::seconds();
                $token = bin2hex(random_bytes(32));
                $claimValues = [
                    'state' => TaskState::RUNNING,
                    'attempt_count' => $nextAttempt,
                    'locked_at' => $leaseNow,
                    'locked_by' => $workerId,
                    'lease_owner' => $workerId,
                    'lease_token' => $token,
                    'lease_fence' => \think\facade\Db::raw('lease_fence + 1'),
                    'lease_expire_time' => date('Y-m-d H:i:s', time() + $leaseSeconds),
                    'heartbeat_time' => $leaseNow,
                    'version' => \think\facade\Db::raw('version + 1'),
                    'started_at' => $candidate->started_at ?: $businessNow,
                    'error_code' => null,
                    'error_summary' => null,
                ];
                if ($recoveryClaim && !($channelClaim['reuse_claim'] ?? false)) {
                    $claimValues['local_recovery_count'] = Db::raw('local_recovery_count + 1');
                }
                $claimed = TaskRun::where('id', (int) $candidate->id)->where('state', (string) $candidate->state)
                    ->whereRaw($claimAttempts)->update($claimValues);
                if ($claimed !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task changed before it could be claimed');
                }
                $parentValues = [
                    'state'=>TaskState::RUNNING, 'attempt_count'=>$nextAttempt, 'started_at'=>$businessNow,
                    'error_code'=>null, 'error_summary'=>null,
                ];
                if ($correctionPlan !== null) $parentValues['context'] = json_encode($correctionPlan['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if ($authorizationContext !== null) $parentValues['context'] = json_encode($authorizationContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if ($channelClaim !== null) $parentValues['context'] = json_encode($channelClaim['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $stepUpdated = TaskStep::where('task_id', (int) $candidate->id)->where('sequence_no', 1)
                    ->whereIn('state', ($authorizationClaim || $correctionClaim) ? [TaskState::QUEUED, TaskState::RETRYING, TaskState::FAILED]
                        : ($recoveryClaim ? [TaskState::FAILED, TaskState::RETRYING] : TaskState::claimable()))->update($parentValues);
                if ($stepUpdated !== 1) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task step changed before it could be claimed');
                }
                $this->updateWorkflowFiles($candidate, TaskState::RUNNING, null, $nextAttempt);
                return TaskRun::where('id', (int) $candidate->id)->find();
            });
            if ($claimedTask !== null) {
                return $claimedTask;
            }
            return null;
        }

        return null;
    }

    /** Fresh queued authorization expired before lease acquisition; no worker or provider started. */
    private function pauseMeetingBeforeClaim(TaskRun $task, string $code): void
    {
        $parent = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)
            ->where('step_code', 'invoke_model')->lock(true)->find();
        if ($parent === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting continuation parent changed');
        $context = TaskAuthorizationContinuation::context($parent);
        $context['authorization_continuation']['required'] = true;
        $context['authorization_continuation']['meeting_claim_pending'] = false;
        $changed = TaskRun::where('id', (int)$task->id)->where('version', (int)$task->version)
            ->where('state', (string)$task->state)->update([
                'state'=>TaskState::RECOVERY_PENDING, 'error_code'=>$code,
                'error_summary'=>'Current authorization is required before the original meeting can continue',
                'version'=>Db::raw('version + 1'),
            ]);
        $stepChanged = TaskStep::where('id', (int)$parent->id)->where('state', (string)$parent->state)->update([
            'state'=>TaskState::FAILED, 'context'=>json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'error_code'=>$code, 'error_summary'=>'Current authorization required', 'completed_at'=>date('Y-m-d H:i:s'),
        ]);
        TaskLeaseTerminalGuard::assertUpdated($changed, $stepChanged, 'meeting authorization before claim');
        $this->audit((int)$task->environment_id, 'task.authorization_paused', (int)$task->id,
            'Current authorization ended before meeting lease acquisition', [
                'error_code'=>$code, 'task_type'=>(string)$task->task_type,
                'provider_reissued'=>false, 'lease_acquired'=>false,
            ]);
    }

    private function recoverStaleAuthorizations(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 300);
        $pendingIds = TaskRun::whereIn('task_type', ['structured_workflow', 'ai_completion', 'agent_run'])
            ->where('state', TaskState::AUTHORIZATION_PENDING)
            ->where('available_at', '<=', $cutoff)->order('id')->limit(20)->column('id');
        foreach ($pendingIds as $taskId) {
            Db::connect('pgsql')->transaction(function () use ($taskId, $cutoff): void {
                $task = TaskRun::where('id', (int) $taskId)
                    ->whereIn('task_type', ['structured_workflow', 'ai_completion', 'agent_run'])
                    ->where('state', TaskState::AUTHORIZATION_PENDING)
                    ->where('available_at', '<=', $cutoff)->lock(true)->find();
                if ($task === null) return;
                $now = date('Y-m-d H:i:s');
                $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::AUTHORIZATION_PENDING)->update([
                    'state' => TaskState::FAILED,
                    'completed_at' => $now,
                    'error_code' => 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
                    'error_summary' => 'Task authorization was not completed before the context expired',
                ]);
                $stepUpdated = TaskStep::where('task_id', (int) $task->id)
                    ->where('state', TaskState::AUTHORIZATION_PENDING)->update([
                        'state' => TaskState::FAILED,
                        'completed_at' => $now,
                        'error_code' => 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
                        'error_summary' => 'Task authorization was not completed before the context expired',
                    ]);
                TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'task authorization timeout');
                $this->updateWorkflowFiles($task, TaskState::FAILED, 'SAND_AI_IDENTITY_CONTEXT_EXPIRED');
                $this->audit((int) $task->environment_id, 'task.authorization_expired', (int) $task->id,
                    'Task authorization timed out before enqueue', ['task_type' => (string) $task->task_type]);
            });
        }
    }

    private function recoverStaleRuns(): void
    {
        $now = date('Y-m-d H:i:s');
        $staleQuery = TaskRun::where('state', TaskState::RUNNING)->where('lease_expire_time', '<=', $now);
        if (\app\Application\Audio\Chunk\AudioChunkRuntimeSchema::present()) {
            $staleQuery->whereRaw('NOT EXISTS (SELECT 1 FROM sand_ai_audio_chunk_preparation p WHERE p.task_run_id=sand_ai_task_run.id)');
        }
        $staleTaskIds = $staleQuery->order('lease_expire_time')->order('id')->limit(20)->column('id');
        foreach ($staleTaskIds as $taskId) {
            try {
                $this->recoverOneStaleRun((int) $taskId, $now);
            } catch (Throwable $failure) {
                // Each recovery transaction has already rolled back. A broken
                // expired task must not prevent other recoveries or new claims.
                try {
                    \support\Log::warning('SandAI stale task recovery failed', [
                        'task_id' => (int) $taskId, 'exception_class' => $failure::class,
                    ]);
                } catch (Throwable) {
                    // Logging must not reintroduce a global claim failure.
                }
            }
        }
    }

    /** A success seal or completed zero-send closure excludes a durable meeting dispatch. */
    private function staleMeetingDispatchForTask(int $taskId): ?InvocationDispatch
    {
        return InvocationDispatch::alias('d')->where('d.parent_task_run_id', $taskId)
            ->whereRaw('NOT sand_ai_meeting_expired_reclaim_dispatch_sealed(d.id)')
            ->whereRaw('NOT sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(d.id)')
            ->order('d.id', 'desc')->field('d.*')->find();
    }

    private function recoverOneStaleRun(int $taskId, string $leaseNow, bool $closePrepared = true): void
    {
        $preparedProofFailure = false;
        try {
        Db::connect('pgsql')->transaction(function () use ($taskId, $leaseNow, $closePrepared, &$preparedProofFailure): void {
            $businessNow = date('Y-m-d H:i:s');
            // A probe selects the stable group anchor without acquiring a lock. The
            // terminal transaction then follows the same order as dispatch
            // finalization: all windows, reservations, invocation, ledger, task.
            $taskProbe = TaskRun::where('id', $taskId)->find();
            $durableMeetingProbe = $taskProbe !== null && $this->durableMeeting($taskProbe);
            $preparedRecovery = null;
            $acceptedCorrection = $durableMeetingProbe
                && TaskRun::where('id', $taskId)->whereRaw(\app\Application\Audio\MeetingOutputCorrection::claimSql(true))->find() !== null;
            $authorizationLease = $durableMeetingProbe && (string)$taskProbe->state === TaskState::RUNNING
                && TaskAuthorizationContinuation::ownsMeetingLease($taskProbe, TaskExecutionLease::fromTask($taskProbe));
            if ($durableMeetingProbe && !$acceptedCorrection && !$authorizationLease && $closePrepared
                && (int) $taskProbe->attempt_count >= (int) $taskProbe->max_attempts) {
                try {
                    // Lock every original prepared group, not only the dispatch
                    // selected below, before acquiring the canonical task lock.
                    $preparedRecovery = TaskPreparedDispatchCancellation::lockBeforeTask($taskProbe);
                } catch (ApiProblem $problem) {
                    $preparedProofFailure = true;
                    throw $problem;
                }
            }
            $dispatchProbe = $durableMeetingProbe
                ? $this->staleMeetingDispatchForTask($taskId)
                : $this->providerVisibleDispatchForTask($taskId);
            // Durable meeting dispatches remain untouched unless closeUnsent
            // proves zero-send. Do not lock a second group after its all-group
            // lock order, or settle any provider-visible accounting here.
            $reservationProbe = $durableMeetingProbe || $dispatchProbe === null ? null : \app\Infrastructure\Persistence\Model\BudgetReservation::where('invocation_id', (int) $dispatchProbe->invocation_id)->order('policy_id', 'asc')->order('id', 'asc')->find();
            $reservationId = $reservationProbe === null ? null : (int) $reservationProbe->id;
            if ($reservationId !== null) {
                foreach ((new \app\Application\Budget\ChatBudgetGuard())->lockReservationGroup($reservationId) as $member) {
                    if ((int) $member['reservation']->invocation_id !== (int) $dispatchProbe->invocation_id) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Budget reservation group belongs to another invocation');
                    }
                }
            }
            $invocation = $durableMeetingProbe || $dispatchProbe === null ? null : \app\Infrastructure\Persistence\Model\Invocation::where('id', (int) $dispatchProbe->invocation_id)->lock(true)->find();
            $dispatch = $durableMeetingProbe ? $dispatchProbe
                : ($dispatchProbe === null ? null : InvocationDispatch::where('id', (int) $dispatchProbe->id)->lock(true)->find());
            $task = TaskRun::where('id', $taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $taskId)->where('sequence_no', 1)->lock(true)->find();
            if ($task === null || (string) $task->state !== TaskState::RUNNING
                || $task->lease_expire_time === null || (string) $task->lease_expire_time > $leaseNow) {
                return;
            }
            if ($authorizationLease && TaskAuthorizationContinuation::ownsMeetingLease($task, TaskExecutionLease::fromTask($task))) {
                // An expired continuation cannot release a proven prepared reserve,
                // acquire another lease, or spend a hidden failure/recovery allowance.
                if ($step === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting continuation parent changed');
                $context = TaskAuthorizationContinuation::context($step);
                $context['authorization_continuation']['required'] = true;
                $context['authorization_continuation']['meeting_claim_pending'] = false;
                $changed = TaskRun::where('id', $taskId)->where('state', TaskState::RUNNING)
                    ->where('version', (int)$task->version)->where('lease_fence', (int)$task->lease_fence)
                    ->where('lease_expire_time', '<=', $leaseNow)->update([
                        'state'=>TaskState::RECOVERY_PENDING, 'available_at'=>$businessNow,
                        'locked_at'=>null, 'locked_by'=>null, 'lease_owner'=>null, 'lease_token'=>null,
                        'lease_expire_time'=>null, 'heartbeat_time'=>null, 'lease_fence'=>Db::raw('lease_fence + 1'),
                        'version'=>Db::raw('version + 1'), 'error_code'=>'SAND_AI_LOCAL_RECOVERY_PENDING',
                        'error_summary'=>'Meeting continuation stopped; current caller must explicitly continue the same progress',
                    ]);
                $parentChanged = TaskStep::where('id', (int)$step->id)->where('state', TaskState::RUNNING)->update([
                    'state'=>TaskState::FAILED, 'context'=>json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'error_code'=>'SAND_AI_LOCAL_RECOVERY_PENDING', 'completed_at'=>$businessNow,
                    'error_summary'=>'Current caller continuation required; original node identities retained',
                ]);
                TaskLeaseTerminalGuard::assertUpdated($changed, $parentChanged, 'expired meeting authorization continuation');
                return;
            }
            if ($preparedRecovery !== null && (int) $task->attempt_count >= (int) $task->max_attempts) {
                try {
                    // Reuse the frozen owner, zero-send CAS and original budget
                    // release chain; no route, provider or worker scope is rebuilt.
                    $preparedRecovery->closeUnsent($task, $businessNow);
                } catch (ApiProblem $problem) {
                    $preparedProofFailure = true;
                    throw $problem;
                }
            }
            if ($durableMeetingProbe) {
                // Unclosed prepared rows are still recovery evidence. Only
                // fully closed zero-send records can permit expired terminality.
                $dispatch = $this->staleMeetingDispatchForTask($taskId);
            }
            $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
            $meetingTask = (string) $task->task_type === 'ai_completion'
                && is_array($taskInput) && is_array($taskInput['_meeting_summary'] ?? null);
            // Structured receipts now retain accounting as well as content. A
            // crashed worker's dispatching call can only enter local recovery;
            // missing/legacy receipts remain rejected by that recovery path.
            $structuredReceipt = (string) $task->task_type === 'structured_workflow'
                && $dispatch !== null && (string) $dispatch->execution_scope === 'structured_workflow'
                && (string) $dispatch->capability_code === 'structured_extraction'
                && (string) $dispatch->state === 'dispatching';
            $meetingReceipt = $meetingTask && $dispatch !== null;
            $agentReceipt = $this->neuronAgent($task) && $dispatch !== null
                && (string) $dispatch->execution_scope === 'agent' && (string) $dispatch->capability_code === 'chat'
                && (in_array((string) $dispatch->workflow_node_code, ['agent_round_1','agent_round_2','agent_round_3','agent_summary_1'], true)
                    || ((string) $dispatch->workflow_node_code === 'agent_round_4'
                        && is_array($taskInput['_agent_readonly_tools'] ?? null) && count($taskInput['_agent_readonly_tools']) > 0
                        && \app\Application\Agent\AgentReadonlyToolProtocol::valid($taskInput['_agent_readonly_protocol'] ?? null)));
            $durableMeeting = $this->durableMeeting($task);
            $meetingContinuation = $meetingTask && $dispatch === null
                && !$durableMeeting && $this->meetingSealedContinuationPending($task);
            $localFinalize = $this->materialFinalizationPending($task);
            $agentChildPending = $this->agentChildToolDispatchState($taskId, true) !== null;
            $recovery = ($meetingReceipt || $meetingContinuation || $structuredReceipt || $agentReceipt || $agentChildPending)
                ? ['state' => TaskState::RECOVERY_PENDING, 'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                    'manual_review' => true, 'provider_retry' => false]
                : ($localFinalize
                ? ['state' => TaskState::RECOVERY_PENDING, 'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                    'manual_review' => true, 'provider_retry' => false]
                : DispatchStaleRecoveryPolicy::decide($dispatch === null ? null : (string) $dispatch->state, (int) $task->attempt_count, (int) $task->max_attempts));
            $manualReview = $recovery['manual_review'];
            $state = $recovery['state'];
            $exhausted = $state === TaskState::FAILED;
            $stepState = $state === TaskState::RECOVERY_PENDING ? TaskState::FAILED : $state;
            $values = [
                'state' => $state, 'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                'lease_expire_time' => null, 'heartbeat_time' => null, 'lease_fence' => Db::raw('lease_fence + 1'), 'version' => Db::raw('version + 1'),
                'error_code' => $recovery['error_code'],
                'error_summary' => $localFinalize ? 'Sealed material files require local finalization'
                    : ($manualReview ? 'Provider dispatch outcome requires manual review or receipt-only recovery' : 'The worker did not finish the task before its lease expired'),
            ];
            if ($exhausted) { $values['completed_at'] = $businessNow; } else {
                $values['available_at'] = $businessNow;
            }
            $ambiguousDispatch = $dispatch !== null && in_array((string) $dispatch->state, ['dispatching', 'ambiguous'], true);
            if ($dispatch !== null && (string) $dispatch->state === 'dispatching' && !$meetingReceipt && !$structuredReceipt && !$agentReceipt) {
                if ($invocation === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Dispatch invocation is unavailable for stale recovery');
                (new \app\Application\Gateway\DispatchCoordinator())->finalizeStaleAmbiguous((int) $dispatch->id, $invocation, $reservationId, (string) $dispatch->capability_code);
            }
            if ($ambiguousDispatch) {
                // The coordinator sealed ledger, reservation and invocation in
                // one terminal boundary; this worker only changes Task state.
            }
            if ($durableMeeting && $exhausted) {
                Db::connect('pgsql')->query("SELECT set_config('sand_ai.running_timezone',?,true),set_config('sand_ai.worker_owner',?,true),set_config('sand_ai.worker_token',?,true),set_config('sand_ai.worker_fence',?,true),set_config('sand_ai.meeting_terminal_mode','expired_reclaim',true)",
                    [TaskLeasePolicy::runningTimezone(), (string) $task->lease_owner, (string) $task->lease_token, (string) $task->lease_fence]);
            }
            if (TaskRun::where('id', $taskId)->where('state', TaskState::RUNNING)->where('lease_expire_time', '<=', $leaseNow)->update($values) !== 1
                || $step === null || TaskStep::where('id', (int) $step->id)->where('state', TaskState::RUNNING)->update([
                    'state' => $stepState, 'completed_at' => ($exhausted || $manualReview) ? $businessNow : null, 'error_code' => $recovery['error_code'],
                    'error_summary' => $localFinalize ? 'Sealed material files require local finalization'
                        : ($manualReview ? 'Provider dispatch outcome requires manual review or receipt-only recovery' : 'The worker did not finish the task before its lease expired'),
                ]) !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A stale task step changed before it could be reclaimed');
            }
            if ($durableMeeting && $exhausted) {
                TaskStep::where('task_id', $taskId)->where('sequence_no', '>', 1)
                    ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING])
                    ->update(['state' => TaskState::FAILED, 'completed_at' => $businessNow,
                        'error_code' => $recovery['error_code'], 'error_summary' => 'The worker lease expired before completion']);
            }
            if ($meetingTask && !$durableMeeting) {
                $nodeState = $meetingReceipt ? TaskState::FAILED
                    : (($meetingContinuation || $state === TaskState::RETRYING) ? TaskState::RETRYING : TaskState::FAILED);
                TaskStep::where('task_id', $taskId)->where('state', TaskState::RUNNING)
                    ->where('sequence_no', '>', 1)->update([
                        'state' => $nodeState,
                        'completed_at' => $meetingReceipt ? $businessNow : null,
                        'error_code' => $recovery['error_code'],
                        'error_summary' => $meetingReceipt
                            ? 'Meeting node requires receipt-only recovery'
                            : 'Meeting node lease expired before completion',
                    ]);
            }
            if ((string) $task->task_type === 'file_parse') {
                (new FileParseGateway())->recoverStaleTask($task, $state);
            }
            $this->updateWorkflowFiles($task, $state, $recovery['error_code']);
            $this->audit((int) $task->environment_id, $localFinalize ? 'task.local_finalization_pending'
                : ($manualReview ? 'task.manual_review_required' : ($exhausted ? 'task.failed' : 'task.retrying')),
                $taskId, 'Reclaimed stale SandAI task', ['task_type' => (string) $task->task_type, 'dispatch_state' => $dispatch?->state]);
        });
        } catch (ApiProblem $problem) {
            if (!$preparedProofFailure || !$closePrepared) throw $problem;
            // The full closure transaction rolled back, including any earlier
            // groups. Missing proof must enter recovery_pending without release.
            $this->recoverOneStaleRun($taskId, $leaseNow, false);
        }
    }

    /** A stale parent with sealed child work needs local continuation even after exhausting attempts. */
    private function meetingSealedContinuationPending(TaskRun $task): bool
    {
        $sealed = 0;
        foreach (MeetingSummaryNode::where('task_id', (int) $task->id)->select() as $node) {
            if ($node->result_snapshot === null) continue;
            $step = TaskStep::where('id', (int) $node->task_step_id)->find();
            if ($step === null || (string) $step->state !== TaskState::SUCCEEDED
                || $node->last_invocation_id === null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Sealed meeting node changed');
            }
            ++$sealed;
        }
        return $sealed > 0;
    }

    /** @param array<string, mixed> $result */
    private function succeed(TaskRun $task, array $result, TaskExecutionLease $lease): void
    {
        $now = date('Y-m-d H:i:s');
        Db::connect('pgsql')->transaction(function () use ($task, $result, $lease, $now): void {
        if ($this->durableMeeting($task)) {
            (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
        }
        $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->update([
            'state' => TaskState::SUCCEEDED,
            'result' => $result,
            'locked_at' => null,
            'locked_by' => null,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expire_time' => null,
            'heartbeat_time' => null,
            'version' => \think\facade\Db::raw('version + 1'),
            'completed_at' => $now,
        ]);
        $stepUpdated = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
            'state' => TaskState::SUCCEEDED,
            'completed_at' => $now,
        ]);
        TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'completion');
        if ((string) $task->task_type === 'audio_transcription') {
            (new \app\Application\Audio\AudioTranscriptSealLedger())->sealBase((int) $task->id);
        }
        $this->audit((int) $task->environment_id, 'task.succeeded', (int) $task->id, 'SandAI task succeeded', ['task_type' => (string) $task->task_type]);
        $this->reviseWorkflowTask($task, TaskState::SUCCEEDED);
        });
    }

    /**
     * The file, source blocks and task outcome commit together. The task row
     * is locked before the file lock so cancellation cannot overtake parsing.
     *
     * @param array<string, mixed> $result
     * @param \Closure():void $persistFile
     */
    public function completeFileParse(TaskRun $task, array $result, TaskExecutionLease $lease, \Closure $persistFile): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $result, $lease, $persistFile): void {
            $this->assertRunningLease($lease);
            $persistFile();
            $this->succeed($task, $result, $lease);
        });
    }

    /** @param \Closure():void $persistFile */
    public function failFileParse(TaskRun $task, string $errorCode, string $message, bool $retryable, TaskExecutionLease $lease, \Closure $persistFile): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $errorCode, $message, $retryable, $lease, $persistFile): void {
            $this->assertRunningLease($lease);
            $persistFile();
            $this->fail($task, $errorCode, $message, $retryable, $lease);
        });
    }

    public function assertRunningLease(TaskExecutionLease $lease): void
    {
        $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
        if ($task === null || (string) $task->state !== TaskState::RUNNING
            || (string) $task->lease_owner !== $lease->owner
            || !hash_equals($lease->token, (string) $task->lease_token)
            || (int) $task->lease_fence !== $lease->fence) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'File parse task lease changed');
        }
    }

    private function fail(TaskRun $task, string $errorCode, string $message, bool $retryable, TaskExecutionLease $lease, ?string $failureKind = null): void
    {
        $now = date('Y-m-d H:i:s');
        $attempts = (int) $task->attempt_count;
        $manualReview = $errorCode === 'SAND_AI_DISPATCH_AMBIGUOUS';
        $willRetry = !$manualReview && $retryable && $attempts < max(1, (int) $task->max_attempts);
        $state = $willRetry ? TaskState::RETRYING : TaskState::FAILED;
        $values = [
            'state' => $state,
            'error_code' => $errorCode,
            'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
            'locked_at' => null,
            'locked_by' => null,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expire_time' => null,
            'heartbeat_time' => null,
            'version' => \think\facade\Db::raw('version + 1'),
        ];
        if ($willRetry) {
            $values['available_at'] = date('Y-m-d H:i:s', time() + TaskState::retryDelaySeconds($attempts));
        } else {
            $values['completed_at'] = $now;
        }
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $values, $state, $errorCode, $message, $willRetry, $manualReview, $now, $attempts, $failureKind): void {
        $locked = $this->durableMeeting($task) ? TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
            ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
            ->lock(true)->find() : null;
        if ($locked !== null && TaskAuthorizationContinuation::ownsMeetingLease($locked, $lease)) {
            // Failure consumes this stage permission. Explicit continuation may resume
            // the same unfinished node, but cannot replace its attempt or dispatch.
            (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
            $parent = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)
                ->where('step_code', 'invoke_model')->where('state', TaskState::RUNNING)->lock(true)->find();
            if ($parent === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting continuation parent changed');
            $parentContext = TaskAuthorizationContinuation::context($parent);
            $parentContext['authorization_continuation']['required'] = true;
            $parentContext['authorization_continuation']['meeting_claim_pending'] = false;
            $visible = $this->providerVisibleDispatchStateForTask($lease->taskId, true) !== null;
            $pausedState = $visible ? TaskState::RECOVERY_PENDING : TaskState::FAILED;
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])->update([
                    'state' => $pausedState, 'error_code' => $errorCode,
                    'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
                    'available_at' => $now, 'completed_at' => $visible ? null : $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int)$parent->id)->where('state', TaskState::RUNNING)->update([
                'state' => TaskState::FAILED, 'context' => json_encode($parentContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'error_code' => $errorCode, 'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'), 'completed_at' => $now,
            ]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'meeting continuation failure pause');
            $this->audit((int)$locked->environment_id, 'task.authorization_paused', $lease->taskId,
                'Meeting stage stopped; current caller must explicitly continue the original progress', [
                    'error_code' => $errorCode, 'task_type' => (string)$locked->task_type,
                    'attempt_count' => (int)$locked->attempt_count, 'receipt_retained' => $visible,
                    'provider_reissued' => false,
                    ...($failureKind === null ? [] : ['failure_kind' => $failureKind]),
                ]);
            return;
        }
        if ($this->providerVisibleDispatchStateForTask((int) $task->id, true) !== null
            || $this->materialFinalizationPending($task)) {
            // This is the final fail-closed fence. It also protects a future
            // post-dispatch heartbeat/finalizer addition from accidentally
            // turning a provider-visible result into an automatic retry.
            $state = TaskState::RECOVERY_PENDING;
            $willRetry = false;
            $manualReview = true;
            $values = [
                'state' => TaskState::RECOVERY_PENDING, 'available_at' => date('Y-m-d H:i:s'),
                'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                'error_summary' => 'Provider-visible dispatch requires receipt-only recovery or manual review',
                'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                'lease_expire_time' => null, 'heartbeat_time' => null, 'version' => \think\facade\Db::raw('version + 1'),
            ];
            $errorCode = 'SAND_AI_LOCAL_RECOVERY_PENDING';
            $message = 'Provider-visible dispatch requires receipt-only recovery or manual review';
        }
        if ($this->durableMeeting($task) && $state === TaskState::FAILED) {
            (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
        }
        $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->update($values);
        $stepUpdated = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
            'state' => $manualReview ? TaskState::FAILED : $state,
            'error_code' => $errorCode,
            'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
            'completed_at' => $willRetry ? null : $now,
        ]);
        TaskLeaseTerminalGuard::assertUpdated($updated, $stepUpdated, 'failure persistence');
        $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
        if (!$this->durableMeeting($task) && (string) $task->task_type === 'ai_completion'
            && is_array($taskInput) && is_array($taskInput['_meeting_summary'] ?? null)) {
            TaskStep::where('task_id', (int) $task->id)->where('sequence_no', '>', 1)
                ->where('state', TaskState::RUNNING)->update([
                    'state' => $manualReview ? TaskState::FAILED : $state,
                    'error_code' => $errorCode,
                    'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
                    'completed_at' => $willRetry ? null : $now,
                ]);
        }
        if ($this->durableMeeting($task) && $state === TaskState::FAILED) {
            TaskStep::where('task_id', (int) $task->id)->where('sequence_no', '>', 1)
                ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING])
                ->update(['state' => TaskState::FAILED, 'error_code' => $errorCode,
                    'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'), 'completed_at' => $now]);
        }
        $this->updateWorkflowFiles($task, $state, $errorCode, $attempts);
        $context = [
            'task_type' => (string) $task->task_type,
            'error_code' => $errorCode,
            'attempt_count' => $attempts,
        ];
        if ($failureKind !== null) {
            $context['failure_kind'] = $failureKind;
        }
        $this->audit((int) $task->environment_id, $manualReview ? 'task.manual_review_required' : ($willRetry ? 'task.retrying' : 'task.failed'), (int) $task->id, 'SandAI task did not complete', $context);
        });
    }

    /** One exhausted receipt's permanent shape rejection; no generation or recovery-budget reset. */
    private function finalizeExhaustedMeetingRejection(string $workerId): bool
    {
        if (!\app\Application\Audio\MeetingExecutionRuntime::available()) return false;
        TaskWorkerIdentity::assertValid($workerId);
        return Db::connect('pgsql')->transaction(function () use ($workerId): bool {
            $task = TaskRun::where('state', TaskState::RECOVERY_PENDING)->where('task_type', 'ai_completion')
                ->whereRaw("input->>'_meeting_durable'='true' AND local_recovery_count>=local_recovery_max")
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')
                ->whereNull('lease_owner')->whereNull('lease_token')->order('id')->lock(true)->find();
            if ($task === null || !\app\Application\Audio\MeetingExecutionRuntime::forTask($task)) return false;
            if (TaskRun::where('id', (int)$task->id)->whereRaw(TaskAuthorizationContinuation::claimSql())->find() === null) return false;
            if (TaskRun::where('id', (int)$task->id)->whereRaw(TaskAuthorizationContinuation::meetingClaimSql())->find() !== null) return false;
            if (TaskRun::where('id', (int)$task->id)->whereRaw(\app\Application\Audio\MeetingOutputCorrection::claimSql())->find() !== null) return false;
            $nodes = MeetingSummaryNode::field('id,task_id,task_step_id,node_code,node_kind,input_snapshot,input_sha256')->where('task_id', (int) $task->id)->whereNull('result_sha256')
                ->whereRaw('EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch d WHERE d.parent_task_run_id=sand_ai_meeting_summary_node.task_id AND d.parent_task_step_id=sand_ai_meeting_summary_node.task_step_id AND d.state=?)', ['succeeded'])
                ->limit(2)->select();
            if (count($nodes) !== 1) return false;
            $node = $nodes[0];
            $step = TaskStep::where('id', (int) $node->task_step_id)->where('task_id', (int) $task->id)
                ->where('state', TaskState::RUNNING)->lock(true)->find();
            $dispatches = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->where('parent_task_step_id', (int) $node->task_step_id)->where('state', 'succeeded')->limit(2)->select();
            if ($step === null || count($dispatches) !== 1) return false;
            $invocation = Invocation::where('id', (int) $dispatches[0]->invocation_id)->where('state', 'succeeded')->find();
            if ($invocation === null) return false;
            $persisted = is_object($task->input) ? (array) $task->input : $task->input;
            $queued = is_array($persisted) ? ($persisted['iam_authorization'] ?? null) : null;
            $expired = is_array($queued) && ($queued['version'] ?? null) === 1
                && ($queued['task_type'] ?? null) === 'ai_completion'
                && is_int($queued['expires_at'] ?? null) && $queued['expires_at'] > 0 && $queued['expires_at'] <= time();
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) return false;
            $receipts = new \app\Application\Gateway\OutcomeReceiptService(
                new \app\Application\Gateway\PostgresOutcomeReceiptStore(),
                new \app\Application\Gateway\OutcomeReceiptCipher($config), $config['ttl_seconds'] ?? 0);
            $outcome = null;
            if (!$expired) {
                try {
                    $outcome = \app\Application\Knowledge\KnowledgeProviderOutcome::decode($receipts->replay([
                        'environment_id' => (int) $task->environment_id, 'invocation_id' => (int) $invocation->id,
                        'request_fingerprint' => (string) $invocation->request_fingerprint, 'capability_code' => 'meeting_summary',
                        'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
                        'version' => \app\Application\Gateway\OutcomeReceiptCipher::RECEIPT_VERSION,
                    ]));
                } catch (ApiProblem) { return false; }
                try {
                    $nodeInput = is_object($node->input_snapshot) ? (array) $node->input_snapshot : $node->input_snapshot;
                    if (!is_array($nodeInput) || !hash_equals((string) $node->input_sha256,
                        \app\Application\Gateway\RequestPayloadFingerprint::fingerprint($nodeInput, []))) return false;
                    (new MeetingSummaryEvidence())->decodeObjectClaims($outcome->content,
                        \app\Application\Audio\MeetingSummaryOutputContract::claimLimits(
                            $persisted['_meeting_summary'] ?? [], (string) $node->node_kind,
                            $nodeInput));
                    return false; // Valid shape needs ordinary recovery, never this terminal-only branch.
                } catch (ApiProblem $problem) {
                    if ($problem->errorCode !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID') return false;
                }
            }
            $now = date('Y-m-d H:i:s');
            $token = bin2hex(random_bytes(32));
            $changed = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('version', (int) $task->version)->update([
                    'state' => TaskState::RUNNING, 'lease_owner' => $workerId, 'lease_token' => $token,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'locked_by' => $workerId, 'locked_at' => $now,
                    'heartbeat_time' => $now, 'lease_expire_time' => date('Y-m-d H:i:s', time() + TaskLeasePolicy::seconds()),
                    'version' => Db::raw('version + 1'),
                ]);
            $parentChanged = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)
                ->where('state', TaskState::FAILED)->update(['state' => TaskState::RUNNING, 'completed_at' => null]);
            if ($changed !== 1 || $parentChanged !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting terminal cleanup changed');
            $current = TaskRun::where('id', (int) $task->id)->find();
            if ($current === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting terminal cleanup lease is unavailable');
            $this->failRejectedMeetingOutput($current, TaskExecutionLease::fromTask($current),
                new \app\Application\Audio\MeetingOutputRejected((int) $node->id, (int) $step->id,
                    (int) $invocation->id, $expired ? '' : hash('sha256', $outcome->content)), $expired);
            return true;
        });
    }

    /** Permanent output rejection requires the actual successful receipt and its immutable usage. */
    private function failRejectedMeetingOutput(TaskRun $task, TaskExecutionLease $lease,
        \app\Application\Audio\MeetingOutputRejected $failure, bool $metadataOnlyExpired = false): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $failure, $metadataOnlyExpired): void {
            $current = TaskRun::where('id', $lease->taskId)->where('environment_id', (int) $task->environment_id)
                ->where('state', TaskState::RUNNING)->where('lease_owner', $lease->owner)
                ->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($current === null || !$this->durableMeeting($current)) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting output rejection lost its task lease');
            }
            (new \app\Application\Audio\PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
            $input = is_object($current->input) ? (array) $current->input : $current->input;
            if (!is_array($input) || !is_array($input['iam_authorization'] ?? null)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Meeting rejection authorization is unavailable');
            }
            $authorizationExpired = false;
            try {
                (new \app\Identity\Infrastructure\TaskRunAccessGuard())->revalidateQueued($input['iam_authorization'], $current);
            } catch (ApiProblem $problem) {
                if (!$metadataOnlyExpired || $problem->errorCode !== 'SAND_AI_IDENTITY_CONTEXT_EXPIRED') throw $problem;
                // Deny an expired authorization. This branch can only seal failure:
                // no result publication, identity replacement, or Provider retry.
                $authorizationExpired = true;
            }
            if ($metadataOnlyExpired && !$authorizationExpired) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting expired cleanup requires an actual identity denial');
            }
            $attribution = AiTaskGateway::attributionFromPersistedInput($input, (int) $current->environment_id);
            if ($authorizationExpired) {
                $sealedMeeting = $input['_meeting_summary'] ?? null;
                if (!is_array($sealedMeeting) || !is_array($sealedMeeting['transcript_seal'] ?? null)
                    || isset($sealedMeeting['aggregation_source'])) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expired meeting cleanup requires immutable source metadata');
                }
            }
            AiTaskGateway::assertCurrentSourceFacts($current, $attribution);
            $nodeQuery = MeetingSummaryNode::where('id', $failure->nodeId)->where('task_id', $lease->taskId)
                ->where('task_step_id', $failure->stepId)->whereNull('result_sha256');
            if ($authorizationExpired) {
                // Only ledger metadata after a denied identity; no transcript/private messages.
                $nodeQuery->field('id,task_id,task_step_id,node_code,input_sha256,transcript_sha256,review_revision,planner_version');
                $meeting = $input['_meeting_summary'] ?? null;
                if (!is_array($meeting) || !is_array($meeting['transcript_seal'] ?? null)
                    || isset($meeting['aggregation_source'])) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expired meeting cleanup requires an immutable transcript seal');
                }
            }
            $node = $nodeQuery->lock(true)->find();
            $step = TaskStep::where('id', $failure->stepId)->where('task_id', $lease->taskId)
                ->where('state', TaskState::RUNNING)->lock(true)->find();
            $invocation = Invocation::where('id', $failure->invocationId)
                ->where('environment_id', (int) $current->environment_id)->where('state', 'succeeded')->lock(true)->find();
            $dispatch = InvocationDispatch::where('invocation_id', $failure->invocationId)
                ->where('parent_task_run_id', $lease->taskId)->where('parent_task_step_id', $failure->stepId)
                ->where('execution_scope', 'ai_task')->where('capability_code', 'chat')->where('state', 'succeeded')->lock(true)->find();
            if ($node === null || $step === null || $invocation === null || $dispatch === null
                || (string) $node->node_code !== (string) $dispatch->workflow_node_code
                || (int) $step->attempt_count !== (int) $dispatch->attempt_no
                || (int) $invocation->model_id !== (int) $dispatch->model_id
                || (int) $invocation->deployment_id !== (int) $dispatch->deployment_id
                || (string) $invocation->request_id !== sprintf('task_%d_%s_attempt_%d',
                    $lease->taskId, (string) $node->node_code, (int) $step->attempt_count)
                || !hash_equals((string) $dispatch->logical_request_id, hash('sha256', (string) $invocation->request_id))) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting output rejection facts are unavailable');
            }
            if ($authorizationExpired) {
                // Registered 109 computed this digest on insertion and forbids input changes.
                if (preg_match('/\A[a-f0-9]{64}\z/', (string) $node->input_sha256) !== 1
                    || !hash_equals((string) $node->transcript_sha256, (string) $meeting['sha256'])
                    || (int) $node->review_revision !== (int) $meeting['review_revision']
                    || (string) $node->planner_version !== (string) $meeting['planner_version']) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting rejected metadata differs from its immutable source proof');
                }
            } else {
                $nodeInput = is_object($node->input_snapshot) ? (array) $node->input_snapshot : $node->input_snapshot;
                if (!is_array($nodeInput) || !hash_equals((string) $node->input_sha256,
                    \app\Application\Gateway\RequestPayloadFingerprint::fingerprint($nodeInput, []))) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting rejected node input differs from its frozen proof');
                }
            }
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Meeting receipt is unavailable');
            $receipts = new \app\Application\Gateway\OutcomeReceiptService(
                new \app\Application\Gateway\PostgresOutcomeReceiptStore(),
                new \app\Application\Gateway\OutcomeReceiptCipher($config), $config['ttl_seconds'] ?? 0);
            $receiptContext = [
                'environment_id' => (int) $current->environment_id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint, 'capability_code' => 'meeting_summary',
                'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
                'version' => \app\Application\Gateway\OutcomeReceiptCipher::RECEIPT_VERSION,
            ];
            $outcome = null;
            if ($authorizationExpired) {
                // Validate availability and immutable envelope binding without reading private plaintext.
                if ($receipts->probe((int) $invocation->id, $receiptContext) !== 'valid') {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting expired cleanup receipt metadata is unavailable');
                }
            } else {
                $outcome = \app\Application\Knowledge\KnowledgeProviderOutcome::decode($receipts->replay($receiptContext));
                if (!hash_equals($failure->contentSha256, hash('sha256', $outcome->content))) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting rejected content differs from its receipt');
                }
            }
            $usage = Db::connect('pgsql')->query('SELECT * FROM sand_ai_usage_event WHERE invocation_id=?', [(int) $invocation->id]);
            if (count($usage) !== 1) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting outcome usage is not sealed');
            $event = $usage[0];
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id', 'source_kind', 'service_action', 'capability_code'] as $key) {
                if (($outcome !== null && (string) ($event[$key] ?? '') !== (string) ($outcome->attribution[$key] ?? ''))
                    || (string) ($event[$key] ?? '') !== (string) (($attribution->toPersisted() + [
                        'source_kind' => $attribution->sourceKind, 'service_action' => $attribution->serviceAction])[$key] ?? '')) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting outcome attribution differs from sealed usage');
                }
            }
            if ((int) $event['provider_id'] !== (int) $dispatch->provider_id
                || (int) $event['model_id'] !== (int) $invocation->model_id
                || (int) $event['deployment_id'] !== (int) $invocation->deployment_id
                || (int) $event['input_tokens'] < 0 || (int) $event['output_tokens'] < 0
                || (int) $event['total_tokens'] !== (int) $event['input_tokens'] + (int) $event['output_tokens']) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting usage differs from its immutable dispatch');
            }
            if ($outcome !== null && ((int) $event['provider_id'] !== ($outcome->provider['id'] ?? null)
                || (int) $event['model_id'] !== ($outcome->model['id'] ?? null)
                || (int) $event['deployment_id'] !== ($outcome->deployment['id'] ?? null)
                || (int) $event['input_tokens'] !== $outcome->inputTokens
                || (int) $event['output_tokens'] !== $outcome->outputTokens
                || ($event['usage_state'] === 'measured') !== $outcome->usageMeasured)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting rejected receipt differs from sealed usage');
            }
            $code = $authorizationExpired ? 'SAND_AI_IDENTITY_CONTEXT_EXPIRED' : 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID';
            $summary = $authorizationExpired
                ? 'Queued identity authorization expired; the paid outcome is retained and no result is published'
                : ($failure->missingSourcePath !== null
                    ? 'Meeting output is missing source references at ' . $failure->missingSourcePath
                        . '; no result was published. Successful nodes and paid usage are retained. Check explicit correction availability; correction creates a new paid attempt'
                    : 'The retained model output does not satisfy the meeting contract; no result was published. Successful nodes and paid usage are retained. Check explicit correction availability before requesting a new paid attempt');
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->update([
                    'state' => TaskState::FAILED, 'error_code' => $code, 'error_summary' => $summary, 'completed_at' => $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('task_id', $lease->taskId)
                ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING])->update([
                    'state' => TaskState::FAILED, 'error_code' => $code, 'error_summary' => $summary, 'completed_at' => $now,
                ]);
            if ($updated !== 1 || $stepUpdated < 2) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting rejection changed before commit');
            $this->audit((int) $current->environment_id, 'task.failed', $lease->taskId, 'Retained meeting output rejected', [
                'error_code' => $code, 'node_id' => $failure->nodeId, 'invocation_id' => (int) $invocation->id,
                'usage_event_id' => (int) $event['id'], 'content_sha256' => $outcome === null ? null : hash('sha256', $outcome->content),
                'receipt_retained' => true, 'provider_reissued' => false, 'authorization_denied' => $authorizationExpired,
            ]);
        });
    }

    private function markRecoveryPending(TaskRun $task, TaskExecutionLease $lease): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease): void {
            $locked = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($locked === null || TaskRun::where('id', (int) $task->id)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])
                ->value('id') === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '过期或失效租约不能转入回执恢复，由正式回收路径继续处理');
            }
            $now = date('Y-m-d H:i:s');
            $authorizationLease = $this->durableMeeting($locked)
                && TaskAuthorizationContinuation::ownsMeetingLease($locked, $lease);
            $parentContext = null;
            if ($authorizationLease) {
                $parent = TaskStep::where('task_id', (int)$locked->id)->where('sequence_no', 1)
                    ->where('step_code', 'invoke_model')->lock(true)->find();
                if ($parent === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting continuation parent changed');
                $parentContext = TaskAuthorizationContinuation::context($parent);
                $parentContext['authorization_continuation']['required'] = true;
                $parentContext['authorization_continuation']['meeting_claim_pending'] = false;
            }
            $taskUpdated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])->update([
                    'state' => TaskState::RECOVERY_PENDING, 'available_at' => $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                    'error_summary' => 'Provider outcome retained for local receipt recovery', 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
                'state' => TaskState::FAILED, 'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                'error_summary' => 'Provider outcome retained for local receipt recovery', 'completed_at' => $now,
                ...($parentContext === null ? [] : ['context'=>json_encode($parentContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]),
            ]);
            TaskLeaseTerminalGuard::assertUpdated($taskUpdated, $stepUpdated, 'receipt recovery handoff');
            $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
            if (!$this->durableMeeting($task) && (string) $task->task_type === 'ai_completion'
                && is_array($taskInput) && is_array($taskInput['_meeting_summary'] ?? null)) {
                TaskStep::where('task_id', (int) $task->id)->where('sequence_no', '>', 1)
                    ->where('state', TaskState::RUNNING)->update([
                        'state' => TaskState::FAILED, 'completed_at' => $now,
                        'error_code' => 'SAND_AI_LOCAL_RECOVERY_PENDING',
                        'error_summary' => 'Meeting node requires receipt-only recovery',
                    ]);
            }
            $this->updateWorkflowFiles($task, TaskState::RECOVERY_PENDING, 'SAND_AI_LOCAL_RECOVERY_PENDING');
            $this->audit((int) $task->environment_id, 'task.receipt_recovery_pending', (int) $task->id, 'Provider outcome retained for receipt-only recovery', [
                'task_type' => (string) $task->task_type, 'provider_called' => true,
            ]);
        });
    }

    private function updateWorkflowFiles(TaskRun $task, string $state, ?string $errorCode = null, ?int $attemptCount = null): void
    {
        if ((string) $task->task_type !== 'structured_workflow') {
            return;
        }
        if ((string) $task->resource_type !== 'workflow_run' || (int) $task->resource_id < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow task resource is invalid');
        }
        $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)->lock(true)->find();
        if ($run === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow task has no associated run');
        }
        if ((string) $run->workflow_code === 'material_analysis') {
            $runInput = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
            $singleFileExecution = is_array($runInput)
                && ($runInput['material_execution_mode'] ?? null) === 'single_file_v1';
            $runFiles = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            if (count($runFiles) < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow task has no source file records');
            }
            $fileIds = [];
            foreach ($runFiles as $runFile) {
                $fileIds[] = (int) $runFile->id;
            }
            $results = [];
            foreach (WorkflowFileResult::whereIn('workflow_run_file_id', $fileIds)->select() as $result) {
                $results[(int) $result->workflow_run_file_id] = $result;
            }
            foreach ($runFiles as $runFile) {
                $result = $results[(int) $runFile->id] ?? null;
                $fileValues = WorkflowRunFileState::forParentTask(
                    [
                        'analysis_state' => (string) $runFile->analysis_state,
                        'attempt_count' => (int) $runFile->attempt_count,
                        'last_invocation_id' => $runFile->last_invocation_id === null ? null : (int) $runFile->last_invocation_id,
                        'failure_summary' => $runFile->failure_summary === null ? null : (string) $runFile->failure_summary,
                    ],
                    $result === null ? null : [
                        'invocation_id' => (int) $result->invocation_id,
                        'attempt_no' => (int) $result->attempt_no,
                    ],
                    $state, $errorCode, $attemptCount, $results !== [], $singleFileExecution,
                );
                if ($fileValues === null) {
                    continue;
                }
                if (WorkflowRunFile::where('id', (int) $runFile->id)
                    ->where('analysis_state', (string) $runFile->analysis_state)->update($fileValues) !== 1) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow file state changed before task update');
                }
            }
            $this->updateCrossFileReview($run, count($runFiles), $state, $errorCode);
            $this->reviseWorkflowTask($task, $state);
            return;
        }
        $values = WorkflowRunFileState::forTask($state, $errorCode);
        if ($attemptCount !== null) {
            $values['attempt_count'] = $attemptCount;
        }
        if (WorkflowRunFile::where('workflow_run_id', (int) $task->resource_id)->update($values) < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow task has no source file records');
        }
        $this->reviseWorkflowTask($task, $state);
    }

    private function durableMeeting(TaskRun $task): bool
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        return (string) $task->task_type === 'ai_completion' && is_array($input)
            && ($input['_meeting_durable'] ?? false) === true && is_array($input['_meeting_summary'] ?? null);
    }

    /**
     * A successful receipt recovery can yield with the real failure history at
     * or above max_attempts. Only its clean queued continuation may proceed.
     * A failed tick remains failed/recovery_pending under the original policy;
     * unknown dispatches still spend the bounded local recovery allowance.
     */
    private function durableContinuationClaimSql(): string
    {
        return "(state='queued' AND task_type='ai_completion' AND input->>'_meeting_durable'='true'
            AND attempt_count>=max_attempts AND local_recovery_count>0
            AND local_recovery_count<=local_recovery_max
            AND attempt_count::bigint < max_attempts::bigint + local_recovery_count::bigint
            AND error_code IS NULL AND error_summary IS NULL
            AND lease_owner IS NULL AND lease_token IS NULL AND lease_expire_time IS NULL
            AND EXISTS (SELECT 1 FROM sand_ai_task_step parent
                WHERE parent.task_id=sand_ai_task_run.id AND parent.sequence_no=1
                    AND parent.step_code='invoke_model' AND parent.state='queued'
                    AND parent.attempt_count=sand_ai_task_run.attempt_count
                    AND parent.status=1 AND parent.delete_time IS NULL)
            AND EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint cp
                WHERE cp.task_id=sand_ai_task_run.id AND cp.state='staging')
            AND EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node n
                JOIN sand_ai_task_step s ON s.id=n.task_step_id AND s.task_id=n.task_id
                JOIN sand_ai_invocation_dispatch d ON d.invocation_id=n.last_invocation_id
                    AND d.parent_task_run_id=n.task_id AND d.parent_task_step_id=n.task_step_id
                    AND d.workflow_node_code=n.node_code AND d.execution_scope='ai_task' AND d.state='succeeded'
                JOIN sand_ai_invocation i ON i.id=d.invocation_id AND i.state='succeeded'
                    AND i.environment_id=sand_ai_task_run.environment_id
                JOIN sand_ai_usage_event u ON u.invocation_id=i.id AND u.environment_id=i.environment_id
                WHERE n.task_id=sand_ai_task_run.id AND n.result_sha256 IS NOT NULL AND n.result_snapshot IS NOT NULL
                    AND s.sequence_no>1 AND s.state='succeeded' AND s.step_code=n.node_code
                    AND s.attempt_count>0 AND s.status=1 AND s.delete_time IS NULL
                    AND i.request_id=n.result_snapshot->>'request_id'
                    AND i.request_id=format('task_%s_%s_attempt_%s',n.task_id,n.node_code,s.attempt_count)))";
    }

    /** Successful stages release their lease without spending a failure attempt.
     * The stage's immutable page/node transaction has already committed.
     */
    private function yieldDurableMeeting(TaskRun $task, TaskExecutionLease $lease): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease): void {
            $this->heartbeat($lease);
            $locked = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            if ($locked === null || !$this->durableMeeting($locked)) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '会议阶段租约已变化');
            $parent = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)
                ->where('step_code', 'invoke_model')->where('state', TaskState::RUNNING)->lock(true)->find();
            if ($parent === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '会议父步骤已变化');
            $authorizationLease = TaskAuthorizationContinuation::ownsMeetingLease($locked, $lease);
            $context = $authorizationLease ? TaskAuthorizationContinuation::yieldMeeting($locked, $lease, $parent) : null;
            $nextAttempt = $authorizationLease ? (int)$locked->attempt_count : max(0, (int)$locked->attempt_count - 1);
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->update([
                    'state' => TaskState::QUEUED, 'attempt_count' => $nextAttempt,
                    'available_at' => date('Y-m-d H:i:s'), 'locked_at' => null, 'locked_by' => null,
                    'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null, 'heartbeat_time' => null,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'version' => Db::raw('version + 1'),
                ]);
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->update([
                'state' => TaskState::QUEUED, 'attempt_count' => $nextAttempt,
                ...($context === null ? [] : ['context'=>json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)])]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $step, 'meeting stage continuation');
        });
    }

    private function reviseWorkflowTask(TaskRun $task, string $state): void
    {
        if ((string) $task->task_type !== 'structured_workflow') return;
        if ((string) $task->resource_type !== 'workflow_run' || (int) $task->resource_id < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow task resource is invalid');
        }
        $revision = new WorkflowStateRevision();
        if (TaskState::terminal($state)) {
            $revision->terminalChanged((int) $task->resource_id, $state);
        } else {
            $revision->changed((int) $task->resource_id);
        }
    }

    private function updateCrossFileReview(WorkflowRun $run, int $fileCount, string $taskState, ?string $errorCode): void
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        if ($fileCount < 2 || !is_array($input)
            || ($input['material_execution_mode'] ?? null) !== 'single_file_v1') {
            return;
        }
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
        if ($review === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state is missing');
        }
        $current = (string) $review->state;
        if ($current === 'succeeded') {
            $result = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find();
            if ($result === null || (int) $result->attempt_no !== (int) $review->attempt_count
                || (int) $result->invocation_id !== (int) $review->last_invocation_id) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review result changed');
            }
            return;
        }
        if (WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null
            || $taskState === TaskState::SUCCEEDED) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review success has no sealed result');
        }
        $next = match ($taskState) {
            TaskState::QUEUED, TaskState::RETRYING => 'pending',
            TaskState::RECOVERY_PENDING => $current === 'running' ? 'recovery_pending' : $current,
            TaskState::FAILED => $current === 'pending' ? 'pending' : 'failed',
            TaskState::CANCELED => 'canceled',
            default => $current,
        };
        if (in_array($taskState, [TaskState::RECOVERY_PENDING, TaskState::FAILED], true)
            && $current !== 'pending'
            && InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
                ->where('parent_task_run_id', (int) $run->task_id)
                ->whereNull('parent_workflow_run_file_id')
                ->where('workflow_node_code', 'cross_file_review')
                ->where('attempt_no', (int) $review->attempt_count)
                ->where('state', 'ambiguous')->find() !== null) {
            // Only a terminal ledger outcome makes this review ambiguous.
            // An in-flight dispatch may still seal a recoverable receipt.
            $next = 'ambiguous';
            $errorCode = 'SAND_AI_DISPATCH_AMBIGUOUS';
        }
        if ($next === $current) {
            return;
        }
        if ($next === 'pending' && $this->providerVisibleDispatchForTask((int) $run->task_id) !== null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A visible cross-file review cannot be retried');
        }
        if (WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)
            ->where('state', $current)->where('attempt_count', (int) $review->attempt_count)
            ->update([
                'state' => $next,
                'error_code' => in_array($next, ['failed', 'recovery_pending', 'ambiguous'], true)
                    && is_string($errorCode)
                    && preg_match('/^SAND_AI_[A-Z0-9_]{1,96}$/D', $errorCode) === 1
                    ? $errorCode : null,
                'failure_summary' => null,
                ...($next === 'pending' ? [
                    'max_output_tokens' => null, 'output_token_parameter' => null,
                ] : []),
            ]) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review state changed');
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $taskId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'system_worker',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'task',
            'resource_id' => $taskId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
