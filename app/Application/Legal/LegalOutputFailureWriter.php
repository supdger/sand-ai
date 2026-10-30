<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Application\Workflow\StructuredProviderOutcome;
use app\Application\Workflow\WorkflowStateRevision;
use app\Application\Workflow\StructuredWorkflowPolicy;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Task\TaskLeaseTerminalGuard;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Seals a known paid legal rejection; does not consume or reissue its outcome. */
final class LegalOutputFailureWriter
{
    public function reject(TaskRun $original, TaskExecutionLease $lease, LegalOutputRejected $failure): void
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
            if ($invocation === null || $dispatch === null || $usage === null || $receipt === null
                || $task === null || $step === null || $run === null
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
                || (string) $run->workflow_code !== 'legal_analysis'
                || (int) $run->status !== 1 || $run->delete_time !== null
                || $failure->attemptNo !== 1
                || \app\Infrastructure\Persistence\Model\Artifact::where('workflow_run_id', (int) $run->id)->lock(true)->find() !== null
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
            $file = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->lock(true)->find();
            $snapshot = (array) $run->execution_snapshot;
            if ($file === null || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($snapshot))) self::invalid();
            foreach ([$invocation, $dispatch] as $row) {
                if ((int) $row->parent_workflow_run_id !== (int) $run->id
                    || (int) $row->parent_task_run_id !== (int) $task->id
                    || (int) $row->parent_task_step_id !== (int) $step->id
                    || (int) $row->parent_workflow_run_file_id !== (int) $file->id
                    || (string) $row->workflow_node_code !== 'analyze_legal_sources'
                    || !hash_equals((string) $run->execution_snapshot_sha256, (string) $row->execution_snapshot_sha256)
                    || !hash_equals((string) $run->logical_execution_id, (string) $row->logical_execution_id)) self::invalid();
            }
            if ((int) $dispatch->attempt_no !== 1 || (int) $invocation->execution_attempt_no !== 1
                || !hash_equals('structured_' . (string) $run->logical_execution_id, (string) $invocation->request_id)) self::invalid();
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
            $input = (array) $run->input_snapshot;
            $legal = $input['legal_analysis'] ?? null;
            if (!is_array($legal)) self::invalid();
            $snapshotSources = $snapshot['legal_analysis']['sources'] ?? null;
            if (!is_array($snapshotSources) || StructuredWorkflowPolicy::fingerprint($legal) !== StructuredWorkflowPolicy::fingerprint($snapshot['legal_analysis'])) self::invalid();
            $ids = array_merge(...array_column($snapshotSources, 'source_block_ids'));
            try {
                (new LegalAnalysisOutputValidator())->validate($accounted->content, $legal['profile'], $ids,
                    $legal['sources'], $legal['facts'], LegalOutputContract::fromSnapshot($snapshot));
                self::invalid();
            } catch (ApiProblem $rejection) {
                if ($rejection->errorCode !== $failure->errorCode) self::invalid();
            }

            $timezone = TaskLeasePolicy::runningTimezone();
            if (TaskRun::where('id', (int) $task->id)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [$timezone])->value('id') === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Legal output rejection lease expired');
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
            TaskLeaseTerminalGuard::assertUpdated($taskUpdated, $stepUpdated, 'paid legal output rejection');
            AuditLog::create([
                'environment_id' => (int) $run->environment_id,
                'actor_type' => 'system', 'actor_ref' => 'legal_output_validation',
                'action' => 'workflow.legal_output_rejected', 'resource_type' => 'workflow_run',
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
        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Legal rejection evidence is unavailable');
    }
}
