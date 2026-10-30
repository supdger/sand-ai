<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Gateway\DispatchCoordinator;
use app\Application\Gateway\DispatchFinalizer;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\ProviderDispatchTerminalizationPolicy;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Application\Skill\SkillOperationFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageMeter;
use app\Application\Usage\UsagePricing;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Settles retained original Provider results; never prepares or sends a request. */
final class StructuredUsageRecovery
{
    /** Caller has revalidated SandIAM and rebuilt the current frozen source snapshot. */
    public function recoverPending(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease): void
    {
        foreach (Invocation::where('parent_workflow_run_id', (int) $run->id)
            ->where('state', 'running')->order('id')->select() as $invocation) {
            $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
            if ($dispatch === null || (string) $dispatch->state !== 'dispatching') {
                continue;
            }
            $this->recoverOne($run, $task, $lease, (int) $invocation->id, (int) $dispatch->id);
        }
    }

    private function recoverOne(WorkflowRun $expectedRun, TaskRun $expectedTask, TaskExecutionLease $lease, int $invocationId, int $dispatchId): void
    {
        (new DispatchFinalizer())->success(function () use ($expectedRun, $expectedTask, $lease, $invocationId, $dispatchId): void {
            $reservation = BudgetReservation::where('invocation_id', $invocationId)->order('policy_id')->order('id')->find();
            $reservationId = $reservation === null ? null : (int) $reservation->id;
            $guard = new ChatBudgetGuard();
            $members = $reservationId === null ? [] : $guard->lockReservationGroup($reservationId);
            foreach ($members as $member) {
                if ((int) $member['reservation']->invocation_id !== $invocationId
                    || (string) $member['reservation']->state === 'released') {
                    self::invalid();
                }
            }
            // Same order as Provider terminal/stale work: windows, reservations,
            // invocation, dispatch, current task/step/run, then private receipt.
            $invocation = Invocation::where('id', $invocationId)->lock(true)->find();
            $dispatch = InvocationDispatch::where('id', $dispatchId)->lock(true)->find();
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $run = WorkflowRun::where('id', (int) $expectedRun->id)->lock(true)->find();
            if ($invocation === null || $dispatch === null || $task === null || $step === null || $run === null
                || (int) $expectedTask->id !== $lease->taskId || (int) $run->task_id !== $lease->taskId
                || (int) $task->resource_id !== (int) $run->id || (string) $task->resource_type !== 'workflow_run'
                || (string) $task->task_type !== 'structured_workflow' || (string) $step->state !== TaskState::FAILED
                || (int) $run->status !== 1 || $run->content_purged_time !== null || $task->content_purged_time !== null
                || !hash_equals((string) $expectedRun->execution_snapshot_sha256, (string) $run->execution_snapshot_sha256)
                || !in_array((string) $run->workflow_code, ['material_analysis', 'legal_analysis'], true)) {
                self::invalid();
            }
            self::assertLease($task, $lease);
            $this->assertAttempt($run, $task, $step, $invocation, $dispatch);
            if (!in_array([(string) $invocation->state, (string) $dispatch->state], [
                ['running', 'dispatching'], ['succeeded', 'succeeded'],
            ], true)) {
                self::invalid();
            }
            // A prior local recovery may already have committed this same call.
            if ((string) $invocation->state === 'succeeded') {
                return;
            }
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) {
                throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
            }
            $context = [
                'environment_id' => (int) $run->environment_id, 'invocation_id' => $invocationId,
                'request_fingerprint' => (string) $invocation->request_fingerprint,
                'capability_code' => 'structured_extraction', 'model_id' => (int) $invocation->model_id,
                'deployment_id' => (int) $invocation->deployment_id, 'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
            ];
            Db::connect('pgsql')->query('SELECT invocation_id FROM sand_ai_invocation_outcome_receipt WHERE invocation_id=? FOR UPDATE', [$invocationId]);
            $receipts = new OutcomeReceiptService(new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0));
            if ($receipts->probe($invocationId, $context) !== 'valid') {
                self::invalid();
            }
            $outcome = StructuredProviderOutcome::decode($receipts->replay($context));
            if ($outcome === null) {
                // Old content-only evidence is insufficient to settle or resume.
                self::invalid();
            }
            $attribution = $this->assertOutcome($run, $invocation, $dispatch, $outcome);
            $pricing = CallStartPricingSnapshot::fromPersistence(
                $invocation->call_started_at, $invocation->call_start_pricing_state,
                $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
            );
            if (SkillOperationFingerprint::canonicalJson(self::snapshot($dispatch->price_snapshot))
                !== SkillOperationFingerprint::canonicalJson($pricing->dispatchSnapshot())) {
                self::invalid();
            }
            foreach ($members as $member) {
                $reserved = $member['reservation'];
                if ((int) $reserved->price_revision_id !== (int) $pricing->priceRevisionId
                    || (string) $reserved->request_id !== (string) $invocation->request_id
                    || !hash_equals((string) $invocation->request_fingerprint, (string) $reserved->request_fingerprint)) {
                    self::invalid();
                }
            }
            $usage = (new UsageMeter())->meterFrozen(
                $invocation, $attribution, $outcome->inputTokens, $outcome->outputTokens, $outcome->usageMeasured,
                $pricing, $outcome->provider, $outcome->model, $outcome->deployment,
            );
            Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_usage (invocation_id,input_tokens,output_tokens,total_tokens,cost_amount,currency,source) '
                . 'VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?) ON CONFLICT (invocation_id) DO NOTHING',
                [$invocationId, $outcome->inputTokens, $outcome->outputTokens, $outcome->inputTokens + $outcome->outputTokens,
                    $usage['metered_amount'], $usage['currency'], $outcome->providerAdapter],
            );
            $legacy = Db::connect('pgsql')->query('SELECT input_tokens,output_tokens,total_tokens,cost_amount,currency,source FROM sand_ai_usage WHERE invocation_id=?', [$invocationId])[0] ?? null;
            if (!is_array($legacy) || (int) $legacy['input_tokens'] !== $outcome->inputTokens
                || (int) $legacy['output_tokens'] !== $outcome->outputTokens
                || (int) $legacy['total_tokens'] !== $outcome->inputTokens + $outcome->outputTokens
                || !UsagePricing::matchesLegacyCost($usage['metered_amount'], $legacy['cost_amount'])
                || (string) $legacy['currency'] !== (string) $usage['currency'] || (string) $legacy['source'] !== $outcome->providerAdapter) {
                self::invalid();
            }
            if ($reservationId !== null) {
                $guard->settle($reservationId, $usage['metered_amount'],
                    $usage['metered_amount'] === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered',
                    $pricing->priceRevisionId, 'structured_extraction');
            }
            self::assertLease($task, $lease);
            if (Invocation::where('id', $invocationId)->where('state', 'running')->update([
                'state' => 'succeeded', 'error_code' => null, 'error_summary' => null,
                'latency_ms' => $outcome->latencyMs, 'completed_at' => gmdate('Y-m-d H:i:s'),
            ]) !== 1) {
                self::invalid();
            }
            (new DispatchCoordinator())->finalizeSuccessForScope($dispatchId, null, ProviderDispatchTerminalizationPolicy::succeeded());
            AuditLog::create([
                'actor_type' => 'system', 'actor_ref' => 'outcome_receipt_recovery',
                'action' => 'workflow.usage_recovered', 'resource_type' => 'invocation', 'resource_id' => $invocationId,
                'summary' => 'Recovered original structured Provider usage',
                'context' => ['workflow_run_id' => (int) $run->id, 'provider_called' => false],
            ]);
            self::assertLease($task, $lease);
        });
    }

    private function assertAttempt(WorkflowRun $run, TaskRun $task, TaskStep $step, Invocation $invocation, InvocationDispatch $dispatch): void
    {
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $run->getAttr($field) < 1 || (int) $invocation->getAttr($field) !== (int) $run->getAttr($field)) {
                self::invalid();
            }
        }
        if ((int) $task->environment_id !== (int) $run->environment_id
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->application_id !== (int) $run->application_id
            || (int) $invocation->status !== 1 || (int) $task->status !== 1
            || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->source_kind !== 'structured_workflow'
            || (string) $dispatch->service_action !== 'sand_ai.workflow.run'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->invocation_id !== (int) $invocation->id || $dispatch->dispatch_start_time === null
            || !is_string($invocation->request_fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $invocation->request_fingerprint) !== 1) {
            self::invalid();
        }
        foreach (['parent_workflow_run_id' => (int) $run->id, 'parent_task_run_id' => (int) $task->id, 'parent_task_step_id' => (int) $step->id,
            'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id] as $field => $value) {
            if ($value < 1 || (int) $invocation->getAttr($field) !== $value || (int) $dispatch->getAttr($field) !== $value) {
                self::invalid();
            }
        }
        if (!hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)
            || !hash_equals(hash('sha256', (string) $invocation->request_id), (string) $dispatch->logical_request_id)) {
            self::invalid();
        }
        $definition = self::snapshot($run->definition_snapshot);
        $input = self::snapshot($run->input_snapshot);
        $singleFile = ($input['material_execution_mode'] ?? null) === 'single_file_v1';
        $attempt = (int) $dispatch->attempt_no;
        $fileId = $invocation->parent_workflow_run_file_id === null ? null : (int) $invocation->parent_workflow_run_file_id;
        $node = (string) $invocation->workflow_node_code;
        if ($attempt < 1 || (int) $invocation->execution_attempt_no !== $attempt
            || (string) $dispatch->workflow_node_code !== $node
            || ($dispatch->parent_workflow_run_file_id === null ? null : (int) $dispatch->parent_workflow_run_file_id) !== $fileId) {
            self::invalid();
        }
        if ($singleFile && $fileId === null && $node === 'cross_file_review') {
            $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($review === null || (string) $review->state !== 'recovery_pending' || (int) $review->attempt_count !== $attempt) {
                self::invalid();
            }
            $chunkIdentity = MaterialCrossFileReviewChunkAttemptIdentity::fromRequestId((string) $run->logical_execution_id, (string) $invocation->request_id);
            if ($chunkIdentity !== null && $chunkIdentity->attemptNo !== $attempt) self::invalid();
            if ($chunkIdentity !== null && !(new MaterialCrossFileReviewChunkRunner())->knowsBatch((int) $task->id, $chunkIdentity->batchId)) self::invalid();
            $identity = $chunkIdentity ?? new MaterialCrossFileReviewAttemptIdentity((string) $run->logical_execution_id, $attempt);
            $requestId = $identity->requestId();
            $logicalId = $identity->dispatchLogicalExecutionId();
        } else {
            $file = $fileId === null ? null : WorkflowRunFile::where('id', $fileId)->where('workflow_run_id', (int) $run->id)->lock(true)->find();
            $chunkNode = $singleFile && preg_match('/\Amaterial_(leaf_[0-9]{6}|merge_[0-9]{6}_review_[0-9]{6})\z/D', $node, $match) === 1 ? $match[1] : null;
            if ($chunkNode !== null && !(new MaterialChunkCheckpoint())->knowsNode((int) $task->id, (int) $fileId, $chunkNode)) self::invalid();
            if ($file === null || (int) $file->status !== 1 || ($chunkNode === null && ($definition['steps'][0] ?? null) !== $node)
                || ($singleFile && ((string) $file->analysis_state !== 'recovery_pending' || (int) $file->attempt_count !== $attempt))) {
                self::invalid();
            }
            if ($singleFile) {
                $identity = $chunkNode === null ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, $fileId, $attempt)
                    : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, $fileId, $chunkNode, $attempt);
                $requestId = $identity->requestId();
                $logicalId = $identity->dispatchLogicalExecutionId();
            } else {
                if ($attempt !== 1) self::invalid();
                $requestId = 'structured_' . (string) $run->logical_execution_id;
                $logicalId = (string) $run->logical_execution_id;
            }
        }
        if (!hash_equals($requestId, (string) $invocation->request_id)
            || !hash_equals($logicalId, (string) $invocation->logical_execution_id)
            || !hash_equals($logicalId, (string) $dispatch->logical_execution_id)) {
            self::invalid();
        }
    }

    private function assertOutcome(WorkflowRun $run, Invocation $invocation, InvocationDispatch $dispatch, KnowledgeProviderOutcome $outcome): UsageAttribution
    {
        try {
            $expected = UsageAttribution::fromCurrentPersisted(self::snapshot($run->attribution_snapshot), (int) $run->environment_id,
                'structured_workflow', 'sand_ai.workflow.run')->withCapability('structured_extraction');
            $attribution = UsageAttribution::fromCurrentPersisted($outcome->attribution, (int) $run->environment_id,
                'structured_workflow', 'sand_ai.workflow.run');
        } catch (\InvalidArgumentException) {
            self::invalid();
        }
        $route = DispatchRouteSnapshot::assert(self::snapshot($dispatch->route_snapshot), false);
        if (SkillOperationFingerprint::canonicalJson($expected->toPersisted()) !== SkillOperationFingerprint::canonicalJson($attribution->toPersisted())
            || $route['capability_code'] !== 'structured_extraction'
            || $route['provider_id'] !== $outcome->provider['id'] || (int) $dispatch->provider_id !== $outcome->provider['id']
            || $route['model_id'] !== $outcome->model['id'] || (int) $invocation->model_id !== $outcome->model['id']
            || $route['deployment_id'] !== $outcome->deployment['id'] || (int) $invocation->deployment_id !== $outcome->deployment['id']
            || $route['provider_adapter'] !== $outcome->providerAdapter || $route['model_code'] !== $outcome->model['code']
            || $route['deployment_remote_model'] !== $outcome->deployment['remote_model']
            || $route['deployment_revision'] !== $outcome->deployment['revision']
            || SkillOperationFingerprint::canonicalJson((new UsageMeter())->usageRouteSnapshot($route))
                !== SkillOperationFingerprint::canonicalJson($outcome->deployment['route'])) {
            self::invalid();
        }
        return $attribution;
    }

    private static function assertLease(TaskRun $task, TaskExecutionLease $lease): void
    {
        if ((string) $task->state !== TaskState::RECOVERY_PENDING || (string) $task->lease_owner !== $lease->owner
            || !hash_equals($lease->token, (string) $task->lease_token) || (int) $task->lease_fence !== $lease->fence
            || !is_string($task->lease_expire_time) || $task->lease_expire_time <= date('Y-m-d H:i:s') || $task->canceled_at !== null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Structured accounting recovery lease changed');
        }
    }

    /** @return array<string,mixed> */
    private static function snapshot(mixed $value): array
    {
        $value = is_object($value) ? (array) $value : $value;
        if (!is_array($value)) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original structured Provider accounting facts cannot be verified');
    }
}
