<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\PreDispatchRejection;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\facade\Db;

/** Only the original budget admission rejection proves that a material call was never sent. */
final class MaterialBudgetRejectionEvidence
{
    /** @return array<string,int|string>|null Private journal evidence, never a public response. */
    public function capture(WorkflowRun $run, TaskRun $task, Invocation $invocation,
        string $requestId, string $node, int $attempt, array $messages, int $maxOutputTokens,
        ?MaterialReviewOutputContract $output = null): ?array
    {
        if ($maxOutputTokens < 1) return null;
        $usage = $this->attribution($run);
        $model = AiModel::where('id', (int) $invocation->model_id)->find();
        if ($usage === null || $model === null) return null;
        $fields = ['environment_id' => (int) $run->environment_id,
            'trusted_identity_scope' => RequestIdempotencyKey::trustedScopeForAttribution($usage),
            'model' => (string) $model->code, 'messages' => $messages,
            'max_output_tokens' => $maxOutputTokens, 'capability_code' => 'structured_extraction',
            'capability_fingerprint_version' => 1];
        if ($output !== null) $fields['skill_output'] = $output->fingerprint();
        $proof = ['invocation_id' => (int) $invocation->id, 'request_id' => $requestId,
            'request_fingerprint' => RequestPayloadFingerprint::fingerprint($fields, []),
            'prompt_sha256' => hash('sha256', json_encode($messages,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256];
        return $this->matches($run, $task, $invocation, $requestId, $node, $attempt, $proof) ? $proof : null;
    }

    /** Recheck the immutable rejection before advancing an explicitly retried node attempt. */
    public function matches(WorkflowRun $run, TaskRun $task, Invocation $invocation,
        string $requestId, string $node, int $attempt, array $proof): bool
    {
        $usage = $this->attribution($run);
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ($usage === null || $step === null || $attempt < 1
            || (int) $run->task_id !== (int) $task->id || (int) $task->resource_id !== (int) $run->id
            || ($proof['invocation_id'] ?? null) !== (int) $invocation->id
            || ($proof['request_id'] ?? null) !== $requestId
            || ($proof['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
            || !is_string($proof['request_fingerprint'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['request_fingerprint']) !== 1
            || !is_string($proof['prompt_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['prompt_sha256']) !== 1
            || (string) $invocation->request_id !== $requestId
            || !hash_equals($proof['request_fingerprint'], (string) $invocation->request_fingerprint)
            || (int) $invocation->status !== 1 || $invocation->delete_time !== null
            || (string) $invocation->state !== 'failed'
            || (string) $invocation->error_code !== 'SAND_AI_BUDGET_EXCEEDED') return false;
        $identity = $usage->canonicalTrustedIdentity();
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if (!$this->sameProjection($run->getAttr($field), $identity[$field])
                || !$this->sameProjection($task->getAttr($field), $identity[$field])
                || !$this->sameProjection($invocation->getAttr($field), $identity[$field])) return false;
        }
        // Admission rejection creates the invocation without InternalModelInvocationContext.
        // Its original trusted task/node/attempt binding is the separate immutable rejection.
        $rejection = PreDispatchRejection::where('trusted_scope', RequestIdempotencyKey::trustedScopeForAttribution($usage))
            ->where('request_id', $requestId)->find();
        if ($rejection === null || (int) $rejection->invocation_id !== (int) $invocation->id
            || (int) $rejection->environment_id !== (int) $run->environment_id
            || !$this->sameProjection($rejection->application_id, $usage->applicationId)
            || !$this->sameProjection($rejection->workload_client_id, $usage->workloadClientId)
            || (int) $rejection->parent_task_run_id !== (int) $task->id
            || (int) $rejection->parent_task_step_id !== (int) $step->id
            || (string) $rejection->workflow_node_code !== $node
            || (int) $rejection->attempt_no !== $attempt
            || (string) $rejection->execution_scope !== 'structured_workflow'
            || (string) $rejection->rejection_stage !== 'budget'
            || (string) $rejection->error_code !== 'SAND_AI_BUDGET_EXCEEDED'
            || !hash_equals($proof['request_fingerprint'], (string) $rejection->request_fingerprint)) return false;
        // Even a prepared dispatch is outside this narrow admission proof.
        foreach (['sand_ai_invocation_dispatch', 'sand_ai_budget_reservation',
            'sand_ai_usage_event', 'sand_ai_invocation_outcome_receipt'] as $table) {
            if (Db::connect('pgsql')->table($table)->where('invocation_id', (int) $invocation->id)->count() > 0) return false;
        }
        return true;
    }

    private function sameProjection(mixed $actual, ?int $expected): bool
    {
        return $expected === null ? $actual === null
            : (is_int($actual) || (is_string($actual) && preg_match('/\A[1-9][0-9]*\z/D', $actual) === 1))
                && (int) $actual === $expected;
    }

    private function attribution(WorkflowRun $run): ?UsageAttribution
    {
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            return UsageAttribution::fromCurrentPersisted(is_array($snapshot) ? $snapshot : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run');
        } catch (\InvalidArgumentException) { return null; }
    }
}
