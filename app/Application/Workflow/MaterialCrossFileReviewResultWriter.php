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
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewSource;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewWithheld;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Seals a reviewed answer and its source links against the complete file set. */
final class MaterialCrossFileReviewResultWriter
{
    public function __construct(
        private readonly MaterialCrossFileReviewResultPolicy $policy = new MaterialCrossFileReviewResultPolicy(),
        private readonly MaterialCrossFileReviewInputLoader $loader = new MaterialCrossFileReviewInputLoader(),
    ) {
    }

    /** @param array<string,mixed> $dispatchedInput */
    public function store(
        TaskExecutionLease $lease,
        int $workflowRunId,
        int $invocationId,
        array $dispatchedInput,
        string $rawJson,
        bool $recovery = false,
    ): int {
        if ($workflowRunId < 1 || $invocationId < 1) {
            self::invalid();
        }
        return Db::connect('pgsql')->transaction(function () use (
            $lease, $workflowRunId, $invocationId, $dispatchedInput, $rawJson, $recovery,
        ): int {
            Db::connect('pgsql')->execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            // Match the terminal writer lock order before touching workflow rows.
            $invocation = Invocation::where('id', $invocationId)->lock(true)->find();
            $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->lock(true)->find();
            $usage = UsageEvent::where('invocation_id', $invocationId)->find();
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $run = WorkflowRun::where('id', $workflowRunId)->lock(true)->find();
            $review = WorkflowCrossFileReview::where('workflow_run_id', $workflowRunId)->lock(true)->find();
            if ($invocation === null || $dispatch === null || $usage === null
                || $task === null || $step === null || $run === null || $review === null
                || (int) $run->task_id !== (int) $task->id
                || (int) $run->environment_id !== (int) $task->environment_id
                || (string) $run->workflow_code !== 'material_analysis'
                || (string) $run->output_schema_code !== 'sand_ai.material_analysis'
                || (string) $run->output_schema_version !== '1.0.0'
                || (int) $run->status !== 1 || $run->delete_time !== null
                || (string) $task->state !== ($recovery ? TaskState::RECOVERY_PENDING : TaskState::RUNNING)
                || (string) $step->state !== ($recovery ? TaskState::FAILED : TaskState::RUNNING)
                || (string) $task->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $task->lease_token)
                || (int) $task->lease_fence !== $lease->fence
                || !in_array((string) $review->state, $recovery ? ['recovery_pending', 'succeeded'] : ['running', 'succeeded'], true)
                || (int) $review->attempt_count < 1) {
                self::invalid();
            }
            $this->lockFileEvidence($workflowRunId);
            $current = $this->loader->loadForWriteTransaction($workflowRunId, $lease);
            $chunkRunner = new MaterialCrossFileReviewChunkRunner();
            if ($chunkRunner->active($run, $task)) {
                $prepared = $chunkRunner->prepareSealed($run, $task, $dispatchedInput, $current, $rawJson, $invocationId);
            } else {
            $this->assertRequestEvidence($run, $review, $invocation, $dispatch, $dispatchedInput);
            $prepared = $this->policy->prepare(
                [
                    'environment_id' => (int) $run->environment_id,
                    'organization_id' => (int) $run->organization_id,
                    'application_id' => (int) $run->application_id,
                    'workload_client_id' => (int) $run->workload_client_id,
                    'workflow_run_id' => (int) $run->id,
                    'task_id' => (int) $task->id,
                    'step_id' => (int) $step->id,
                    'attempt_no' => (int) $review->attempt_count,
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
                    'parent_workflow_run_file_id' => $invocation->parent_workflow_run_file_id,
                    'workflow_node_code' => (string) $invocation->workflow_node_code,
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
                    'parent_workflow_run_file_id' => $dispatch->parent_workflow_run_file_id,
                    'workflow_node_code' => (string) $dispatch->workflow_node_code,
                    'attempt_no' => (int) $dispatch->attempt_no,
                    'execution_snapshot_sha256' => (string) $dispatch->execution_snapshot_sha256,
                    'logical_execution_id' => (string) $dispatch->logical_execution_id,
                    'logical_request_id' => (string) $dispatch->logical_request_id,
                    'model_id' => (int) $dispatch->model_id,
                    'deployment_id' => (int) $dispatch->deployment_id,
                    'capability_code' => (string) $dispatch->capability_code,
                    'state' => (string) $dispatch->state,
                ],
                [
                    'invocation_id' => (int) $usage->invocation_id,
                    'environment_id' => (int) $usage->environment_id,
                    'organization_id' => (int) $usage->organization_id,
                    'application_id' => (int) $usage->application_id,
                    'workload_client_id' => (int) $usage->workload_client_id,
                    'model_id' => (int) $usage->model_id,
                    'deployment_id' => (int) $usage->deployment_id,
                    'service_action' => (string) $usage->service_action,
                    'source_kind' => (string) $usage->source_kind,
                    'capability_code' => (string) $usage->capability_code,
                    'idempotency_key' => (string) $usage->idempotency_key,
                ],
                $dispatchedInput,
                $current,
                $rawJson,
                $this->maxOutputCharacters($run),
            );
            }
            $existing = WorkflowCrossFileReviewResult::where('workflow_run_id', $workflowRunId)->lock(true)->find();
            if ($existing !== null) {
                $this->assertExisting($existing, $review, $run, $prepared, $invocationId);
                $this->acknowledgeReceipt($invocationId, (int) $existing->id);
                return (int) $existing->id;
            }
            if ((string) $review->state !== ($recovery ? 'recovery_pending' : 'running')) {
                self::invalid();
            }
            $result = WorkflowCrossFileReviewResult::create([
                'workflow_run_id' => $workflowRunId,
                'attempt_no' => (int) $review->attempt_count,
                'invocation_id' => $invocationId,
                'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                'input_sha256' => $prepared['input_sha256'],
                'payload' => $prepared['payload'],
                'payload_sha256' => $prepared['payload_sha256'],
                'field_source_count' => $prepared['field_source_count'],
                'withheld_candidate_count' => $prepared['withheld_candidate_count'],
            ]);
            foreach ($prepared['source_rows'] as $source) {
                WorkflowCrossFileReviewSource::create([
                    'result_id' => (int) $result->id,
                    'field_path' => $source['field_path'],
                    'source_block_id' => $source['source_block_id'],
                ]);
            }
            foreach ($prepared['withheld_rows'] as $candidate) {
                WorkflowCrossFileReviewWithheld::create([
                    'result_id' => (int) $result->id,
                    'workflow_run_file_id' => $candidate['workflow_run_file_id'],
                    'field_path' => $candidate['field_path'],
                ]);
            }
            if (WorkflowCrossFileReview::where('workflow_run_id', $workflowRunId)
                ->where('state', $recovery ? 'recovery_pending' : 'running')
                ->where('attempt_count', (int) $review->attempt_count)
                ->update([
                    'state' => 'succeeded', 'last_invocation_id' => $invocationId,
                    'error_code' => null, 'failure_summary' => null,
                ]) !== 1) {
                self::invalid();
            }
            $this->acknowledgeReceipt($invocationId, (int) $result->id);
            (new WorkflowStateRevision())->changed($workflowRunId);
            return (int) $result->id;
        });
    }

    private function lockFileEvidence(int $workflowRunId): void
    {
        $runFiles = WorkflowRunFile::where('workflow_run_id', $workflowRunId)->order('id')->lock(true)->select();
        if (count($runFiles) < 2) {
            self::invalid();
        }
        foreach ($runFiles as $runFile) {
            AiFile::where('id', (int) $runFile->file_id)->lock(true)->find();
            AiFileParse::where('id', (int) $runFile->parse_id)->lock(true)->find();
            SourceBlock::where('file_id', (int) $runFile->file_id)
                ->where('parse_id', (int) $runFile->parse_id)->order('id')->lock(true)->select();
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $runFile->id)->lock(true)->find();
            if ($result !== null) {
                WorkflowFileResultSource::where('result_id', (int) $result->id)->order('id')->lock(true)->select();
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function assertRequestEvidence(WorkflowRun $run, WorkflowCrossFileReview $review, Invocation $invocation, InvocationDispatch $dispatch, array $input): void
    {
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($snapshot) ? $snapshot : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run',
            );
        } catch (\InvalidArgumentException) {
            self::invalid();
        }
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        if (!is_int($review->max_output_tokens) || !is_string($review->output_token_parameter)
            || !is_array($route) || !is_array($input['messages'] ?? null)) {
            self::invalid();
        }
        MaterialCrossFileReviewRequestEvidence::assert(
            (int) $run->environment_id, $attribution,
            (int) $invocation->model_id, (int) $invocation->deployment_id,
            $route, $review->max_output_tokens, $review->output_token_parameter,
            $input['messages'], (string) $invocation->request_fingerprint,
            (string) $dispatch->request_body_digest,
            MaterialReviewOutputContract::fromSnapshot($input),
        );
    }

    private function maxOutputCharacters(WorkflowRun $run): int
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $profile = is_array($input) ? ($input['analysis_profile']['snapshot'] ?? null) : null;
        if (!is_array($profile)) {
            self::invalid();
        }
        return (new MaterialAnalysisProfilePolicy())->validate($profile)['max_output_characters'];
    }

    /** @param array<string,mixed> $prepared */
    private function assertExisting(WorkflowCrossFileReviewResult $existing, WorkflowCrossFileReview $review, WorkflowRun $run, array $prepared, int $invocationId): void
    {
        $payload = is_object($existing->payload) ? (array) $existing->payload : $existing->payload;
        if (!is_array($payload)) {
            self::invalid();
        }
        if ((string) $review->state !== 'succeeded'
            || (int) $review->last_invocation_id !== $invocationId
            || (int) $existing->invocation_id !== $invocationId
            || (int) $existing->attempt_no !== (int) $review->attempt_count
            || !hash_equals((string) $existing->execution_snapshot_sha256, (string) $run->execution_snapshot_sha256)
            || !hash_equals((string) $existing->input_sha256, $prepared['input_sha256'])
            || !hash_equals((string) $existing->payload_sha256, $prepared['payload_sha256'])
            || !hash_equals(StructuredWorkflowPolicy::fingerprint($payload), $prepared['payload_sha256'])
            || (int) $existing->field_source_count !== $prepared['field_source_count']
            || (int) $existing->withheld_candidate_count !== $prepared['withheld_candidate_count']) {
            self::invalid();
        }
        $sources = [];
        foreach (WorkflowCrossFileReviewSource::where('result_id', (int) $existing->id)
            ->order('field_path')->order('source_block_id')->lock(true)->select() as $source) {
            $sources[] = ['field_path' => (string) $source->field_path, 'source_block_id' => (int) $source->source_block_id];
        }
        $withheld = [];
        foreach (WorkflowCrossFileReviewWithheld::where('result_id', (int) $existing->id)
            ->order('workflow_run_file_id')->order('field_path')->lock(true)->select() as $candidate) {
            $withheld[] = [
                'workflow_run_file_id' => (int) $candidate->workflow_run_file_id,
                'field_path' => (string) $candidate->field_path,
            ];
        }
        if ($sources !== $prepared['source_rows'] || $withheld !== $prepared['withheld_rows']) {
            self::invalid();
        }
    }

    private function acknowledgeReceipt(int $invocationId, int $resultId): void
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt retention is not configured');
        }
        $now = gmdate('Y-m-d H:i:s');
        (new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(),
            new OutcomeReceiptCipher($config),
            (int) ($config['ttl_seconds'] ?? 0),
        ))->acknowledgeCrossFileReview(
            $invocationId, $resultId, $now,
            OutcomeReceiptRetentionPolicy::fromConfig($config)->retainUntil(time()),
        );
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Cross-file review evidence changed before persistence');
    }
}
