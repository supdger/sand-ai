<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskState;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Application\Task\TaskInputAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Agent\PostgresAgentCheckpointStore;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Persistence\Model\ToolAttempt;
use app\Infrastructure\Persistence\Model\ToolOutcomeReceipt;
use think\facade\Db;

/** Explicit SDK continuation; never relaxes the legacy retry or child dispatch gates. */
final class AgentContinuationGateway
{
    /** @return array{available:bool,reason:?string} Structural availability is not IAM authorization. */
    public function availability(TaskRun $task): array
    {
        $reason = $this->unavailableReason($task);
        return ['available' => $reason === null, 'reason' => $reason];
    }

    /** @param \Closure(TaskRun):array<string,mixed> $authorize @param \Closure(TaskRun,array):void $revalidate */
    public function continueTask(int $environmentId, int $taskId, string $scope, string $key,
        \Closure $authorize, \Closure $revalidate): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $taskId, $scope, $key, $authorize, $revalidate): array {
            $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)->where('task_type', 'agent_run')
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null || !is_array($task->input) || isset($task->input['_channel'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
            }
            (new TaskInputAccessGuard())->assert($task, $scope);
            $input = $task->input;
            $records = $input['_agent_continuations'] ?? [];
            if (!is_array($records) || !array_is_list($records)) self::invalid();
            $current = $authorize($task);
            if (!is_array($current)) self::invalid();
            $revalidate($task, $current);
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['key'] ?? null)) self::invalid();
                if (!hash_equals($record['key'], $key)) continue;
                if (($record['scope'] ?? null) !== $scope || ($record['task_id'] ?? null) !== $taskId) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Continuation key belongs to another request');
                }
                // A replay reads the existing admission and cannot replace its queued identity.
                $revalidate($task, $current);
                return (new TaskGateway())->summary($task, true);
            }
            $reason = $this->unavailableReason($task);
            if ($reason !== null) {
                $code = $reason === 'dispatch_unknown' ? 'SAND_AI_TOOL_FINALIZATION_PENDING' : 'SAND_AI_RESOURCE_CONFLICT';
                throw new ApiProblem($code, 'Agent continuation is unavailable: ' . $reason);
            }
            $inspection = $this->checkpointStore($task)->inspect(AgentGateway::checkpointWorkflowId($task));
            if (!is_array($inspection) || !is_string($inspection['run_id'] ?? null)) self::invalid();
            $record = ['version' => 1, 'key' => $key, 'scope' => $scope, 'task_id' => $taskId,
                'run_id' => $inspection['run_id'], 'execution_attempt' => $inspection['execution_attempt'],
                'task_version' => (int) $task->version, 'lease_fence' => (int) $task->lease_fence + 1,
                'admitted_at' => gmdate('Y-m-d H:i:s'), 'authorization' => $current,
                'model_request_version' => 1, 'model_requests' => AgentGateway::continuationModelRequests($task)];
            $toolGateway = new \app\Application\Tool\ToolRunGateway();
            foreach (ToolRun::where('parent_task_run_id', $taskId)->select() as $run) {
                if ((string) $run->state === 'succeeded') continue;
                $failedStep = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', $taskId)->find();
                if ($failedStep === null || $toolGateway->agentFailedNeverDispatchedProof($task, $failedStep, $run) === null) {
                    throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Agent tool dispatch is not safely resumable');
                }
                if ((int) AgentReadonlyToolContinuationNode::latest($task, $failedStep)->id !== (int) $failedStep->id) continue;
                $context = ['original_step_id' => (int) $failedStep->id, 'original_run_id' => (int) $run->id,
                    'continuation_key' => $key, 'input_sha256' => $failedStep->context['input_sha256'],
                    'readonly_binding_sha256' => $failedStep->context['readonly_binding_sha256'],
                    'child_request_id' => AgentReadonlyToolContinuationNode::childKey($taskId, (int) $failedStep->id, (int) $run->id, $key)];
                $stepCode = AgentReadonlyToolContinuationNode::code((int) $failedStep->id, $key);
                $existing = TaskStep::where('task_id', $taskId)->where('step_code', $stepCode)->select();
                if (count($existing) > 1 || (count($existing) === 1 && !\app\Application\Gateway\RequestPayloadFingerprint::same($existing[0]->context, $context))) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Agent successor node belongs to another continuation');
                }
                if (count($existing) === 0) TaskStep::create(['task_id' => $taskId, 'step_code' => $stepCode,
                    'sequence_no' => (int) TaskStep::where('task_id', $taskId)->max('sequence_no') + 1,
                    'state' => TaskState::QUEUED, 'attempt_count' => 0, 'context' => $context, 'status' => 1]);
            }
            $records[] = $record;
            $input['_agent_continuations'] = $records;
            $input['_agent_continuation'] = $record;
            $revalidate($task, $current);
            $updated = TaskRun::where('id', $taskId)->where('state', TaskState::FAILED)
                ->where('version', (int) $task->version)->where('lease_fence', (int) $task->lease_fence)->update([
                    'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'state' => TaskState::RETRYING, 'available_at' => date('Y-m-d H:i:s'),
                    'completed_at' => null, 'error_code' => null, 'error_summary' => null,
                    'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null,
                    'locked_at' => null, 'locked_by' => null, 'heartbeat_time' => null,
                    'version' => (int) $task->version + 1, 'lease_fence' => (int) $task->lease_fence + 1,
                ]);
            if ($updated !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent changed before continuation');
            $stepUpdated = TaskStep::where('task_id', $taskId)->where('step_code', 'agent_run')
                ->where('sequence_no', 1)->where('state', TaskState::FAILED)->update([
                    'state' => TaskState::RETRYING, 'completed_at' => null, 'error_code' => null, 'error_summary' => null,
                ]);
            if ($stepUpdated !== 1) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent continuation step changed');
            $queued = TaskRun::where('id', $taskId)->find();
            if ($queued === null) self::invalid();
            $revalidate($queued, $current);
            return (new TaskGateway())->summary($queued, true);
        });
    }

    /** @return array<string,mixed> Current admission is independent of the original parent/child envelopes. */
    public static function executionAuthorization(TaskRun $task): array
    {
        $input = $task->input;
        if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)) {
            $authorization = $input['_channel_receipt_recovery']['authorization']['task_authorization'] ?? null;
            if (!is_array($authorization)) self::invalid();
            return $authorization;
        }
        $continuation = is_array($input) ? ($input['_agent_continuation'] ?? null) : null;
        $authorization = $continuation === null ? ($input['iam_authorization'] ?? null)
            : ($continuation['authorization']['task_authorization'] ?? null);
        if (!is_array($authorization)) self::invalid();
        return $authorization;
    }

    public static function revalidateAdmission(TaskRun $task): void
    {
        if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)) {
            \app\Application\Channel\ChannelReceiptRecoveryGateway::revalidateExecution($task);
            return;
        }
        $record = $task->input['_agent_continuation'] ?? null;
        if ($record === null) return;
        if (!is_array($record) || ($record['version'] ?? null) !== 1 || ($record['task_id'] ?? null) !== (int) $task->id
            || !is_int($record['lease_fence'] ?? null) || (int) $task->lease_fence < $record['lease_fence']) self::invalid();
        $queued = (new TaskRunAccessGuard())->revalidateQueued(self::executionAuthorization($task), $task);
        $provider = new HostSandIamIdentityContextProvider();
        $authorizer = new HostSandIamInvocationAuthorizer();
        foreach (['retry_decision' => ['sand_ai.task.retry', 'sand_ai.task_operation'],
            'run_decision' => ['sand_ai.agent.run', 'sand_ai.task_run']] as $field => [$action, $resolver]) {
            try { $identity = $provider->requireSignedContext($queued['signed_context'], $action, $queued['source_ip'], $queued['request_id']); }
            catch (IdentityContextException $failure) { throw IdentityContextProblem::from($failure); }
            if (RequestIdempotencyKey::trustedScope($identity) !== RequestIdempotencyKey::trustedScope($queued['identity'])
                || $identity->organizationId !== $queued['identity']->organizationId
                || $identity->expiresAt !== $queued['identity']->expiresAt) self::invalid();
            $decision = $record['authorization'][$field] ?? null;
            if (!is_array($decision) || !is_int($decision['authorization_id'] ?? null)) self::invalid();
            $authorizer->revalidate($decision['authorization_id'], $queued['signed_context'], $identity,
                $action, $resolver, (string) $task->id, $queued['source_ip'], $queued['request_id']);
        }
        $pendingDecisions = $record['authorization']['tool_node_decisions'] ?? [];
        if (!is_array($pendingDecisions) || !array_is_list($pendingDecisions)) self::invalid();
        if ($pendingDecisions !== []) {
            try { $toolIdentity = $provider->requireSignedContext($queued['signed_context'], 'sand_ai.tool.run', $queued['source_ip'], $queued['request_id']); }
            catch (IdentityContextException $failure) { throw IdentityContextProblem::from($failure); }
            if (RequestIdempotencyKey::trustedScope($toolIdentity) !== RequestIdempotencyKey::trustedScope($queued['identity'])
                || $toolIdentity->organizationId !== $queued['identity']->organizationId || $toolIdentity->expiresAt !== $queued['identity']->expiresAt) self::invalid();
            foreach ($pendingDecisions as $item) {
                if (!is_array($item) || !is_int($item['run_id'] ?? null) || !is_array($item['decision'] ?? null)
                    || !is_int($item['decision']['authorization_id'] ?? null)) self::invalid();
                $original = ToolRun::where('id', $item['run_id'])->where('parent_task_run_id', (int) $task->id)->find();
                $originalStep = $original === null ? null : TaskStep::where('id', (int) $original->parent_task_step_id)->where('task_id', (int) $task->id)->find();
                if ($original === null || $originalStep === null
                    || (new \app\Application\Tool\ToolRunGateway())->agentContinuationNeverDispatchedProof($task, $originalStep, $original) === null) self::invalid();
                $verified = $authorizer->revalidate($item['decision']['authorization_id'], $queued['signed_context'], $toolIdentity,
                    'sand_ai.tool.run', 'sand_ai.tool_run', (string) $original->id, $queued['source_ip'], $queued['request_id']);
                if (($verified['data_class'] ?? null) !== ($original->execution_snapshot['data_class'] ?? null)) self::invalid();
            }
        }
    }

    public function checkpointStore(TaskRun $task): PostgresAgentCheckpointStore
    {
        $connection = Db::connect('pgsql');
        $pdo = $connection instanceof \think\db\PDOConnection ? $connection->getPdo() : false;
        if (!$pdo instanceof \PDO) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent checkpoint storage is unavailable');
        return new PostgresAgentCheckpointStore($pdo, (int) $task->environment_id, (int) $task->id);
    }

    private function unavailableReason(TaskRun $task): ?string
    {
        if ((string) $task->state !== TaskState::FAILED) return 'task_not_failed';
        if ($task->lease_expire_time !== null && (string) $task->lease_expire_time > date('Y-m-d H:i:s')) return 'lease_active';
        if ((int) $task->attempt_count >= (int) $task->max_attempts) return 'attempts_exhausted';
        $input = $task->input;
        if (!is_array($input) || isset($input['_channel']) || ($input['_agent_neuron'] ?? null) !== true || ($input['_agent_checkpoint_version'] ?? null) !== 1) return 'checkpoint_missing';
        try {
            if (isset($input['_agent_definition'])) (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $input['_agent_definition']);
            (new AgentReadonlyToolCatalog())->assertCurrent((int) $task->environment_id, $input['_agent_readonly_tools'] ?? []);
        } catch (\Throwable) { return 'configuration_changed'; }
        try { (new AgentFileUsageGateway())->assertRetryableSources((int) $task->environment_id, (int) $task->id); }
        catch (\Throwable) { return 'source_unavailable'; }
        if ($task->content_purged_time !== null || $task->delete_time !== null) return 'source_unavailable';
        foreach (InvocationDispatch::where('parent_task_run_id', (int) $task->id)->where('environment_id', (int) $task->environment_id)->select() as $dispatch) {
            if (!in_array((string) $dispatch->state, ['succeeded', 'failed', 'not_dispatched'], true)) return 'dispatch_unknown';
            $invocation = \app\Infrastructure\Persistence\Model\Invocation::where('id', (int) $dispatch->invocation_id)
                ->where('environment_id', (int) $task->environment_id)->find();
            if ($invocation === null || !in_array((string) $invocation->state, ['succeeded', 'failed'], true)
                || ((string) $dispatch->state === 'succeeded' && (string) $invocation->state !== 'succeeded')
                || ((string) $dispatch->state !== 'succeeded' && (string) $invocation->state !== 'failed')) return 'dispatch_unknown';
            if ((string) $dispatch->state === 'succeeded') {
                $receipt = \app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt::where('invocation_id', (int) $invocation->id)
                    ->where('environment_id', (int) $task->environment_id)->where('state', 'available')->find();
                if ($receipt === null || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')) return 'dispatch_unknown';
            }
        }
        foreach (ToolRun::where('parent_task_run_id', (int) $task->id)->select() as $run) {
            if ((string) $run->state === 'failed') {
                $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
                if ($step !== null && (new \app\Application\Tool\ToolRunGateway())->agentFailedNeverDispatchedProof($task, $step, $run) !== null) continue;
                return 'dispatch_unknown';
            }
            $attempt = ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->find();
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->where('state', 'available')->find();
            if ((string) $run->state !== 'succeeded' || $attempt === null || (string) $attempt->state !== 'succeeded'
                || $receipt === null || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')) return 'dispatch_unknown';
        }
        try {
            $inspection = $this->checkpointStore($task)->inspectMetadata(AgentGateway::checkpointWorkflowId($task));
            if (!is_array($inspection) || ($inspection['checkpoint_available'] ?? false) !== true
                || ($inspection['run_id'] ?? null) !== AgentGateway::checkpointRunId($task)) return 'checkpoint_missing';
            if (($inspection['state'] ?? null) !== 'failed') return ($inspection['state'] ?? null) === 'running' ? 'lease_active' : 'checkpoint_missing';
        } catch (\Throwable) { return 'checkpoint_missing'; }
        if (!is_array($input['iam_authorization'] ?? null)) return 'authorization_required';
        return null;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent continuation authorization is unavailable');
    }
}
