<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskState;
use app\Application\Task\TaskWorkerIdentity;
use app\Application\Tool\ToolRunGateway;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\QueuedSandIamContextCipher;
use app\Identity\Infrastructure\QueuedSandIamTaskBinding;
use app\Identity\Infrastructure\TaskInvocationFacts;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Provider\AgentToolProviderOutcome;
use think\facade\Db;

/** Seals failure after actual execution-context expiry; never continues an Agent or changes its receipts or accounting. */
final class AgentExpiredReceiptFinalizer
{
    /** Process-local pagination survives the short-lived gateway objects, not a task or authorization mutation. */
    private static int $scanAfterId = 0;

    public function runOne(string $workerId): bool
    {
        TaskWorkerIdentity::assertValid($workerId);
        return Db::connect('pgsql')->transaction(function () use ($workerId): bool {
            $rows = Db::connect('pgsql')->query(
                "SELECT id FROM sand_ai_task_run
                 WHERE task_type='agent_run' AND state='recovery_pending'
                   AND input->>'_agent_neuron'='true' AND NOT jsonb_exists(input,'_channel')
                   AND CASE WHEN input->'_agent_continuation' IS NULL OR input->'_agent_continuation'='null'::jsonb
                       THEN CASE WHEN jsonb_typeof(input#>'{iam_authorization,expires_at}')='number'
                           THEN (input#>>'{iam_authorization,expires_at}')::numeric<=extract(epoch FROM clock_timestamp()) ELSE false END
                       ELSE jsonb_typeof(input->'_agent_continuation')='object' AND
                           CASE WHEN jsonb_typeof(input#>'{_agent_continuation,authorization,task_authorization,expires_at}')='number'
                               THEN (input#>>'{_agent_continuation,authorization,task_authorization,expires_at}')::numeric<=extract(epoch FROM clock_timestamp()) ELSE false END END
                   AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL
                   AND (lease_expire_time IS NULL OR lease_expire_time<=(clock_timestamp() AT TIME ZONE ?))
                   AND id>?
                 ORDER BY id LIMIT 5 FOR UPDATE SKIP LOCKED",
                [\app\Application\Task\TaskLeasePolicy::runningTimezone(), self::$scanAfterId],
            );
            // Unknown receipts remain held, but cannot permanently occupy the first scan page.
            if ($rows === []) {
                self::$scanAfterId = 0;
                return false;
            }
            $task = null;
            $proof = null;
            foreach ($rows as $row) {
                self::$scanAfterId = (int) $row['id'];
                $candidate = TaskRun::find((int) $row['id']);
                if ($candidate === null) continue;
                $candidateProof = $this->expiredKnownReceiptProof($candidate);
                if ($candidateProof === null) continue;
                $task = $candidate;
                $proof = $candidateProof;
                break;
            }
            if ($task === null || $proof === null) return false;
            foreach ($proof['tool_receipts'] as $child) {
                $step = TaskStep::where('id', $child['step_id'])->where('task_id', (int) $task->id)->find();
                $run = ToolRun::where('id', $child['run_id'])->where('parent_task_run_id', (int) $task->id)
                    ->where('parent_task_step_id', $child['step_id'])->find();
                if ($step === null || $run === null) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Known Agent tool parent changed');
                }
                // The parent row is already locked. The service seals failure metadata
                // without issuing IAM, sending requests, inserting usage or materializing receipt text.
                $sealed = (new ToolRunGateway())->finalizeAgentKnownReceipt($task, $step, $run);
                foreach (['proof_kind', 'run_id', 'step_id', 'receipt_id', 'state', 'finalization_state', 'usage_event_id', 'quantity'] as $field) {
                    if (!array_key_exists($field, $sealed) || $sealed[$field] !== $child[$field]) {
                        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Known Agent tool receipt changed');
                    }
                }
                $finalRun = ToolRun::where('id', $child['run_id'])->find();
                $finalStep = TaskStep::where('id', $child['step_id'])->where('task_id', (int) $task->id)->find();
                $stepWasTerminal = in_array((string) $step->state, [TaskState::SUCCEEDED, TaskState::FAILED, TaskState::CANCELED], true);
                $expectedStepState = $stepWasTerminal ? (string) $step->state : TaskState::FAILED;
                if ($finalRun === null || $finalStep === null || (string) $finalRun->state !== $child['finalization_state']
                    || (string) $finalStep->state !== $expectedStepState
                    || ((string) $run->state === TaskState::RUNNING
                        && ($finalRun->result !== null || ($finalRun->citations ?? []) !== []))) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Known Agent tool failure finalization changed');
                }
                if ($stepWasTerminal) {
                    foreach (['completed_at', 'error_code', 'error_summary'] as $field) {
                        if ($finalStep->getAttr($field) !== $step->getAttr($field)) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Terminal Agent tool step changed');
                        }
                    }
                    if (!RequestPayloadFingerprint::same(['context' => $finalStep->context], ['context' => $step->context])) {
                        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Terminal Agent tool step changed');
                    }
                }
            }
            $now = date('Y-m-d H:i:s');
            $code = 'SAND_AI_IDENTITY_CONTEXT_EXPIRED';
            $summary = 'Execution authorization expired; known provider receipts and measured fees retained';
            $changed = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('version', (int) $task->version)->update([
                    'state' => TaskState::FAILED, 'error_code' => $code, 'error_summary' => $summary,
                    'completed_at' => $now, 'locked_at' => null, 'locked_by' => null,
                    'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null,
                    'heartbeat_time' => null, 'lease_fence' => Db::raw('lease_fence + 1'),
                    'version' => Db::raw('version + 1'),
                ]);
            if ($changed !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent failure finalization lost its task');
            $steps = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_task_step SET state='failed',error_code=?,error_summary=?,completed_at=?,
                     update_time=CURRENT_TIMESTAMP(0)
                 WHERE task_id=? AND sequence_no=1 AND state='failed' AND status=1 AND delete_time IS NULL",
                [$code, $summary, $now, (int) $task->id],
            );
            if ($steps !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent failure finalization lost its parent step');
            Db::connect('pgsql')->execute(
                "UPDATE sand_ai_task_step SET state='failed',error_code=?,error_summary=?,completed_at=?,
                     update_time=CURRENT_TIMESTAMP(0)
                 WHERE task_id=? AND sequence_no>1 AND state IN ('authorization_pending','queued','retrying')
                   AND status=1 AND delete_time IS NULL",
                [$code, $summary, $now, (int) $task->id],
            );
            AuditLog::create([
                'actor_type' => 'system_worker', 'actor_ref' => (string) $task->environment_id,
                'action' => 'task.agent_expired_receipt_failed', 'resource_type' => 'task',
                'resource_id' => (int) $task->id, 'summary' => 'Expired Agent authorization denied; sealed accounting retained',
                'context' => ['task_type' => 'agent_run', 'worker_id' => $workerId,
                    'receipt_count' => $proof['receipt_count'], 'usage_event_ids' => $proof['usage_event_ids'],
                    ...($proof['tool_receipts'] === [] ? [] : [
                        'tool_receipt_count' => count(array_filter($proof['tool_receipts'],
                            static fn (array $child): bool => $child['proof_kind'] === 'sealed_receipt')),
                        'tool_child_count' => count($proof['tool_receipts']),
                        'tool_run_ids' => array_column($proof['tool_receipts'], 'run_id'),
                        'tool_usage_event_ids' => array_values(array_filter(array_column($proof['tool_receipts'], 'usage_event_id'),
                            static fn (mixed $id): bool => is_int($id))),
                        'tool_never_dispatched_run_ids' => array_values(array_map(
                            static fn (array $child): int => $child['run_id'],
                            array_filter($proof['tool_receipts'], static fn (array $child): bool => $child['proof_kind'] === 'never_dispatched'))),
                    ]),
                    'authorization_denied' => true, 'provider_reissued' => false, 'receipt_retained' => true],
            ]);
            return true;
        });
    }

    /** Read-only proof used while the caller holds the task lock. Returns metadata only, never receipt text. */
    public function expiredKnownReceiptProof(TaskRun $task): ?array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if ((string) $task->task_type !== 'agent_run' || (string) $task->state !== TaskState::RECOVERY_PENDING
            || !is_array($input) || ($input['_agent_neuron'] ?? null) !== true || array_key_exists('_channel', $input)
            || !is_array($input['iam_authorization'] ?? null) || $task->result !== null
            || $task->content_purged_time !== null || (int) $task->status !== 1 || $task->delete_time !== null) return null;
        $live = Db::connect('pgsql')->query(
            "SELECT 1 FROM sand_ai_task_run
             WHERE id=? AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)
             UNION ALL SELECT 1 FROM sand_ai_task_step WHERE task_id=?
                 AND state='running' AND step_code NOT IN ('agent_managed_tool_1','agent_managed_tool_2')
                 AND step_code !~ '^agent_tool_continue_[a-f0-9]{32}$' LIMIT 1",
            [(int) $task->id, \app\Application\Task\TaskLeasePolicy::runningTimezone(), (int) $task->id],
        );
        if ($live !== []) return null;
        try {
            $maximumRound = $this->maximumRound($input);
            TaskInvocationFacts::fromTask($task, 'sand_ai.agent.submit');
            $authorization = AgentContinuationGateway::executionAuthorization($task);
            if (($authorization['version'] ?? null) !== 1 || ($authorization['task_type'] ?? null) !== 'agent_run'
                || !is_int($authorization['authorization_id'] ?? null)
                || !is_string($authorization['source_ip'] ?? null)
                || !is_string($authorization['request_id'] ?? null)
                || !is_int($authorization['expires_at'] ?? null)
                || !is_array($authorization['context'] ?? null)) return null;
            $binding = new QueuedSandIamTaskBinding((int) $task->id, 'agent_run',
                $authorization['authorization_id'], $authorization['source_ip'],
                $authorization['request_id'], $authorization['expires_at']);
            (new QueuedSandIamContextCipher((array) config('sand_ai_iam_context', [])))->assertExpiredAuthentic(
                $authorization['context'], $binding);
            (new TaskRunAccessGuard())->revalidateQueued($authorization, $task);
            return null;
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_IDENTITY_CONTEXT_EXPIRED') return null;
        } catch (IdentityContextException) {
            return null;
        }
        $rows = Db::connect('pgsql')->query(
            "SELECT d.id dispatch_id,d.state dispatch_state,d.execution_scope,d.capability_code,d.workflow_node_code,
                    i.id invocation_id,i.state invocation_state,i.environment_id,i.request_fingerprint,
                    d.environment_id dispatch_environment_id,d.parent_task_run_id,d.parent_task_step_id,d.attempt_no,
                    d.logical_request_id,d.logical_execution_id,d.execution_snapshot_sha256,
                    d.application_id dispatch_application_id,d.service_action,d.source_kind,
                    d.provider_id dispatch_provider_id,d.model_id dispatch_model_id,d.deployment_id dispatch_deployment_id,
                    d.price_snapshot,d.dispatch_start_time,d.dispatch_complete_time,
                    i.model_id,i.deployment_id,i.request_id,i.organization_id,i.application_id,i.workload_client_id,
                    i.call_started_at,i.call_start_pricing_state,i.call_start_price_revision_id,i.call_start_price_snapshot,
                    s.id step_id,s.step_code,s.sequence_no,s.state step_state,s.status step_status,s.delete_time step_delete_time
             FROM sand_ai_invocation_dispatch d JOIN sand_ai_invocation i ON i.id=d.invocation_id
             LEFT JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=d.parent_task_run_id
             WHERE d.parent_task_run_id=? OR i.request_id IN
                 (SELECT context->>'child_request_id' FROM sand_ai_task_step WHERE task_id=?)
             ORDER BY d.id",
            [(int) $task->id, (int) $task->id],
        );
        if ($rows === [] || (int) $task->attempt_count < 1
            || count($rows) > ($maximumRound + 1) * (int) $task->attempt_count) return null;
        $orphan = Db::connect('pgsql')->query(
            "SELECT 1 FROM sand_ai_invocation i
             WHERE (i.request_id ~ ?
                 OR i.request_id IN (SELECT context->>'child_request_id' FROM sand_ai_task_step WHERE task_id=?))
               AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch d
                   WHERE d.invocation_id=i.id AND d.parent_task_run_id=?) LIMIT 1",
            ['^agent_' . $task->id . '_(round_|summary_)', (int) $task->id, (int) $task->id],
        );
        if ($orphan !== []) return null;
        try {
            $attribution = AgentGateway::attributionFromPersistedInput($input, (int) $task->environment_id);
            $config = (array) config('sand_ai_outcome_receipt', []);
            $receipts = new OutcomeReceiptService(new PostgresOutcomeReceiptStore(),
                new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0));
        } catch (ApiProblem|\InvalidArgumentException) {
            return null;
        }
        $usageIds = [];
        $invocationIds = [];
        $latest = [];
        $attempts = [];
        $sealedCount = 0;
        $nodes = [...array_map(static fn (int $round): string => 'agent_round_' . $round, range(1, $maximumRound)), 'agent_summary_1'];
        foreach ($rows as $row) {
            $node = $row['workflow_node_code'];
            $attempt = (int) $row['attempt_no'];
            $expectedSequence = $node === 'agent_summary_1' ? 5
                : ($node === 'agent_round_4' ? 6 : (int) substr((string) $node, -1) + 1);
            if (!in_array([$row['dispatch_state'], $row['invocation_state']],
                    [['succeeded', 'succeeded'], ['failed', 'failed'], ['not_dispatched', 'failed']], true)
                || $row['execution_scope'] !== 'agent' || $row['capability_code'] !== 'chat'
                || !in_array($node, $nodes, true) || $attempt < 1
                || (int) $row['environment_id'] !== $attribution->environmentId
                || (int) $row['dispatch_environment_id'] !== $attribution->environmentId
                || (int) $row['organization_id'] !== $attribution->organizationId
                || (int) $row['application_id'] !== $attribution->applicationId
                || (int) $row['workload_client_id'] !== $attribution->workloadClientId
                || (int) $row['dispatch_application_id'] !== $attribution->applicationId
                || $row['service_action'] !== $attribution->serviceAction || $row['source_kind'] !== $attribution->sourceKind
                || (int) $row['dispatch_model_id'] !== (int) $row['model_id']
                || (int) $row['dispatch_deployment_id'] !== (int) $row['deployment_id']
                || (int) $row['step_id'] !== (int) $row['parent_task_step_id'] || $row['step_code'] !== $node
                || (int) $row['sequence_no'] !== $expectedSequence || (int) $row['step_status'] !== 1
                || $row['step_delete_time'] !== null || $row['dispatch_complete_time'] === null
                || isset($attempts[$node][$attempt])) return null;
            try {
                AgentGateway::assertModelDispatchIdentity($task, $row);
            } catch (ApiProblem|\InvalidArgumentException) {
                return null;
            }
            $attempts[$node][$attempt] = true;
            if (!isset($latest[$node]) || $attempt > (int) $latest[$node]['attempt_no']) $latest[$node] = $row;
            $invocationIds[] = (int) $row['invocation_id'];
            if ($row['dispatch_state'] !== 'succeeded') {
                $failedUsage = $this->knownFailedAttemptUsage($row, $attribution);
                if ($failedUsage === false) return null;
                if (is_int($failedUsage)) $usageIds[] = $failedUsage;
                continue;
            }
            $sealedCount++;
            $context = ['environment_id' => (int) $row['environment_id'], 'invocation_id' => (int) $row['invocation_id'],
                'request_fingerprint' => (string) $row['request_fingerprint'], 'capability_code' => 'agent_tool',
                'model_id' => (int) $row['model_id'], 'deployment_id' => (int) $row['deployment_id'],
                'version' => OutcomeReceiptCipher::RECEIPT_VERSION];
            // Internal AEAD verification only. Neither semantic text nor tool arguments leave this method.
            try {
                $accounting = AgentToolProviderOutcome::decode($receipts->replay($context))->accounting;
            } catch (ApiProblem) {
                return null;
            }
            try {
                $owner = UsageAttribution::fromCurrentPersisted($accounting->attribution, (int) $task->environment_id,
                    $accounting->attribution['source_kind'], $accounting->attribution['service_action']);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if (!$accounting->usageMeasured || $owner->canonicalTrustedIdentity() !== $attribution->canonicalTrustedIdentity()
                || $accounting->provider['id'] !== (int) $row['dispatch_provider_id']
                || $accounting->model['id'] !== (int) $row['model_id']
                || $accounting->deployment['id'] !== (int) $row['deployment_id']) return null;
            $usage = Db::connect('pgsql')->query('SELECT * FROM sand_ai_usage_event WHERE invocation_id=?', [(int) $row['invocation_id']]);
            if (count($usage) !== 1) return null;
            $event = $usage[0];
            if ($event['usage_state'] !== 'measured' || $event['pricing_state'] !== 'priced'
                || (int) $event['input_tokens'] !== $accounting->inputTokens
                || (int) $event['output_tokens'] !== $accounting->outputTokens
                || (int) $event['total_tokens'] !== $accounting->inputTokens + $accounting->outputTokens
                || (int) $event['organization_id'] !== $attribution->organizationId
                || (int) $event['application_id'] !== $attribution->applicationId
                || (int) $event['environment_id'] !== $attribution->environmentId
                || (int) $event['workload_client_id'] !== $attribution->workloadClientId
                || (int) $event['provider_id'] !== $accounting->provider['id']
                || (int) $event['model_id'] !== $accounting->model['id']
                || (int) $event['deployment_id'] !== $accounting->deployment['id']
                || (int) $event['price_revision_id'] < 1 || !is_string($event['currency']) || $event['currency'] === ''
                || !is_string($event['metered_amount']) || preg_match('/^\d+\.\d{6}$/D', $event['metered_amount']) !== 1) return null;
            foreach (['provider' => 'provider_snapshot', 'model' => 'model_snapshot', 'deployment' => 'deployment_snapshot'] as $property => $column) {
                $snapshot = $event[$column];
                if (is_string($snapshot)) {
                    try { $snapshot = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR); }
                    catch (\JsonException) { return null; }
                }
                if (!is_array($snapshot) || !RequestPayloadFingerprint::same($snapshot, $accounting->{$property}, [])) return null;
            }
            $usageIds[] = (int) $event['id'];
        }
        foreach ($latest as $row) {
            if ($row['dispatch_state'] !== 'succeeded' || $row['invocation_state'] !== 'succeeded') return null;
        }
        if (count($usageIds) !== count(array_unique($usageIds))
            || count($invocationIds) !== count(array_unique($invocationIds))) return null;
        $toolReceipts = $this->knownToolReceipts($task, $input, $maximumRound);
        if ($toolReceipts === null) return null;
        return ['receipt_count' => $sealedCount, 'usage_event_ids' => $usageIds,
            'tool_receipts' => $toolReceipts,
            'original_seal_authenticated' => true, 'authorization_denied' => true];
    }

    /** Failed predecessors retain their immutable fee facts; missing accounting never becomes zero usage. */
    private function knownFailedAttemptUsage(array $row, UsageAttribution $attribution): int|false|null
    {
        $events = Db::connect('pgsql')->query('SELECT * FROM sand_ai_usage_event WHERE invocation_id=?',
            [(int) $row['invocation_id']]);
        if ($row['dispatch_state'] === 'not_dispatched') {
            return $events === [] && $row['dispatch_start_time'] === null ? null : false;
        }
        if ($row['dispatch_start_time'] === null || count($events) !== 1) return false;
        $event = $events[0];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
            if ((!is_int($event[$field]) && !is_string($event[$field]))
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $event[$field]) !== 1
                || filter_var($event[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) return false;
        }
        $inputTokens = (int) $event['input_tokens'];
        $outputTokens = (int) $event['output_tokens'];
        if ($inputTokens > PHP_INT_MAX - $outputTokens || (int) $event['total_tokens'] !== $inputTokens + $outputTokens
            || $event['usage_state'] !== 'measured' || !in_array($event['pricing_state'], ['priced', 'unpriced'], true)
            || (int) $event['id'] < 1 || $event['idempotency_key'] !== 'invocation:' . $row['invocation_id']
            || (int) $event['environment_id'] !== $attribution->environmentId
            || (int) $event['organization_id'] !== $attribution->organizationId
            || (int) $event['application_id'] !== $attribution->applicationId
            || (int) $event['workload_client_id'] !== $attribution->workloadClientId
            || $event['service_action'] !== $attribution->serviceAction || $event['source_kind'] !== $attribution->sourceKind
            || $event['capability_code'] !== 'chat'
            || (int) $event['provider_id'] !== (int) $row['dispatch_provider_id']
            || (int) $event['model_id'] !== (int) $row['model_id']
            || (int) $event['deployment_id'] !== (int) $row['deployment_id']) return false;
        $identity = $this->snapshot($event['identity_snapshot']);
        $provider = $this->snapshot($event['provider_snapshot']);
        $model = $this->snapshot($event['model_snapshot']);
        $deployment = $this->snapshot($event['deployment_snapshot']);
        if ($identity === null || !RequestPayloadFingerprint::same($identity, $attribution->identitySnapshot, [])
            || $provider === null || ($provider['id'] ?? null) !== (int) $row['dispatch_provider_id']
            || $model === null || ($model['id'] ?? null) !== (int) $row['model_id']
            || $deployment === null || ($deployment['id'] ?? null) !== (int) $row['deployment_id']) return false;
        try {
            $price = CallStartPricingSnapshot::fromPersistence($row['call_started_at'], $row['call_start_pricing_state'],
                $row['call_start_price_revision_id'], $this->snapshot($row['call_start_price_snapshot']));
            $dispatchPrice = $this->snapshot($row['price_snapshot']);
            if ($dispatchPrice === null || !RequestPayloadFingerprint::same($dispatchPrice, $price->dispatchSnapshot(), [])) return false;
            $expected = $price->usagePricing($inputTokens, $outputTokens, true);
        } catch (ApiProblem|\InvalidArgumentException) {
            return false;
        }
        foreach (['pricing_state', 'price_revision_id', 'metered_amount', 'currency'] as $field) {
            $actual = $event[$field];
            if ($field === 'price_revision_id' && $actual !== null) $actual = (int) $actual;
            if ($actual !== $expected[$field]) return false;
        }
        $eventPrice = $this->snapshot($event['price_snapshot']);
        if ($eventPrice === null || !RequestPayloadFingerprint::same($eventPrice, $expected['price_snapshot'], [])) return false;
        return (int) $event['id'];
    }

    /** Decode only persisted metadata; a malformed snapshot keeps the task held. */
    private function snapshot(mixed $value): ?array
    {
        if (is_string($value)) {
            try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return null; }
        }
        if (is_object($value)) $value = (array) $value;
        return is_array($value) ? $value : null;
    }

    /** Full typed protocol is mandatory for the new fourth round; legacy inputs keep their original bound. */
    private function maximumRound(array $input): int
    {
        $hasBindings = array_key_exists('_agent_readonly_tools', $input);
        $definition = $input['_agent_definition'] ?? null;
        $hasDefinitionBindings = is_array($definition) && array_key_exists('readonly_tool_bindings', $definition);
        if (!$hasBindings) {
            if (array_key_exists('_agent_readonly_protocol', $input) || $hasDefinitionBindings) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly receipt binding is unavailable');
            }
            return 3;
        }
        $bindings = $input['_agent_readonly_tools'];
        if (!is_array($bindings) || !is_int($input['max_output_tokens'] ?? null)
            || $input['max_output_tokens'] < 1 || $input['max_output_tokens'] > 512) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly receipt binding is unavailable');
        }
        AgentConversationGateway::assertReadonlyProtocol($input['_agent_readonly_protocol'] ?? null);
        $digest = AgentConversationGateway::readonlyBindingsDigest($bindings);
        if ($definition !== null && (!$hasDefinitionBindings || !is_array($definition['readonly_tool_bindings'])
            || !hash_equals($digest, AgentConversationGateway::readonlyBindingsDigest($definition['readonly_tool_bindings'])))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly receipt definition changed');
        }
        return 4;
    }

    /** Complete inventory before any local write: extra, orphaned or unknown managed children keep the parent held. */
    private function knownToolReceipts(TaskRun $task, array $input, int $maximumRound): ?array
    {
        $steps = TaskStep::where('task_id', (int) $task->id)
            ->whereRaw("(step_code IN ('agent_managed_tool_1','agent_managed_tool_2') OR sequence_no IN (20,21) OR step_code ~ '^agent_tool_continue_[a-f0-9]{32}$')")
            ->order('id', 'asc')->limit(17)->select();
        if (count($steps) > 16) return null;
        $runs = ToolRun::whereRaw(
            "parent_task_run_id=? OR parent_task_step_id IN
             (SELECT id FROM sand_ai_task_step WHERE task_id=? AND
                (step_code IN ('agent_managed_tool_1','agent_managed_tool_2') OR sequence_no IN (20,21) OR step_code ~ '^agent_tool_continue_[a-f0-9]{32}$'))
             OR (environment_id=? AND (request_id LIKE ? OR request_id LIKE ?))",
            [(int) $task->id, (int) $task->id, (int) $task->environment_id, 'agent-tool:' . $task->id . ':%', 'agent-tool-continue:' . $task->id . ':%'],
        )->order('id', 'asc')->limit(17)->select();
        if (count($runs) > 16 || count($runs) !== count($steps) || ($maximumRound === 3 && (count($steps) !== 0 || count($runs) !== 0))) return null;
        $proofs = [];
        $used = [];
        $usageIds = [];
        $codes = [];
        foreach ($steps as $step) {
            try { $bindingStep = AgentReadonlyToolContinuationNode::originalStep($task, $step); }
            catch (ApiProblem) { return null; }
            $index = array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
            if ($index === false || (int) $bindingStep->sequence_no !== 20 + $index
                || (int) $step->status !== 1 || $step->delete_time !== null
                || !in_array((string) $step->state, ['running', 'recovery_pending', 'succeeded', 'failed', 'canceled'], true)
                || !is_array($input['_agent_readonly_tools'][$index] ?? null)
                || in_array((string) $step->step_code, $codes, true)) return null;
            $codes[] = (string) $step->step_code;
            $matches = [];
            foreach ($runs as $run) {
                if ((int) $run->parent_task_run_id === (int) $task->id
                    && (int) $run->parent_task_step_id === (int) $step->id) $matches[] = $run;
            }
            if (count($matches) !== 1) return null;
            $run = $matches[0];
            $context = $step->context;
            if (!is_array($context) || (array_key_exists('tool_run_id', $context)
                && (!is_int($context['tool_run_id']) || $context['tool_run_id'] !== (int) $run->id))) return null;
            try {
                $proof = (new ToolRunGateway())->agentKnownReceiptProof($task, $step, $run);
            } catch (ApiProblem|\InvalidArgumentException) {
                return null;
            }
            if (!is_array($proof) || ($proof['run_id'] ?? null) !== (int) $run->id
                || ($proof['step_id'] ?? null) !== (int) $step->id
                || !in_array($proof['finalization_state'] ?? null, ['succeeded', 'failed'], true)
                || in_array($proof['run_id'], $used, true)) return null;
            if (($proof['proof_kind'] ?? null) === 'sealed_receipt') {
                if (!in_array($proof['state'] ?? null, ['succeeded', 'failed'], true)
                    || ((string) $run->state === TaskState::RUNNING
                        ? $proof['finalization_state'] !== TaskState::FAILED
                        : $proof['finalization_state'] !== (string) $run->state)
                    || !is_int($proof['receipt_id'] ?? null) || $proof['receipt_id'] < 1
                    || !is_int($proof['usage_event_id'] ?? null) || $proof['usage_event_id'] < 1
                    || !is_int($proof['quantity'] ?? null) || $proof['quantity'] < 1 || $proof['quantity'] > 1024
                    || in_array($proof['usage_event_id'], $usageIds, true)) return null;
                $usageIds[] = $proof['usage_event_id'];
            } elseif (($proof['proof_kind'] ?? null) === 'never_dispatched') {
                // Null receipt/usage is meaningful only with the service's durable
                // cancellation proof. Missing evidence never falls into this branch.
                if (($proof['state'] ?? null) !== 'cancelled' || $proof['finalization_state'] !== TaskState::FAILED
                    || (string) $run->state !== TaskState::FAILED
                    || !array_key_exists('receipt_id', $proof) || $proof['receipt_id'] !== null
                    || !array_key_exists('usage_event_id', $proof) || $proof['usage_event_id'] !== null
                    || ($proof['quantity'] ?? null) !== 0) return null;
            } else {
                return null;
            }
            $used[] = $proof['run_id'];
            $proofs[] = array_intersect_key($proof, array_flip(['proof_kind','run_id','step_id','receipt_id','state','finalization_state','usage_event_id','quantity']));
        }
        return count($used) === count($runs) ? $proofs : null;
    }
}
