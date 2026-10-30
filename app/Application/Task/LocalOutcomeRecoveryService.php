<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\StructuredWorkflowGateway;
use app\Application\Workflow\WorkflowStateRevision;
use app\Application\Audio\MeetingSummaryRunner;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\facade\Db;

/** Claims receipt-only recovery work. It never invokes a Provider or ChatGateway. */
final class LocalOutcomeRecoveryService
{
    public function __construct(private readonly ?StructuredWorkflowGateway $workflows = null)
    {
    }

    public function runOne(string $workerId): bool
    {
        TaskWorkerIdentity::assertValid($workerId);
        $task = $this->claim($workerId);
        if ($task === null) {
            return false;
        }
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $this->process($task, is_array($input) && is_array($input['_receipt_only_review'] ?? null));
        return true;
    }

    /** A normal authenticated API intent claims exactly one existing paid review. */
    public function recoverReview(TaskRun $expected, string $trustedScope, array $freshAuthorization, \Closure $revalidate): TaskRun
    {
        $workerId = TaskWorkerIdentity::forProcess('receipt-only-api', (int) getmypid());
        $task = Db::connect('pgsql')->transaction(function () use ($expected, $trustedScope, $freshAuthorization, $revalidate, $workerId): TaskRun {
            $task = TaskRun::where('id', (int) $expected->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('version', (int) $expected->version)->where('status', 1)->whereNull('delete_time')
                ->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null || (int) $task->environment_id !== (int) $expected->environment_id
                || (int) $task->local_recovery_count >= (int) $task->local_recovery_max) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Receipt-only recovery is unavailable or exhausted');
            }
            (new TaskInputAccessGuard())->assert($task, $trustedScope);
            (new StructuredTaskAccessGuard())->assert($task, $trustedScope);
            $revalidate($task);
            $run = (new \app\Application\Workflow\MaterialReviewReceiptOnlyGuard())->assert($task, true);
            (new \app\Identity\Infrastructure\WorkflowRunAccessGuard())->revalidateQueued($freshAuthorization, $run);
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            if (!is_array($input)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt-only task input is invalid');
            $review = \app\Infrastructure\Persistence\Model\WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
            if ($review === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Receipt-only review changed');
            $input['iam_authorization'] = $freshAuthorization;
            $input['_receipt_only_review'] = [
                'version' => 1, 'intent_id' => bin2hex(random_bytes(16)),
                'task_id' => (int) $task->id, 'workflow_run_id' => (int) $run->id,
                'review_attempt_no' => (int) $review->attempt_count,
                'local_recovery_no' => (int) $task->local_recovery_count + 1,
            ];
            $now = date('Y-m-d H:i:s');
            $claimable = '((lease_owner IS NULL AND lease_token IS NULL AND lease_expire_time IS NULL) OR lease_expire_time <= ?)';
            $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('version', (int) $task->version)->whereRaw('local_recovery_count < local_recovery_max')
                ->whereRaw($claimable, [$now])->update([
                    'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'locked_at' => $now, 'locked_by' => $workerId, 'lease_owner' => $workerId,
                    'lease_token' => bin2hex(random_bytes(32)), 'lease_fence' => Db::raw('lease_fence + 1'),
                    'lease_expire_time' => date('Y-m-d H:i:s', time() + TaskLeasePolicy::seconds()),
                    'heartbeat_time' => $now, 'local_recovery_count' => Db::raw('local_recovery_count + 1'),
                    'version' => Db::raw('version + 1'),
                ]);
            if ($updated !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Receipt-only task lease changed');
            $claimed = TaskRun::where('id', (int) $task->id)->find();
            if ($claimed === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
            return $claimed;
        });
        $this->process($task, true);
        $current = TaskRun::where('id', (int) $task->id)->find();
        if ($current === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        return $current;
    }

    private function process(TaskRun $task, bool $receiptOnly): void
    {
        $lease = TaskExecutionLease::fromTask($task);
        try {
            if ((string) $task->task_type === 'ai_completion') {
                (new MeetingSummaryRunner())->recoverOutcome($task, $lease);
            } else {
                ($this->workflows ?? new StructuredWorkflowGateway())->recoverOutcome($task, $lease, $receiptOnly);
            }
        } catch (\app\Application\Legal\LegalOutputRejected $failure) {
            try {
                (new \app\Application\Legal\LegalOutputFailureWriter())->reject($task, $lease, $failure);
            } catch (\Throwable $persistenceFailure) {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $persistenceFailure, (int) $task->id, null, $failure->workflowRunId, 'legal_rejection_persistence');
                if ($persistenceFailure instanceof ApiProblem && $persistenceFailure->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $persistenceFailure;
                $this->retainForReview($task, $lease, 'SAND_AI_RECEIPT_INVALID', 'Paid legal rejection could not be persisted');
            }
        } catch (\app\Application\Workflow\MaterialReviewOutputRejected $failure) {
            try {
                (new \app\Application\Workflow\MaterialReviewOutputFailureWriter())->reject($task, $lease, $failure);
            } catch (\Throwable $persistenceFailure) {
                \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                    $persistenceFailure, (int) $task->id, null, $failure->workflowRunId, 'receipt_rejection_persistence');
                if ($persistenceFailure instanceof ApiProblem && $persistenceFailure->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $persistenceFailure;
                $this->retainForReview($task, $lease, 'SAND_AI_RECEIPT_INVALID', 'Paid outcome rejection could not be persisted');
            }
        } catch (ApiProblem $problem) {
            \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                $problem, (int) $task->id, null, (int) $task->resource_id, 'receipt_only_processing');
            if ($problem->errorCode === 'SAND_AI_TASK_LEASE_LOST') throw $problem;
            $this->retainForReview($task, $lease, $problem->errorCode,
                $receiptOnly ? 'Receipt-only recovery requires manual review' : $problem->getMessage());
        } catch (\Throwable $failure) {
            \app\Application\Workflow\MaterialOutputFailureDiagnostics::record(
                $failure, (int) $task->id, null, (int) $task->resource_id, 'receipt_only_processing');
            $this->retainForReview($task, $lease, 'SAND_AI_RECEIPT_INVALID', 'Outcome receipt recovery failed closed');
        }
    }

    private function claim(string $workerId): ?TaskRun
    {
        return Db::connect('pgsql')->transaction(function () use ($workerId): ?TaskRun {
            $now = date('Y-m-d H:i:s');
            $claimableLease = '((lease_owner IS NULL AND lease_token IS NULL AND lease_expire_time IS NULL) OR lease_expire_time <= ?)';
            $candidate = TaskRun::where('state', TaskState::RECOVERY_PENDING)->whereRaw(TaskAuthorizationContinuation::claimSql())
                ->whereRaw("(task_type='structured_workflow' OR (task_type='ai_completion' AND input->'_meeting_summary' IS NOT NULL))")
                ->whereRaw("COALESCE(input->>'_meeting_durable','false') <> 'true'")
                ->where('available_at', '<=', $now)->whereRaw('local_recovery_count < local_recovery_max')
                ->whereRaw($claimableLease, [$now])->where('status', 1)->whereNull('delete_time')
                ->order('available_at')->order('id')->lock(true)->find();
            if ($candidate === null) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $updated = TaskRun::where('id', (int) $candidate->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('version', (int) $candidate->version)->whereRaw('local_recovery_count < local_recovery_max')
                ->whereRaw($claimableLease, [$now])->update([
                    'locked_at' => $now, 'locked_by' => $workerId, 'lease_owner' => $workerId, 'lease_token' => $token,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'lease_expire_time' => date('Y-m-d H:i:s', time() + TaskLeasePolicy::seconds()),
                    'heartbeat_time' => $now, 'local_recovery_count' => Db::raw('local_recovery_count + 1'), 'version' => Db::raw('version + 1'),
                ]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Receipt recovery task changed before claim');
            }
            return TaskRun::where('id', (int) $candidate->id)->find();
        });
    }

    private function retainForReview(TaskRun $task, TaskExecutionLease $lease, string $errorCode, string $summary): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $errorCode, $summary): void {
            $now = date('Y-m-d H:i:s');
            $lockedTask = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->lock(true)->find();
            if ($lockedTask === null) { return; }
            if (TaskRun::where('id', (int) $lockedTask->id)
                ->whereRaw("lease_expire_time > (clock_timestamp() AT TIME ZONE '" . TaskLeasePolicy::runningTimezone() . "')")->value('id') === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '过期恢复租约不能保留复核状态，由新领取的Worker继续处理');
            }
            if (TaskAuthorizationContinuation::authorizationFailure($errorCode)) {
                $step = \app\Infrastructure\Persistence\Model\TaskStep::where('task_id', (int) $lockedTask->id)
                    ->where('sequence_no', 1)->where('state', TaskState::FAILED)->lock(true)->find();
                if ($step === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Authorization recovery step changed');
                $context = TaskAuthorizationContinuation::context($step);
                $context['authorization_continuation'] = ['version' => TaskAuthorizationContinuation::VERSION, 'required' => true];
                $step->save(['context' => $context, 'error_code' => $errorCode,
                    'error_summary' => 'Current caller must explicitly continue the original task']);
            }
            $updated = TaskRun::where('id', (int) $lockedTask->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw("lease_expire_time > (clock_timestamp() AT TIME ZONE '" . TaskLeasePolicy::runningTimezone() . "')")->update([
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null, 'lease_token' => null,
                    'lease_expire_time' => null, 'heartbeat_time' => null, 'available_at' => $now,
                    'error_code' => $errorCode, 'error_summary' => mb_strcut($summary, 0, 512, 'UTF-8'), 'version' => Db::raw('version + 1'),
                ]);
            if ($updated !== 1) {
                return;
            }
            $workflow = (string) $lockedTask->resource_type === 'workflow_run'
                ? WorkflowRun::where('id', (int) $lockedTask->resource_id)->lock(true)->find()
                : null;
            $validWorkflow = (new RecoveryReviewTargetPolicy())->matches([
                'id' => (int) $lockedTask->id, 'environment_id' => (int) $lockedTask->environment_id,
                'resource_type' => (string) $lockedTask->resource_type, 'resource_id' => (int) $lockedTask->resource_id,
            ], $workflow === null ? null : [
                'id' => (int) $workflow->id, 'environment_id' => (int) $workflow->environment_id,
                'task_id' => (int) $workflow->task_id, 'workflow_code' => (string) $workflow->workflow_code,
                'status' => (int) $workflow->status, 'delete_time' => $workflow->delete_time,
            ]);
            if ($validWorkflow) {
                WorkflowRun::where('id', (int) $workflow->id)->update(['review_required' => true]);
            }
            AuditLog::create([
                'actor_type' => 'system', 'actor_ref' => 'outcome_receipt_recovery',
                'action' => $validWorkflow ? 'workflow.receipt_recovery_review_required' : 'task.receipt_recovery_integrity_failure',
                'resource_type' => $validWorkflow ? 'workflow_run' : 'task_run',
                'resource_id' => $validWorkflow ? (int) $workflow->id : (int) $lockedTask->id,
                'summary' => $validWorkflow ? 'Encrypted outcome receipt recovery requires review' : 'Receipt recovery task linkage is invalid',
                'context' => ['error_code' => $errorCode, 'provider_called' => false, 'business_approval_created' => false, 'workflow_link_valid' => $validWorkflow],
            ]);
            if ($validWorkflow) {
                (new WorkflowStateRevision())->changed((int) $workflow->id);
            }
        });
    }
}
