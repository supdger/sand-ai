<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Agent\AgentContinuationGateway;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentFileUsageGateway;
use app\Application\Agent\AgentGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskInputAccessGuard;
use app\Application\Task\TaskState;
use app\Application\Task\TaskAuthorizationContinuation;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Persistence\Model\ToolAttempt;
use app\Infrastructure\Persistence\Model\ToolOutcomeReceipt;
use think\facade\Db;

/** Current original caller may recover sealed outcomes, never authorize another inference. */
final class ChannelReceiptRecoveryGateway
{
    public const VERSION = 'channel_receipt_recovery@1';
    public const AUTHORIZATION_VERSION = 'channel_receipt_authorization@1';

    /** Find the exact original admission, including one superseded by a later current authorization. */
    public function readRecovery(int $environmentId, int $taskId, string $scope, string $key,
        array $target, \Closure $authorize): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $taskId, $scope, $key, $target, $authorize): array {
            $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)->where('task_type', 'agent_run')
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
            (new TaskInputAccessGuard())->assert($task, $scope);
            $input = $task->input;
            $channel = is_array($input) ? ($input['_channel'] ?? null) : null;
            if (!is_array($channel) || ($channel['code'] ?? null) !== $target['code']
                || ($channel['channel_binding_id'] ?? null) !== $target['channel_binding_id']
                || ($channel['conversation_scope'] ?? null) !== $target['conversation_scope']
                || ($channel['delivery_platform'] ?? null) !== ($target['delivery_platform'] ?? null)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel message not found');
            }
            $authorize($task);
            $records = $input['_channel_receipt_recoveries'] ?? [];
            if (!is_array($records) || !array_is_list($records)) self::invalid();
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['key'] ?? null)) self::invalid();
                if (!hash_equals($key, $record['key'])) continue;
                if (($record['version'] ?? null) !== self::VERSION || ($record['scope'] ?? null) !== $scope
                    || ($record['task_id'] ?? null) !== $taskId
                    || ($record['original_request_id'] ?? null) !== (string) $task->request_id
                    || !is_string($record['caller_intent_sha256'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $record['caller_intent_sha256']) !== 1
                    || !is_int($record['previous_task_version'] ?? null) || !is_int($record['previous_lease_fence'] ?? null)) self::invalid();
                (new ChannelBindingGateway())->assertCurrent($environmentId, $channel);
                (new AgentDefinitionGateway())->assertCurrent($environmentId, $input['_agent_definition'] ?? null);
                $result = ['task' => (new TaskGateway())->summary($task, true), 'receipt_recovery' => [
                    'mode' => 'receipt_only', 'intent_key_sha256' => $record['caller_intent_sha256'],
                    'previous_task_version' => $record['previous_task_version'],
                    'previous_lease_fence' => $record['previous_lease_fence'], 'accepted' => true]];
                $authorize($task);
                return $result;
            }
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel recovery intent not found');
        });
    }

    /** @param array<string,mixed> $target */
    public function recover(int $environmentId, int $taskId, string $scope, string $key, array $target,
        \Closure $authorize, \Closure $revalidate): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $taskId, $scope, $key, $target, $authorize, $revalidate): array {
            $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)->where('task_type', 'agent_run')
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
            (new TaskInputAccessGuard())->assert($task, $scope);
            $input = $task->input;
            $channel = is_array($input) ? ($input['_channel'] ?? null) : null;
            if (!is_array($channel) || ($channel['code'] ?? null) !== $target['code']
                || ($channel['channel_binding_id'] ?? null) !== $target['channel_binding_id']
                || ($channel['conversation_scope'] ?? null) !== $target['conversation_scope']
                || ($channel['delivery_platform'] ?? null) !== ($target['delivery_platform'] ?? null)
                || !is_string($channel['message_scope'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $channel['message_scope']) !== 1
                || preg_match('/\Achannel:[a-f0-9]{64}\z/D', (string) $task->request_id) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel message not found');
            }
            $records = $input['_channel_receipt_recoveries'] ?? [];
            if (!is_array($records) || !array_is_list($records)) self::invalid();
            if (!is_string($target['caller_intent_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $target['caller_intent_sha256']) !== 1) self::invalid();
            $fingerprint = RequestPayloadFingerprint::fingerprint($target, []);
            $current = $authorize($task);
            if (!is_array($current)) self::invalid();
            $revalidate($task, $current);
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['key'] ?? null)) self::invalid();
                if (!hash_equals($record['key'], $key)) continue;
                if (($record['scope'] ?? null) !== $scope || ($record['task_id'] ?? null) !== $taskId
                    || !is_string($record['fingerprint'] ?? null) || !hash_equals($record['fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Channel recovery intent changed');
                }
                $revalidate($task, $current);
                return (new TaskGateway())->summary($task, true);
            }
            $parent = TaskStep::where('task_id', $taskId)->where('sequence_no', 1)->where('step_code', 'agent_run')
                ->whereIn('state', [TaskState::FAILED, TaskState::RETRYING])->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($parent === null) self::invalid();
            $parentContext = self::parentContext($parent);
            $resumeClaim = self::pausedClaim($task, $parentContext) ?? self::pendingClaim($task, $parentContext);
            if (!in_array((string) $task->state, [TaskState::FAILED, TaskState::RECOVERY_PENDING], true)
                || (int) $task->version !== $target['expected_task_version']
                || (int) $task->lease_fence !== $target['expected_lease_fence']
                || ((int) $task->local_recovery_count >= (int) $task->local_recovery_max && $resumeClaim === null)
                || TaskRun::where('id', $taskId)->whereRaw(
                    'lease_expire_time > (clock_timestamp() AT TIME ZONE ?)',
                    [\app\Application\Task\TaskLeasePolicy::runningTimezone()],
                )->find() !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Channel receipt recovery changed or is unavailable');
            }
            if (($this->projection($task)['available'] ?? false) !== true) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original channel recovery intent is still active or unavailable');
            }
            $requests = self::originalModelRequests($task);
            $checkpoint = (new AgentContinuationGateway())->checkpointStore($task)->inspectMetadata(AgentGateway::checkpointWorkflowId($task));
            if (!is_array($checkpoint) || ($checkpoint['checkpoint_available'] ?? false) !== true
                || ($checkpoint['run_id'] ?? null) !== AgentGateway::checkpointRunId($task)
                || (!in_array($checkpoint['state'] ?? null, ['failed', 'completed'], true)
                    && !(($checkpoint['state'] ?? null) === 'running' && is_int($checkpoint['lease_expires_at'] ?? null)
                        && $checkpoint['lease_expires_at'] <= time()))) self::pending();
            $record = ['version' => self::VERSION, 'key' => $key, 'scope' => $scope, 'task_id' => $taskId,
                'fingerprint' => $fingerprint, 'original_request_id' => (string) $task->request_id,
                'previous_task_version' => (int) $task->version, 'previous_lease_fence' => (int) $task->lease_fence,
                'lease_fence' => (int) $task->lease_fence + 1, 'admitted_at' => gmdate('Y-m-d H:i:s'),
                'authorization' => $current, 'model_requests' => $requests,
                'caller_intent_sha256' => $target['caller_intent_sha256'],
                'previous_task_state' => (string) $task->state,
                'previous_task_error_code' => $task->error_code, 'previous_task_error_summary' => $task->error_summary,
                'previous_parent_state' => (string) $parent->state,
                'previous_parent_error_code' => $parent->error_code, 'previous_parent_error_summary' => $parent->error_summary,
                'previous_parent_authorization_continuation' => $parentContext['authorization_continuation'] ?? null,
                'previous_parent_channel_receipt_authorization' => $parentContext['channel_receipt_authorization'] ?? null,
                'resume_claim' => $resumeClaim,
                'previous_steps' => TaskStep::where('task_id', $taskId)->order('sequence_no')->select()->map(
                    static fn (TaskStep $step): array => ['id' => (int) $step->id, 'code' => (string) $step->step_code,
                        'state' => (string) $step->state, 'attempt_count' => (int) $step->attempt_count,
                        'error_code' => $step->error_code, 'error_summary' => $step->error_summary,
                        'started_at' => $step->started_at, 'completed_at' => $step->completed_at],
                )->toArray()];
            // Keep the original queued envelope and business input intact.
            $input['_channel_receipt_recoveries'] = [...$records, $record];
            $input['_channel_receipt_recovery'] = $record;
            $revalidate($task, $current);
            self::originalModelRequests($task);
            $updated = TaskRun::where('id', $taskId)->where('version', (int) $task->version)
                ->where('lease_fence', (int) $task->lease_fence)->where('state', (string) $task->state)->update([
                    'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'state' => TaskState::RECOVERY_PENDING, 'available_at' => date('Y-m-d H:i:s'),
                    'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null,
                    'locked_at' => null, 'locked_by' => null, 'heartbeat_time' => null,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'version' => Db::raw('version + 1'),
                ]);
            if ($updated !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Channel recovery task changed');
            if (is_array($parentContext['authorization_continuation'] ?? null)) {
                $parentContext['authorization_continuation']['required'] = false;
            }
            $parentContext['channel_receipt_authorization'] = [
                'version' => self::AUTHORIZATION_VERSION, 'required' => false, 'intent_key' => $key,
                'original_request_id' => (string) $task->request_id, 'task_attempt' => (int) $task->attempt_count,
                'admission_fence' => (int) $task->lease_fence + 1, 'resume_pending' => $resumeClaim !== null,
                'resume_claim' => $resumeClaim,
            ];
            $parent->save(['context' => $parentContext]);
            AuditLog::create(['environment_id' => $environmentId, 'actor_type' => 'workload',
                'actor_ref' => (string) $task->workload_client_id, 'action' => 'channel.receipt_recovery.accepted',
                'resource_type' => 'task', 'resource_id' => $taskId, 'summary' => 'Original channel receipt recovery accepted',
                'context' => ['intent_key' => $key, 'fingerprint' => $fingerprint,
                    'original_request_id' => (string) $task->request_id, 'previous_task_version' => (int) $task->version,
                    'previous_lease_fence' => (int) $task->lease_fence, 'provider_reissued' => false]]);
            $currentTask = TaskRun::where('id', $taskId)->find();
            if ($currentTask === null) self::invalid();
            $revalidate($currentTask, $current);
            return (new TaskGateway())->summary($currentTask, true);
        });
    }

    public static function receiptOnly(TaskRun $task): bool
    {
        return (string) $task->task_type === 'agent_run' && is_array($task->input)
            && isset($task->input['_channel'], $task->input['_channel_receipt_recovery']);
    }

    /** Required authorization stops automatic claims; an explicit permit can reuse one charged slot. */
    public static function authorizationClaimSql(): string
    {
        return <<<'SQL'
(NOT (task_type = 'agent_run' AND pg_catalog.jsonb_exists(input, '_channel') AND pg_catalog.jsonb_exists(input, '_channel_receipt_recovery')) OR (
    input->'_channel_receipt_recovery'->>'version' = 'channel_receipt_recovery@1'
    AND input->'_channel_receipt_recovery'->'task_id' = to_jsonb(sand_ai_task_run.id)
    AND input->'_channel_receipt_recovery'->>'original_request_id' = sand_ai_task_run.request_id
    AND NOT EXISTS (SELECT 1 FROM sand_ai_task_step channel_wait
        WHERE channel_wait.task_id = sand_ai_task_run.id AND channel_wait.sequence_no = 1
        AND channel_wait.step_code = 'agent_run' AND channel_wait.status = 1 AND channel_wait.delete_time IS NULL
        AND channel_wait.context->'channel_receipt_authorization'->'required' = 'true'::jsonb)
    AND (local_recovery_count < local_recovery_max OR EXISTS (
        SELECT 1 FROM sand_ai_task_step channel_resume
        WHERE channel_resume.task_id = sand_ai_task_run.id AND channel_resume.sequence_no = 1
        AND channel_resume.step_code = 'agent_run' AND channel_resume.status = 1 AND channel_resume.delete_time IS NULL
        AND channel_resume.state IN ('failed', 'retrying')
        AND channel_resume.context->'channel_receipt_authorization'->>'version' = 'channel_receipt_authorization@1'
        AND channel_resume.context->'channel_receipt_authorization'->'required' = 'false'::jsonb
        AND channel_resume.context->'channel_receipt_authorization'->'resume_pending' = 'true'::jsonb
        AND channel_resume.context->'channel_receipt_authorization'->>'intent_key' = input->'_channel_receipt_recovery'->>'key'
        AND channel_resume.context->'channel_receipt_authorization'->>'original_request_id' = sand_ai_task_run.request_id
        AND channel_resume.context->'channel_receipt_authorization'->'task_attempt' = to_jsonb(sand_ai_task_run.attempt_count)
        AND channel_resume.context->'channel_receipt_authorization'->'admission_fence' = to_jsonb(sand_ai_task_run.lease_fence)
        AND channel_resume.context->'channel_receipt_authorization'->'resume_claim' = input->'_channel_receipt_recovery'->'resume_claim'
        AND input->'_channel_receipt_recovery'->'resume_claim'->'local_recovery_count' = to_jsonb(sand_ai_task_run.local_recovery_count)
        AND input->'_channel_receipt_recovery'->'resume_claim'->'local_recovery_max' = to_jsonb(sand_ai_task_run.local_recovery_max)
        AND input->'_channel_receipt_recovery'->'resume_claim'->'task_attempt' = to_jsonb(sand_ai_task_run.attempt_count)
        AND local_recovery_count > 0 AND local_recovery_count <= local_recovery_max
    ))
))
SQL;
    }

    /** Called with the original task locked, before the fenced task and parent updates. */
    public static function claimAuthorization(TaskRun $task): array
    {
        if (!self::receiptOnly($task)) self::invalid();
        $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('step_code', 'agent_run')
            ->whereIn('state', [TaskState::FAILED, TaskState::RETRYING])->where('status', 1)->whereNull('delete_time')->lock(true)->find();
        if ($parent === null) self::invalid();
        $context = self::parentContext($parent);
        $record = self::currentRecord($task);
        $marker = $context['channel_receipt_authorization'] ?? null;
        if ($marker !== null && (!is_array($marker) || ($marker['version'] ?? null) !== self::AUTHORIZATION_VERSION
            || ($marker['required'] ?? null) !== false || ($marker['intent_key'] ?? null) !== $record['key']
            || ($marker['original_request_id'] ?? null) !== (string) $task->request_id
            || ($marker['task_attempt'] ?? null) !== (int) $task->attempt_count)) self::invalid();
        $resume = self::pendingClaim($task, $context);
        if (($marker['resume_pending'] ?? false) === true && $resume === null) self::invalid();
        if ((int) $task->local_recovery_count >= (int) $task->local_recovery_max && $resume === null) self::invalid();
        $context['channel_receipt_authorization'] = [
            'version' => self::AUTHORIZATION_VERSION, 'required' => false, 'intent_key' => $record['key'],
            'original_request_id' => (string) $task->request_id, 'task_attempt' => (int) $task->attempt_count,
            'admission_fence' => $record['lease_fence'], 'resume_pending' => false,
            'claimed_local_recovery_count' => (int) $task->local_recovery_count + ($resume === null ? 1 : 0),
            'claimed_local_recovery_max' => (int) $task->local_recovery_max,
            'claimed_lease_fence' => (int) $task->lease_fence + 1,
            'resume_claim' => $resume,
        ];
        return ['context' => $context, 'reuse_claim' => $resume !== null];
    }

    /** Only the current fenced claim can enter the channel authorization waiting state. */
    public static function pauseAuthorizationContext(TaskRun $task, array $context, string $code): array
    {
        if (!TaskAuthorizationContinuation::authorizationFailure($code) || !self::claimed($task, $context)) self::invalid();
        $context['channel_receipt_authorization']['required'] = true;
        $context['channel_receipt_authorization']['resume_pending'] = false;
        $context['channel_receipt_authorization']['error_code'] = $code;
        $context['channel_receipt_authorization']['paused_at'] = gmdate('Y-m-d H:i:s');
        return $context;
    }

    private static function parentContext(TaskStep $parent): array
    {
        $context = is_object($parent->context) ? (array) $parent->context : $parent->context;
        if (!is_array($context)) self::invalid();
        return $context;
    }

    private static function currentRecord(TaskRun $task): array
    {
        $record = is_array($task->input) ? ($task->input['_channel_receipt_recovery'] ?? null) : null;
        if (!self::receiptOnly($task) || !is_array($record) || ($record['version'] ?? null) !== self::VERSION
            || ($record['task_id'] ?? null) !== (int) $task->id
            || ($record['original_request_id'] ?? null) !== (string) $task->request_id
            || !is_string($record['key'] ?? null) || $record['key'] === ''
            || !is_int($record['lease_fence'] ?? null) || $record['lease_fence'] > (int) $task->lease_fence) self::invalid();
        return $record;
    }

    private static function charged(TaskRun $task, array $context): bool
    {
        $record = is_array($task->input) ? ($task->input['_channel_receipt_recovery'] ?? null) : null;
        $marker = $context['channel_receipt_authorization'] ?? null;
        return self::receiptOnly($task) && is_array($record) && ($record['version'] ?? null) === self::VERSION
            && ($record['task_id'] ?? null) === (int) $task->id
            && ($record['original_request_id'] ?? null) === (string) $task->request_id
            && is_string($record['key'] ?? null) && is_array($marker)
            && ($marker['version'] ?? null) === self::AUTHORIZATION_VERSION
            && ($marker['intent_key'] ?? null) === $record['key']
            && ($marker['original_request_id'] ?? null) === (string) $task->request_id
            && ($marker['task_attempt'] ?? null) === (int) $task->attempt_count
            && ($marker['claimed_local_recovery_count'] ?? null) === (int) $task->local_recovery_count
            && ($marker['claimed_local_recovery_max'] ?? null) === (int) $task->local_recovery_max
            && ($marker['claimed_lease_fence'] ?? null) === (int) $task->lease_fence
            && (int) $task->local_recovery_count > 0
            && (int) $task->local_recovery_count <= (int) $task->local_recovery_max;
    }

    private static function claimed(TaskRun $task, array $context): bool
    {
        return self::charged($task, $context)
            && ($context['channel_receipt_authorization']['required'] ?? null) === false
            && ($context['channel_receipt_authorization']['resume_pending'] ?? null) === false;
    }

    /** The last slot remains renewable only when this exact claim stopped at an authorization gate. */
    private static function pausedClaim(TaskRun $task, array $context): ?array
    {
        $marker = $context['channel_receipt_authorization'] ?? null;
        if (!in_array((string) $task->state, [TaskState::FAILED, TaskState::RECOVERY_PENDING], true)
            || !self::charged($task, $context) || ($marker['required'] ?? null) !== true
            || ($marker['resume_pending'] ?? null) !== false
            || !is_string($marker['error_code'] ?? null)
            || !TaskAuthorizationContinuation::authorizationFailure($marker['error_code'])) return null;
        return ['intent_key' => $marker['intent_key'], 'original_request_id' => (string) $task->request_id,
            'task_attempt' => (int) $task->attempt_count, 'local_recovery_count' => (int) $task->local_recovery_count,
            'local_recovery_max' => (int) $task->local_recovery_max, 'lease_fence' => (int) $task->lease_fence];
    }

    /** An unclaimed renewed authorization may be replaced after expiry without spending the charged slot. */
    private static function pendingClaim(TaskRun $task, array $context): ?array
    {
        $marker = $context['channel_receipt_authorization'] ?? null;
        $record = is_array($task->input) ? ($task->input['_channel_receipt_recovery'] ?? null) : null;
        $resume = is_array($record) ? ($record['resume_claim'] ?? null) : null;
        if (!self::receiptOnly($task) || !is_array($marker) || !is_array($resume)
            || ($record['version'] ?? null) !== self::VERSION
            || ($record['task_id'] ?? null) !== (int) $task->id
            || ($record['original_request_id'] ?? null) !== (string) $task->request_id
            || ($record['lease_fence'] ?? null) !== (int) $task->lease_fence
            || ($marker['version'] ?? null) !== self::AUTHORIZATION_VERSION
            || ($marker['required'] ?? null) !== false || ($marker['resume_pending'] ?? null) !== true
            || ($marker['intent_key'] ?? null) !== ($record['key'] ?? null)
            || ($marker['original_request_id'] ?? null) !== (string) $task->request_id
            || ($marker['task_attempt'] ?? null) !== (int) $task->attempt_count
            || ($marker['admission_fence'] ?? null) !== (int) $task->lease_fence
            || ($marker['resume_claim'] ?? null) !== $resume
            || ($resume['original_request_id'] ?? null) !== (string) $task->request_id
            || ($resume['task_attempt'] ?? null) !== (int) $task->attempt_count
            || ($resume['local_recovery_count'] ?? null) !== (int) $task->local_recovery_count
            || ($resume['local_recovery_max'] ?? null) !== (int) $task->local_recovery_max
            || !is_string($resume['intent_key'] ?? null) || !is_int($resume['lease_fence'] ?? null)
            || $resume['lease_fence'] >= (int) $task->lease_fence
            || (int) $task->local_recovery_count < 1
            || (int) $task->local_recovery_count > (int) $task->local_recovery_max) return null;
        $records = $task->input['_channel_receipt_recoveries'] ?? [];
        $originalIntent = false;
        $pausedProof = false;
        foreach (is_array($records) ? $records : [] as $previous) {
            if (is_array($previous) && ($previous['version'] ?? null) === self::VERSION
                && ($previous['task_id'] ?? null) === (int) $task->id
                && ($previous['original_request_id'] ?? null) === (string) $task->request_id
                && ($previous['key'] ?? null) === $resume['intent_key']) $originalIntent = true;
            $proof = is_array($previous) ? ($previous['previous_parent_channel_receipt_authorization'] ?? null) : null;
            if (is_array($previous) && ($previous['version'] ?? null) === self::VERSION
                && ($previous['task_id'] ?? null) === (int) $task->id
                && ($previous['original_request_id'] ?? null) === (string) $task->request_id
                && ($previous['resume_claim'] ?? null) === $resume && is_array($proof)
                && ($proof['version'] ?? null) === self::AUTHORIZATION_VERSION
                && ($proof['required'] ?? null) === true && ($proof['resume_pending'] ?? null) === false
                && ($proof['intent_key'] ?? null) === $resume['intent_key']
                && ($proof['original_request_id'] ?? null) === $resume['original_request_id']
                && ($proof['task_attempt'] ?? null) === $resume['task_attempt']
                && ($proof['claimed_local_recovery_count'] ?? null) === $resume['local_recovery_count']
                && ($proof['claimed_local_recovery_max'] ?? null) === $resume['local_recovery_max']
                && ($proof['claimed_lease_fence'] ?? null) === $resume['lease_fence']
                && is_string($proof['error_code'] ?? null)
                && TaskAuthorizationContinuation::authorizationFailure($proof['error_code'])) $pausedProof = true;
        }
        return $originalIntent && $pausedProof ? $resume : null;
    }

    /** Original running/dispatching ledgers are admissible only with an already sealed outcome. */
    public static function originalModelRequests(TaskRun $task): array
    {
        $input = $task->input;
        if (!is_array($input) || !is_array($input['_channel'] ?? null)
            || ($input['_agent_neuron'] ?? null) !== true || ($input['_agent_checkpoint_version'] ?? null) !== 1
            || isset($input['_agent_continuation'])) self::invalid();
        (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $input['_channel']);
        (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $input['_agent_definition'] ?? null);
        (new AgentFileUsageGateway())->assertRetryableSources((int) $task->environment_id, (int) $task->id);
        $requests = [];
        $maximum = empty($input['_agent_readonly_tools']) ? 3 : 4;
        foreach (InvocationDispatch::where('parent_task_run_id', (int) $task->id)->select() as $dispatch) {
            $node = (string) $dispatch->workflow_node_code;
            if ((string) $dispatch->execution_scope === 'embedding') {
                // A checkpoint can reuse its sealed retrieval; it cannot resume an unknown embedding.
                $embedding = Invocation::where('id', (int) $dispatch->invocation_id)
                    ->where('environment_id', (int) $task->environment_id)->where('status', 1)->whereNull('delete_time')->find();
                if ((int) $dispatch->environment_id !== (int) $task->environment_id || $embedding === null
                    || (string) $embedding->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded'
                    || (int) $embedding->organization_id !== (int) $task->organization_id
                    || (int) $embedding->application_id !== (int) $task->application_id
                    || (int) $embedding->workload_client_id !== (int) $task->workload_client_id
                    || (int) $embedding->model_id !== (int) $dispatch->model_id
                    || (int) $embedding->deployment_id !== (int) $dispatch->deployment_id) self::pending();
                continue;
            }
            if ((int) $dispatch->environment_id !== (int) $task->environment_id
                || (string) $dispatch->execution_scope !== 'agent' || (string) $dispatch->capability_code !== 'chat'
                || ($node !== 'agent_summary_1' && !in_array($node, array_map(
                    static fn (int $round): string => 'agent_round_' . $round, range(1, $maximum)), true))
                || isset($requests[$node])) self::pending();
            $invocation = Invocation::where('id', (int) $dispatch->invocation_id)->where('environment_id', (int) $task->environment_id)
                ->where('organization_id', (int) $task->organization_id)->where('application_id', (int) $task->application_id)
                ->where('workload_client_id', (int) $task->workload_client_id)->where('status', 1)->whereNull('delete_time')->find();
            if ($invocation === null || (int) $invocation->model_id !== (int) $dispatch->model_id
                || (int) $invocation->deployment_id !== (int) $dispatch->deployment_id
                || !in_array([(string) $invocation->state, (string) $dispatch->state],
                    [['succeeded', 'succeeded'], ['running', 'dispatching']], true)) self::pending();
            $facts = $dispatch->toArray();
            $facts['request_id'] = (string) $invocation->request_id;
            AgentGateway::assertModelDispatchIdentity($task, $facts);
            $receipt = InvocationOutcomeReceipt::where('invocation_id', (int) $invocation->id)
                ->where('environment_id', (int) $task->environment_id)->where('capability_code', 'agent_tool')
                ->where('state', 'available')->where('expire_time', '>', gmdate('Y-m-d H:i:s'))->find();
            if ($receipt === null || !is_string($invocation->request_fingerprint)
                || !hash_equals((string) $receipt->request_fingerprint, $invocation->request_fingerprint)
                || (int) $receipt->model_id !== (int) $invocation->model_id
                || (int) $receipt->deployment_id !== (int) $invocation->deployment_id) self::pending();
            (new \app\Application\Gateway\ChatGateway())->verifyAgentReceipt($invocation);
            $requests[$node] = ['request_id' => (string) $invocation->request_id,
                'attempt_no' => (int) $dispatch->attempt_no, 'basis_invocation_id' => (int) $invocation->id];
            if ($node === 'agent_summary_1') {
                $step = TaskStep::where('id', (int) $dispatch->parent_task_step_id)->find();
                $coverage = $step === null ? null : ($step->context['coverage']['coverage_sha256'] ?? null);
                if (!is_string($coverage)) self::invalid();
                $requests[$node]['coverage_sha256'] = $coverage;
            }
        }
        if ($requests === []) self::pending();
        foreach (ToolRun::where('parent_task_run_id', (int) $task->id)->select() as $run) {
            $attempt = ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->find();
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->where('state', 'available')
                ->where('expire_time', '>', gmdate('Y-m-d H:i:s'))->find();
            if ((string) $run->state !== 'succeeded' || $attempt === null || (string) $attempt->state !== 'succeeded'
                || $receipt === null) self::pending();
            (new \app\Application\Tool\ToolRunGateway())->assertAgentReceiptAvailable($task, $run);
        }
        ksort($requests);
        return $requests;
    }

    /** Structural permission only; callers must separately authorize current owner/source reads. */
    public function projection(TaskRun $task): ?array
    {
        if ((string) $task->task_type !== 'agent_run' || !is_array($task->input) || !isset($task->input['_channel'])) return null;
        $reason = null;
        $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('step_code', 'agent_run')
            ->where('status', 1)->whereNull('delete_time')->find();
        $parentContext = $parent !== null ? self::parentContext($parent) : [];
        $resumeClaim = self::pausedClaim($task, $parentContext) ?? self::pendingClaim($task, $parentContext);
        if ($task->content_purged_time !== null || $task->delete_time !== null || (int) $task->status !== 1) {
            $reason = 'source_unavailable';
        } elseif (!in_array((string) $task->state, [TaskState::FAILED, TaskState::RECOVERY_PENDING], true)) {
            $reason = 'task_not_recoverable';
        } elseif ((int) $task->local_recovery_count >= (int) $task->local_recovery_max && $resumeClaim === null) {
            $reason = 'local_recovery_exhausted';
        } elseif (TaskRun::where('id', (int) $task->id)->whereRaw(
            'lease_expire_time > (clock_timestamp() AT TIME ZONE ?)',
            [\app\Application\Task\TaskLeasePolicy::runningTimezone()],
        )->find() !== null) {
            $reason = 'lease_active';
        } else {
            try {
                $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('step_code', 'agent_run')
                    ->whereIn('state', [TaskState::FAILED, TaskState::RETRYING])->where('status', 1)->whereNull('delete_time')->find();
                if ($parent === null) self::invalid();
                self::originalModelRequests($task);
                $checkpoint = (new AgentContinuationGateway())->checkpointStore($task)->inspectMetadata(AgentGateway::checkpointWorkflowId($task));
                if (!is_array($checkpoint) || ($checkpoint['checkpoint_available'] ?? false) !== true
                    || ($checkpoint['run_id'] ?? null) !== AgentGateway::checkpointRunId($task)
                    || (!in_array($checkpoint['state'] ?? null, ['failed', 'completed'], true)
                        && !(($checkpoint['state'] ?? null) === 'running' && is_int($checkpoint['lease_expires_at'] ?? null)
                            && $checkpoint['lease_expires_at'] <= time()))) $reason = 'checkpoint_unavailable';
            } catch (ApiProblem $problem) {
                $reason = in_array($problem->errorCode, ['SAND_AI_RESOURCE_NOT_FOUND', 'SAND_AI_SCOPE_SNAPSHOT_INVALID'], true)
                    ? 'source_or_snapshot_unavailable' : 'receipt_unavailable';
            } catch (\Throwable) { $reason = 'receipt_unavailable'; }
        }
        $record = $task->input['_channel_receipt_recovery'] ?? null;
        $accepted = is_array($record) && ($record['version'] ?? null) === self::VERSION
            && ($record['task_id'] ?? null) === (int) $task->id
            && ($record['original_request_id'] ?? null) === (string) $task->request_id
            && is_string($record['caller_intent_sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', $record['caller_intent_sha256']) === 1
            && is_int($record['previous_task_version'] ?? null)
            && is_int($record['previous_lease_fence'] ?? null);
        $authorizationExpiresAt = $accepted ? ($record['authorization']['task_authorization']['expires_at'] ?? null) : null;
        $requiresCurrentAuthorization = ($parentContext['channel_receipt_authorization']['required'] ?? false) === true
            || ($parentContext['authorization_continuation']['required'] ?? false) === true;
        if ($accepted && $reason === null && (string) $task->state === TaskState::RECOVERY_PENDING
            && !$requiresCurrentAuthorization && is_int($authorizationExpiresAt) && $authorizationExpiresAt > time()) {
            $reason = 'intent_accepted';
        }
        return ['mode' => 'receipt_only', 'available' => $reason === null, 'reason' => $reason,
            'provider_reissued' => false, 'expected_task_version' => (int) $task->version,
            'expected_lease_fence' => (int) $task->lease_fence,
            'accepted' => $accepted ? ['intent_key_sha256' => $record['caller_intent_sha256'],
                'previous_task_version' => $record['previous_task_version'],
                'previous_lease_fence' => $record['previous_lease_fence']] : null];
    }

    /** Fresh decisions are checked independently of the historical queued envelope. */
    public static function revalidateExecution(TaskRun $task): void
    {
        $record = $task->input['_channel_receipt_recovery'] ?? null;
        if (!is_array($record) || ($record['version'] ?? null) !== self::VERSION
            || ($record['task_id'] ?? null) !== (int) $task->id
            || ($record['original_request_id'] ?? null) !== (string) $task->request_id
            || !is_int($record['lease_fence'] ?? null) || (int) $task->lease_fence < $record['lease_fence']
            || !is_array($record['authorization']['task_authorization'] ?? null)) self::invalid();
        $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->where('step_code', 'agent_run')
            ->where('state', TaskState::RUNNING)->where('status', 1)->whereNull('delete_time')->find();
        if ($parent === null || !self::claimed($task, self::parentContext($parent))) self::invalid();
        (new TaskInputAccessGuard())->assert($task, $record['scope'] ?? null);
        $queued = (new TaskRunAccessGuard())->revalidateQueued($record['authorization']['task_authorization'], $task);
        $provider = new HostSandIamIdentityContextProvider();
        $authorizer = new HostSandIamInvocationAuthorizer();
        foreach (['read_decision' => ['sand_ai.task.read', 'sand_ai.task_operation'],
            'retry_decision' => ['sand_ai.task.retry', 'sand_ai.task_operation'],
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
        if (!RequestPayloadFingerprint::same(self::originalModelRequests($task), $record['model_requests'] ?? [])) self::invalid();
    }

    private static function pending(): never
    {
        throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original channel outcome is not locally recoverable');
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel receipt recovery facts are unavailable');
    }
}
