<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Task\TaskLeaseTerminalGuard;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Seals a known paid review rejection; does not consume or reissue its outcome. */
final class MaterialReviewOutputFailureWriter
{
    public function reject(TaskRun $original, TaskExecutionLease $lease, MaterialReviewOutputRejected $failure): void
    {
        if ($failure->workflowRunId === null || $failure->invocationId === null
            || $failure->attemptNo === null || $failure->contentSha256 === null) {
            self::invalid();
        }
        Db::connect('pgsql')->transaction(function () use ($original, $lease, $failure): void {
            $invocation = Invocation::where('id', $failure->invocationId)->lock(true)->find();
            $dispatch = InvocationDispatch::where('invocation_id', $failure->invocationId)->lock(true)->find();
            $usage = UsageEvent::where('invocation_id', $failure->invocationId)->find();
            $receipt = InvocationOutcomeReceipt::where('invocation_id', $failure->invocationId)->lock(true)->find();
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $run = WorkflowRun::where('id', $failure->workflowRunId)->lock(true)->find();
            $review = WorkflowCrossFileReview::where('workflow_run_id', $failure->workflowRunId)->lock(true)->find();
            if ($invocation === null || $dispatch === null || $usage === null || $receipt === null
                || $task === null || $step === null || $run === null || $review === null
                || (int) $task->id !== (int) $original->id
                || (string) $task->task_type !== 'structured_workflow'
                || (string) $task->resource_type !== 'workflow_run'
                || (int) $task->resource_id !== (int) $run->id
                || (int) $run->task_id !== (int) $task->id
                || !in_array((string) $task->state, [TaskState::RUNNING, TaskState::RECOVERY_PENDING], true)
                || (string) $step->state !== ((string) $task->state === TaskState::RUNNING ? TaskState::RUNNING : TaskState::FAILED)
                || (string) $task->lease_owner !== $lease->owner
                || !hash_equals($lease->token, (string) $task->lease_token)
                || (int) $task->lease_fence !== $lease->fence
                || (int) $task->status !== 1 || $task->delete_time !== null || $task->content_purged_time !== null
                || (string) $run->workflow_code !== 'material_analysis'
                || (int) $run->status !== 1 || $run->delete_time !== null
                || !in_array((string) $review->state, ['running', 'recovery_pending'], true)
                || $failure->attemptNo > (int) $review->attempt_count
                || WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $run->id)->find() !== null
                || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded'
                || (int) $invocation->status !== 1 || $invocation->delete_time !== null
                || (string) $dispatch->execution_scope !== 'structured_workflow'
                || (string) $dispatch->capability_code !== 'structured_extraction'
                || (string) $receipt->state !== 'available' || $receipt->consumer_ack_time !== null
                || (string) $receipt->capability_code !== 'structured_extraction') {
                self::invalid();
            }
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                if ((int) $run->{$field} < 1 || (int) $invocation->{$field} !== (int) $run->{$field}
                    || (int) $usage->{$field} !== (int) $run->{$field}) {
                    self::invalid();
                }
            }
            if ((int) $task->environment_id !== (int) $run->environment_id
                || (int) $dispatch->environment_id !== (int) $run->environment_id
                || (int) $receipt->environment_id !== (int) $run->environment_id
                || (int) $dispatch->invocation_id !== (int) $invocation->id
                || (int) $usage->invocation_id !== (int) $invocation->id
                || (int) $receipt->invocation_id !== (int) $invocation->id
                || (int) $dispatch->model_id !== (int) $invocation->model_id
                || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
                || (int) $usage->model_id !== (int) $invocation->model_id
                || (int) $usage->deployment_id !== (int) $invocation->deployment_id
                || (string) $usage->service_action !== 'sand_ai.workflow.run'
                || (string) $usage->source_kind !== 'structured_workflow'
                || (string) $usage->capability_code !== 'structured_extraction'
                || (string) $usage->usage_state !== 'measured'
                || (int) $usage->provider_id !== (int) $dispatch->provider_id
                || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id
                || (int) $receipt->model_id !== (int) $invocation->model_id
                || (int) $receipt->deployment_id !== (int) $invocation->deployment_id
                || !hash_equals((string) $invocation->request_fingerprint, (string) $receipt->request_fingerprint)) {
                self::invalid();
            }
            $chunkRunner = new MaterialCrossFileReviewChunkRunner();
            $chunked = $chunkRunner->active($run, $task);
            $batchIdentity = $chunked ? $chunkRunner->invocationBatch($run, $task, $invocation) : null;
            $identity = $chunked ? $batchIdentity['identity']
                : new MaterialCrossFileReviewAttemptIdentity((string) $run->logical_execution_id, $failure->attemptNo);
            if (!$chunked && (int) $review->attempt_count !== $failure->attemptNo) self::invalid();
            foreach ([$invocation, $dispatch] as $row) {
                if ((int) $row->parent_workflow_run_id !== (int) $run->id
                    || (int) $row->parent_task_run_id !== (int) $task->id
                    || (int) $row->parent_task_step_id !== (int) $step->id
                    || $row->parent_workflow_run_file_id !== null
                    || (string) $row->workflow_node_code !== 'cross_file_review'
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $row->execution_snapshot_sha256)
                    || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $row->logical_execution_id)) {
                    self::invalid();
                }
            }
            if ((int) $dispatch->attempt_no !== $failure->attemptNo
                || (int) $invocation->execution_attempt_no !== $failure->attemptNo
                || !hash_equals($identity->requestId(), (string) $invocation->request_id)) {
                self::invalid();
            }
            $files = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->select();
            if (count($files) < 2) self::invalid();
            foreach ($files as $file) {
                AiFile::where('id', (int) $file->file_id)->lock(true)->find();
                AiFileParse::where('id', (int) $file->parse_id)->lock(true)->find();
                SourceBlock::where('file_id', (int) $file->file_id)->where('parse_id', (int) $file->parse_id)
                    ->order('id')->lock(true)->select();
                $result = WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->lock(true)->find();
                if ($result !== null) WorkflowFileResultSource::where('result_id', (int) $result->id)->order('id')->lock(true)->select();
                if ((string) $file->analysis_state !== 'succeeded' || $result === null
                    || (int) $file->attempt_count !== (int) $result->attempt_no
                    || (int) $file->last_invocation_id !== (int) $result->invocation_id
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                    self::invalid();
                }
                MaterialAnalysisFilePayloadDigest::verified(
                    $result->payload, (string) $result->payload_sha256,
                    $result->payload_digest_version, $result->content_purged_time,
                );
            }
            // Reuse the production sealed-input and request-fingerprint proof.
            $currentInput = (new MaterialCrossFileReviewInputLoader())->loadForWriteTransaction((int) $run->id, $lease);
            if ($chunked) {
                $found = false;
                foreach ($currentInput['review_batches'] as $batch) {
                    if ($batch['id'] !== $batchIdentity['id']) continue;
                    $currentInput = $batch + array_intersect_key($currentInput, array_flip(['material_review_output_contract']));
                    $found = true;
                    break;
                }
                if (!$found) self::invalid();
                $chunkRunner->assertIdentity($run, $task, $invocation, $dispatch, $batchIdentity['id'], $failure->attemptNo, $batchIdentity['final']);
            }
            $attributionSnapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($attributionSnapshot) ? $attributionSnapshot : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run',
            );
            $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
            if (!is_array($route) || !is_int($review->max_output_tokens) || !is_string($review->output_token_parameter)) self::invalid();
            MaterialCrossFileReviewRequestEvidence::assert(
                (int) $run->environment_id, $attribution, (int) $invocation->model_id, (int) $invocation->deployment_id,
                $route, $review->max_output_tokens, $review->output_token_parameter, $currentInput['messages'],
                (string) $invocation->request_fingerprint, (string) $dispatch->request_body_digest,
                MaterialReviewOutputContract::fromSnapshot($currentInput),
            );
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) self::invalid();
            $outcome = (new OutcomeReceiptService(
                new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0),
            ))->replay([
                'environment_id' => (int) $run->environment_id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint,
                'capability_code' => 'structured_extraction', 'model_id' => (int) $invocation->model_id,
                'deployment_id' => (int) $invocation->deployment_id, 'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
            ]);
            $accounted = StructuredProviderOutcome::decode($outcome);
            if ($accounted === null || !$accounted->usageMeasured
                || !hash_equals($failure->contentSha256, hash('sha256', $accounted->content))
                || (int) $usage->input_tokens !== $accounted->inputTokens
                || (int) $usage->output_tokens !== $accounted->outputTokens
                || (int) $usage->total_tokens !== $accounted->inputTokens + $accounted->outputTokens
                || (int) $usage->provider_id !== ($accounted->provider['id'] ?? null)
                || (int) $usage->model_id !== ($accounted->model['id'] ?? null)
                || (int) $usage->deployment_id !== ($accounted->deployment['id'] ?? null)) {
                self::invalid();
            }
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                if ((int) $run->{$field} !== ($accounted->attribution[$field] ?? null)) self::invalid();
            }
            $timezone = TaskLeasePolicy::runningTimezone();
            if (TaskRun::where('id', (int) $task->id)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [$timezone])->value('id') === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material output rejection lease expired');
            }
            $now = date('Y-m-d H:i:s');
            $taskUpdated = TaskRun::where('id', (int) $task->id)->where('state', (string) $task->state)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [$timezone])->update([
                    'state' => TaskState::FAILED, 'error_code' => $failure->errorCode,
                    'error_summary' => $failure->getMessage(), 'completed_at' => $now,
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'version' => Db::raw('version + 1'),
                ]);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->where('state', (string) $step->state)->update([
                'state' => TaskState::FAILED, 'error_code' => $failure->errorCode,
                'error_summary' => $failure->getMessage(), 'completed_at' => $now,
            ]);
            TaskLeaseTerminalGuard::assertUpdated($taskUpdated, $stepUpdated, 'paid material output rejection');
            if (WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)
                ->where('state', (string) $review->state)->where('attempt_count', (int) $review->attempt_count)->update([
                    'state' => 'failed', 'error_code' => $failure->errorCode,
                    'failure_summary' => $failure->getMessage(), 'last_invocation_id' => (int) $invocation->id,
                ]) !== 1) self::invalid();
            AuditLog::create([
                'actor_type' => 'system', 'actor_ref' => 'material_review_output_validation',
                'action' => 'workflow.review_output_rejected', 'resource_type' => 'workflow_run',
                'resource_id' => (int) $run->id, 'summary' => $failure->getMessage(),
                'context' => [
                    'task_id' => (int) $task->id, 'step_id' => (int) $step->id,
                    'error_code' => $failure->errorCode, 'attempt_no' => $failure->attemptNo,
                    'invocation_id' => (int) $invocation->id, 'dispatch_id' => (int) $dispatch->id,
                    'receipt_id' => (int) $receipt->id, 'usage_event_id' => (int) $usage->id,
                    'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                    'content_sha256' => $failure->contentSha256, 'receipt_retained' => true,
                    'provider_reissued' => false, 'permanent_output_rejection' => true,
                ],
            ]);
            (new WorkflowStateRevision())->terminalChanged((int) $run->id, TaskState::FAILED);
        });
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Material review rejection evidence is unavailable');
    }
}
