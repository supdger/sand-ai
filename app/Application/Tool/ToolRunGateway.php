<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\ToolRunAccessGuard;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Tool;
use app\Infrastructure\Persistence\Model\ToolAttempt;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolEnvironmentBinding;
use app\Infrastructure\Persistence\Model\ToolOutcomeReceipt;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Persistence\Model\ToolSource;
use app\Infrastructure\Persistence\Model\ToolVersion;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshot;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshotItem;
use think\facade\Db;
use support\Request;

final class ToolRunGateway
{
    public function __construct(
        private readonly ?ToolEndpointVerifier $endpointPolicy = null,
        private readonly ?StandardReadonlyHttpTransport $transport = null,
        private readonly ?ToolConnectionSecretCipher $connectionCipher = null,
        private readonly ?ToolOutcomeReceiptCipher $receiptCipher = null,
        private readonly ?ToolUsageMeter $usageMeter = null,
        private readonly ?McpStreamableHttpClient $mcpClient = null,
        private readonly ?McpReadonlyToolRegistry $mcpRegistry = null,
        private readonly ?ToolRunBoundaryPause $boundaryPause = null,
        private readonly ?ToolRunAccessGuard $accessGuard = null,
    ) {
    }

    /** @param array<string,mixed>|\stdClass $input @return array<string,mixed> */
    public function run(IdentityContext $identity, string $code, int $versionNo, string $businessScope, array|\stdClass $input, string $idempotencyKey, Request $httpRequest, string $requestId): array
    {
        $request = $this->request($identity, $code, $versionNo, $businessScope, $input, $idempotencyKey);
        $guard = $this->accessGuard ?? new ToolRunAccessGuard();
        return $this->execute($identity, $request,
            fn (ToolRun $run): array => $guard->authorize($httpRequest, $identity, (int) $run->id, 'sand_ai.tool.run', $requestId, $run->execution_snapshot['data_class'] ?? null),
            fn (ToolRun $run, array $decision) => $guard->revalidate($httpRequest, $identity, (int) $run->id, 'sand_ai.tool.run', $requestId, $decision),
        );
    }

    /**
     * Agent worker entry. Parent, scope and version come from durable server facts.
     * No Request object or caller-supplied IdentityContext is manufactured.
     */
    public function runQueued(\app\Application\Task\TaskExecutionLease $parentLease, int $stepId, array $binding, array|\stdClass $input): array
    {
        $parentFacts = new \app\Application\Agent\AgentReadonlyToolParentFacts();
        $facts = $parentFacts->require($parentLease, $stepId, $binding);
        $taskGuard = new \app\Identity\Infrastructure\TaskRunAccessGuard();
        $authorization = \app\Application\Agent\AgentContinuationGateway::executionAuthorization($facts['task']);
        \app\Application\Agent\AgentContinuationGateway::revalidateAdmission($facts['task']);
        if (!is_array($authorization)) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original agent authorization is unavailable');
        $queued = $taskGuard->revalidateQueued($authorization, $facts['task']);
        $guard = $this->accessGuard ?? new ToolRunAccessGuard();
        $identity = $guard->queuedIdentity($queued);
        $selection = $binding['selection'];
        $request = $this->request($identity, $selection['code'], $selection['version'], $selection['business_scope'],
            $input, $facts['parent']['child_request_id']);
        $request['agent_parent'] = $facts['parent'];
        $request['agent_binding'] = $binding;
        $request['payload_fingerprint'] = ToolFingerprint::for('agent_tool_run_request', [
            'request' => $request['payload_fingerprint'], 'parent' => $facts['parent'],
        ]);
        $revalidate = function (ToolRun $run, array $decision) use ($parentFacts, $parentLease, $stepId, $binding, $taskGuard, $guard, $authorization): void {
            $current = $parentFacts->require($parentLease, $stepId, $binding);
            \app\Application\Agent\AgentContinuationGateway::revalidateAdmission($current['task']);
            $original = $taskGuard->revalidateQueued($authorization, $current['task']);
            $toolIdentity = $guard->queuedIdentity($original);
            if ((int) $run->parent_task_run_id !== $parentLease->taskId || (int) $run->parent_task_step_id !== $stepId
                || ($decision['parent_authorization_id'] ?? null) !== $authorization['authorization_id']
                || ($decision['expires_at'] ?? null) !== $authorization['expires_at']) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent tool authorization parent changed');
            }
            $guard->revalidateQueued($original, $toolIdentity, (int) $run->id, $decision);
        };
        $authorize = function (ToolRun $run) use ($guard, $queued, $identity, $authorization, $facts, $revalidate): array {
            $decision = Db::connect('pgsql')->transaction(function () use ($run, $guard, $queued, $identity, $authorization, $facts): array {
                $locked = ToolRun::where('id', (int) $run->id)->lock(true)->find();
                if ($locked === null || (int) $locked->parent_task_run_id !== $facts['parent']['task_id']
                    || (int) $locked->parent_task_step_id !== $facts['parent']['step_id']) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent tool parent linkage is unavailable');
                }
                $decision = $locked->queued_authorization;
                if ($decision === null) {
                    // A dispatched or terminal run may never replace its original decision.
                    $attempt = ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->find();
                    if ((string) $locked->state !== 'running' || $attempt === null || (string) $attempt->state !== 'prepared') {
                        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original tool decision is unavailable');
                    }
                    $decision = $guard->authorizeQueued($queued, $identity, (int) $run->id,
                        $facts['parent']['child_request_id'], $run->execution_snapshot['data_class'] ?? null);
                    $decision['parent_authorization_id'] = $authorization['authorization_id'];
                    $decision['expires_at'] = $authorization['expires_at'];
                    if (isset($facts['task']->input['_agent_continuation'])) $decision['continuation_key'] = $facts['task']->input['_agent_continuation']['key'];
                    $locked->save(['queued_authorization' => $decision]);
                }
                if (!is_array($decision)) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original tool decision is invalid');
                return $decision;
            });
            $revalidate($run, $decision);
            return $decision;
        };
        return $this->execute($identity, $request, $authorize, $revalidate);
    }

    /** Reads an original sealed success under current tool.read; no transport or metering writes. */
    public function readQueuedContinuation(\app\Application\Task\TaskExecutionLease $lease, int $stepId,
        array $binding, array|\stdClass $input, array $queued): array
    {
        (new \app\Application\Task\TaskGateway())->heartbeat($lease);
        $task = \app\Infrastructure\Persistence\Model\TaskRun::where('id', $lease->taskId)->where('task_type', 'agent_run')
            ->where('state', 'running')->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')
            ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->find();
        $step = \app\Infrastructure\Persistence\Model\TaskStep::where('id', $stepId)->where('task_id', $lease->taskId)->find();
        if ($task === null || $step === null || !in_array((string) $step->state, ['succeeded', 'running', 'failed'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Original tool completion is unavailable');
        }
        \app\Application\Agent\AgentContinuationGateway::revalidateAdmission($task);
        $parent = $queued['identity'] ?? null;
        if (!$parent instanceof IdentityContext) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Continuation identity is unavailable');
        $provider = new \app\Identity\Infrastructure\HostSandIamIdentityContextProvider();
        try {
            $identity = $provider->requireSignedContext($queued['signed_context'], 'sand_ai.tool.read', $queued['source_ip'], $queued['request_id']);
            $taskIdentity = $provider->requireSignedContext($queued['signed_context'], 'sand_ai.task.read', $queued['source_ip'], $queued['request_id']);
        } catch (\app\Identity\Contract\IdentityContextException $failure) {
            throw \app\Api\Support\IdentityContextProblem::from($failure);
        }
        foreach ([$identity, $taskIdentity] as $part) {
            if ($part->organizationId !== $parent->organizationId || $part->applicationId !== $parent->applicationId
                || $part->environmentId !== $parent->environmentId || $part->workloadClientId !== $parent->workloadClientId
                || $part->audience !== $parent->audience || $part->expiresAt !== $parent->expiresAt) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Continuation identity changed');
            }
        }
        $inputHash = ToolFingerprint::for('agent_readonly_input', ['input' => $input]);
        $bindingStep = \app\Application\Agent\AgentReadonlyToolContinuationNode::originalStep($task, $step);
        $childKey = isset($step->context['original_step_id']) ? \app\Application\Agent\AgentReadonlyToolContinuationNode::childKey(
            (int) $task->id, $step->context['original_step_id'], $step->context['original_run_id'], $step->context['continuation_key'])
            : 'agent-tool:' . $task->id . ':' . $step->id . ':' . substr($inputHash, 0, 32);
        $index = array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
        if ($index === false || !is_array($task->input['_agent_readonly_tools'][$index] ?? null)
            || !\app\Application\Agent\AgentReadonlyToolProtocol::valid($task->input['_agent_readonly_protocol'] ?? null)
            || !hash_equals(ToolFingerprint::for('agent_readonly_binding', $binding),
                ToolFingerprint::for('agent_readonly_binding', $task->input['_agent_readonly_tools'][$index]))
            || ($step->context['readonly_binding_sha256'] ?? null) !== ($binding['snapshot_sha256'] ?? null)
            || ($step->context['input_sha256'] ?? null) !== $inputHash || ($step->context['child_request_id'] ?? null) !== $childKey) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool parent facts changed');
        }
        (new \app\Application\Agent\AgentReadonlyToolCatalog())->assertCurrent((int) $task->environment_id, [$binding]);
        ToolSchemaPolicy::assertValue($binding['input_schema'], $input);
        $run = ToolRun::where('parent_task_run_id', (int) $task->id)->where('parent_task_step_id', $stepId)
            ->where('environment_id', (int) $task->environment_id)->where('request_id', $childKey)->find();
        if ($run === null || (string) $run->state !== 'succeeded' || !$this->sameIdentity($run, $identity)
            || !hash_equals($inputHash, ToolFingerprint::for('agent_readonly_input', ['input' => $run->input]))) {
            throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Original tool receipt is not completed');
        }
        $authorizer = new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer();
        $taskDecision = $authorizer->authorizeSigned('Bearer ' . $queued['signed_context'], $queued['source_ip'], $taskIdentity,
            'sand_ai.task.read', 'sand_ai.task_operation', (string) $task->id,
            'agent-continue-task-read:' . $run->id . ':' . bin2hex(random_bytes(16)), $queued['request_id']);
        $decision = $authorizer->authorizeSigned('Bearer ' . $queued['signed_context'], $queued['source_ip'], $identity,
            'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id,
            'agent-continue-tool-read:' . $run->id . ':' . bin2hex(random_bytes(16)), $queued['request_id']);
        if (($decision['data_class'] ?? null) !== ($binding['data_class'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Current tool source classification changed');
        }
        $result = Db::connect('pgsql')->transaction(function () use ($run, $task, $binding, $input, $childKey, $step, $identity): array {
            $original = ToolRun::where('id', (int) $run->id)->where('state', 'succeeded')->lock(true)->find();
            if ($original === null || ($step->context['tool_run_id'] ?? (int) $run->id) !== (int) $run->id) {
                throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Original tool changed during receipt read');
            }
            $selection = $binding['selection'];
            $request = $this->request($identity, $selection['code'], $selection['version'], $selection['business_scope'], $input, $childKey);
            $request['agent_parent'] = ['task_id' => (int) $task->id, 'step_id' => (int) $step->id,
                'binding_sha256' => $binding['snapshot_sha256'], 'child_request_id' => $childKey];
            $request['agent_binding'] = $binding;
            $request['payload_fingerprint'] = ToolFingerprint::for('agent_tool_run_request', [
                'request' => $request['payload_fingerprint'], 'parent' => $request['agent_parent']]);
            if (!hash_equals((string) $original->request_payload_fingerprint, $request['payload_fingerprint'])
                || !hash_equals((string) $original->request_fingerprint, ToolFingerprint::for('tool_run_execution', [
                    'request' => $request, 'execution_snapshot' => $original->execution_snapshot]))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool request changed');
            }
            foreach (['adapter', 'effect_class', 'tool_id', 'tool_version_id', 'binding_id', 'connection_id', 'connection_revision_id',
                'connection_config_fingerprint', 'data_class', 'timeout_ms', 'response_max_bytes', 'wire_contract', 'mcp_snapshot_item_id', 'mcp_snapshot_item_hash'] as $field) {
                if (($original->execution_snapshot[$field] ?? null) !== ($binding[$field] ?? null)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool configuration changed');
                }
            }
            $originalDecision = $original->queued_authorization;
            $originalAuthorization = $task->input['iam_authorization'] ?? null;
            if (is_array($originalDecision) && isset($originalDecision['continuation_key'])) {
                $originalAuthorization = null;
                foreach (($task->input['_agent_continuations'] ?? []) as $record) {
                    if (($record['key'] ?? null) === $originalDecision['continuation_key']) $originalAuthorization = $record['authorization']['task_authorization'] ?? null;
                }
            }
            if (!is_array($originalAuthorization) || !is_array($originalDecision)
                || ($originalDecision['parent_authorization_id'] ?? null) !== ($originalAuthorization['authorization_id'] ?? null)
                || ($originalDecision['expires_at'] ?? null) !== ($originalAuthorization['expires_at'] ?? null)
                || ($originalDecision['action_code'] ?? null) !== 'sand_ai.tool.run'
                || ($originalDecision['operation_id'] ?? null) !== $childKey
                || ($originalDecision['data_class'] ?? null) !== ($binding['data_class'] ?? null)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool authorization binding changed');
            }
            $aad = new \app\Identity\Infrastructure\QueuedSandIamTaskBinding((int) $task->id, 'agent_run',
                $originalAuthorization['authorization_id'], $originalAuthorization['source_ip'], $originalAuthorization['request_id'], $originalAuthorization['expires_at']);
            $cipher = new \app\Identity\Infrastructure\QueuedSandIamContextCipher((array) config('sand_ai_iam_context', []));
            if ($originalAuthorization['expires_at'] <= time()) $cipher->assertExpiredAuthentic($originalAuthorization['context'], $aad);
            else $cipher->open($originalAuthorization['context'], $aad);
            $attempt = ToolAttempt::where('tool_run_id', (int) $original->id)->where('attempt_no', 1)->lock(true)->find();
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $original->id)->where('state', 'available')->lock(true)->find();
            if ($attempt === null || (string) $attempt->state !== 'succeeded' || $receipt === null
                || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')
                || !hash_equals((string) $attempt->dispatch_fingerprint, ToolFingerprint::for('tool_dispatch', [
                    'run_id' => (int) $original->id, 'request_fingerprint' => (string) $original->request_fingerprint]))) {
                throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Original tool receipt is unavailable');
            }
            $outcome = $this->decodeReceipt($original, $receipt);
            if ($outcome->state !== 'succeeded' || !hash_equals(
                ToolFingerprint::for('tool_terminal_receipt', ['result' => $original->result, 'citations' => $original->citations ?? []]),
                ToolFingerprint::for('tool_terminal_receipt', ['result' => $outcome->result, 'citations' => $outcome->citations]))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original result differs from its sealed tool receipt');
            }
            ToolSchemaPolicy::assertValue($binding['output_schema'], $outcome->result);
            if ((bool) $original->review_required !== ($binding['review_required'] ?? null)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool review policy changed');
            }
            $sourceRows = ToolSource::where('tool_run_id', (int) $original->id)->order('sequence_no')->select();
            if (count($sourceRows) !== count($outcome->citations)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool sources changed');
            foreach ($sourceRows as $index => $source) {
                if (!hash_equals(ToolFingerprint::for('tool_source', $outcome->citations[$index]),
                    ToolFingerprint::for('tool_source', ['source_ref' => $source->source_ref, 'locator' => $source->locator,
                        'source_version' => $source->source_version, 'content_hash' => $source->content_hash]))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original tool source version changed');
                }
            }
            $resources = $this->resourcesForRun($original);
            $meter = $this->usageMeter ?? new ToolUsageMeter();
            $adapter = (string) $original->execution_snapshot['adapter'];
            $quantity = $adapter === 'mcp_streamable_http' ? $meter->protocolRequestCount($original) : 1;
            $meter->requireRecordedQuantity($original, $resources['version'], $resources['connection'], $resources['revision'], $quantity, $adapter);
            return $this->summary($original, true);
        });
        $verified = $authorizer->revalidate($decision['authorization_id'], $queued['signed_context'], $identity,
            'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id, $queued['source_ip'], $queued['request_id']);
        if (($verified['data_class'] ?? null) !== ($decision['data_class'] ?? null)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Tool read classification changed');
        $authorizer->revalidate($taskDecision['authorization_id'], $queued['signed_context'], $taskIdentity,
            'sand_ai.task.read', 'sand_ai.task_operation', (string) $task->id, $queued['source_ip'], $queued['request_id']);
        (new \app\Application\Task\TaskGateway())->heartbeat($lease);
        \app\Application\Agent\AgentContinuationGateway::revalidateAdmission($task);
        return $result;
    }

    /** Pure metadata proof for a failed node: never opens IAM, SDK checkpoint or receipt payloads. */
    public function agentFailedNeverDispatchedProof(\app\Infrastructure\Persistence\Model\TaskRun $parent,
        \app\Infrastructure\Persistence\Model\TaskStep $step, ToolRun $run): ?array
    {
        return $this->agentNeverDispatchedMetadataProof($parent, $step, $run, false);
    }

    /** Internal recheck: active parents require a durable continuation admission; no execution authority is granted. */
    public function agentContinuationNeverDispatchedProof(\app\Infrastructure\Persistence\Model\TaskRun $parent,
        \app\Infrastructure\Persistence\Model\TaskStep $step, ToolRun $run): ?array
    {
        return $this->agentNeverDispatchedMetadataProof($parent, $step, $run, true);
    }

    private function agentNeverDispatchedMetadataProof(\app\Infrastructure\Persistence\Model\TaskRun $parent,
        \app\Infrastructure\Persistence\Model\TaskStep $step, ToolRun $run, bool $admitted): ?array
    {
        try {
            $actualParent = \app\Infrastructure\Persistence\Model\TaskRun::where('id', (int) $parent->id)
                ->where('task_type', 'agent_run')->whereIn('state', $admitted ? ['failed', 'retrying', 'running', 'succeeded'] : ['failed'])->where('status', 1)
                ->whereNull('delete_time')->whereNull('content_purged_time')->find();
            $actualStep = \app\Infrastructure\Persistence\Model\TaskStep::where('id', (int) $step->id)
                ->where('task_id', (int) $parent->id)->where('state', 'failed')->find();
            $actualRun = ToolRun::where('id', (int) $run->id)->where('parent_task_run_id', (int) $parent->id)
                ->where('parent_task_step_id', (int) $step->id)->where('state', 'failed')->find();
            if ($actualParent === null || $actualStep === null || $actualRun === null
                || (!$admitted && $actualParent->lease_expire_time !== null && !$this->expired($actualParent->lease_expire_time))) return null;
            if ($admitted && (string) $actualParent->state !== 'failed') {
                $record = $actualParent->input['_agent_continuation'] ?? null;
                if (!is_array($record) || ($record['task_id'] ?? null) !== (int) $actualParent->id
                    || ($record['run_id'] ?? null) !== \app\Application\Agent\AgentGateway::checkpointRunId($actualParent)
                    || !is_int($record['lease_fence'] ?? null) || (int) $actualParent->lease_fence < $record['lease_fence']) return null;
            }
            $verifiedParent = \app\Identity\Infrastructure\TaskInvocationFacts::fromTask($actualParent, 'sand_ai.agent.submit');
            $parentOwner = \app\Application\Usage\UsageAttribution::fromCurrentPersisted(
                $actualParent->input['_usage_attribution'] ?? null, (int) $actualParent->environment_id, 'agent', 'sand_ai.agent.submit');
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ((string) $actualRun->{$field} !== (string) $verifiedParent[$field]) return null;
            }
            foreach (['organization_ref', 'application_ref', 'workload_client_ref'] as $field) {
                if (($actualRun->identity_snapshot[$field] ?? null) !== ($parentOwner->identitySnapshot[$field] ?? null)) return null;
            }
            $authorization = $actualParent->input['iam_authorization'] ?? null;
            if (is_string($actualStep->context['continuation_key'] ?? null)) {
                $authorization = null;
                foreach (($actualParent->input['_agent_continuations'] ?? []) as $record) {
                    if (($record['key'] ?? null) === $actualStep->context['continuation_key']) $authorization = $record['authorization']['task_authorization'] ?? null;
                }
            }
            if (!is_array($authorization) || ($authorization['version'] ?? null) !== 1
                || ($authorization['task_type'] ?? null) !== 'agent_run' || !is_array($authorization['context'] ?? null)) return null;
            $bindingStep = \app\Application\Agent\AgentReadonlyToolContinuationNode::originalStep($actualParent, $actualStep);
            $index = array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
            $binding = $index === false ? null : ($actualParent->input['_agent_readonly_tools'][$index] ?? null);
            if (!is_array($binding) || !is_string($binding['snapshot_sha256'] ?? null)
                || !\app\Application\Agent\AgentReadonlyToolProtocol::valid($actualParent->input['_agent_readonly_protocol'] ?? null)) return null;
            $unsigned = $binding;
            unset($unsigned['snapshot_sha256']);
            if (!hash_equals($binding['snapshot_sha256'], ToolFingerprint::for('agent_readonly_binding', $unsigned))
                || !\app\Application\Agent\AgentReadonlyToolProtocol::valid($binding['execution_limits'] ?? null)) return null;
            $context = $actualStep->context;
            $inputHash = ToolFingerprint::for('agent_readonly_input', ['input' => $actualRun->input]);
            $childKey = isset($context['original_step_id']) ? \app\Application\Agent\AgentReadonlyToolContinuationNode::childKey(
                (int) $actualParent->id, $context['original_step_id'], $context['original_run_id'], $context['continuation_key'])
                : 'agent-tool:' . $actualParent->id . ':' . $actualStep->id . ':' . substr($inputHash, 0, 32);
            if (!is_array($context) || ($context['input_sha256'] ?? null) !== $inputHash
                || ($context['readonly_binding_sha256'] ?? null) !== $binding['snapshot_sha256']
                || ($context['child_request_id'] ?? null) !== $childKey || $actualRun->request_id !== $childKey
                || (array_key_exists('tool_run_id', $context) && $context['tool_run_id'] !== (int) $actualRun->id)) return null;
            foreach (['adapter', 'effect_class', 'tool_id', 'tool_version_id', 'binding_id', 'connection_id', 'connection_revision_id',
                'connection_config_fingerprint', 'data_class', 'timeout_ms', 'response_max_bytes', 'wire_contract', 'mcp_snapshot_item_id', 'mcp_snapshot_item_hash'] as $field) {
                if (($actualRun->execution_snapshot[$field] ?? null) !== ($binding[$field] ?? null)) return null;
            }
            $selection = $binding['selection'];
            $payload = ['environment_id' => (int) $actualParent->environment_id, 'code' => $selection['code'],
                'version_no' => $selection['version'], 'business_scope' => $selection['business_scope'], 'input' => $actualRun->input];
            $parentFacts = ['task_id' => (int) $actualParent->id, 'step_id' => (int) $actualStep->id,
                'binding_sha256' => $binding['snapshot_sha256'], 'child_request_id' => $childKey];
            $payloadHash = ToolFingerprint::for('agent_tool_run_request', ['request' => ToolFingerprint::for('tool_run_request', $payload), 'parent' => $parentFacts]);
            $request = $payload + ['idempotency_key' => $childKey, 'payload_fingerprint' => $payloadHash, 'agent_parent' => $parentFacts, 'agent_binding' => $binding];
            if (!hash_equals((string) $actualRun->request_payload_fingerprint, $payloadHash)
                || !hash_equals((string) $actualRun->request_fingerprint, ToolFingerprint::for('tool_run_execution', ['request' => $request, 'execution_snapshot' => $actualRun->execution_snapshot]))) return null;
            $attempt = ToolAttempt::where('tool_run_id', (int) $actualRun->id)->where('attempt_no', 1)->find();
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $actualRun->id)->where('state', 'available')->find();
            if ($attempt !== null && (string) $actualRun->state === 'failed' && (string) $attempt->state === 'cancelled'
                && $actualRun->lease_expires_at === null && $attempt->dispatched_time === null && $attempt->http_status === null
                && is_string($actualRun->completed_time) && $actualRun->completed_time !== ''
                && is_string($attempt->completed_time) && $attempt->completed_time !== ''
                && is_string($actualRun->error_code) && $actualRun->error_code !== ''
                && $actualRun->error_code === $attempt->error_code
                && $actualRun->result === null && ($actualRun->citations ?? []) === []
                && ($admitted || $actualParent->lease_expire_time === null || $this->expired($actualParent->lease_expire_time))) {
                if (!hash_equals((string) $attempt->dispatch_fingerprint,
                    ToolFingerprint::for('tool_dispatch', ['run_id' => (int) $actualRun->id, 'request_fingerprint' => (string) $actualRun->request_fingerprint]))
                    || ToolAttempt::where('tool_run_id', (int) $actualRun->id)->count() !== 1
                    || ToolSource::where('tool_run_id', (int) $actualRun->id)->count() !== 0
                    || ToolOutcomeReceipt::where('tool_run_id', (int) $actualRun->id)->count() !== 0
                    || \app\Infrastructure\Persistence\Model\UsageEvent::where('tool_run_id', (int) $actualRun->id)->count() !== 0
                    || \app\Infrastructure\Persistence\Model\ToolProtocolRequest::where('tool_run_id', (int) $actualRun->id)->count() !== 0) return null;
                $audit = AuditLog::where('id', (int) $actualRun->audit_log_id)->where('action', 'sand_ai.tool.run_rejected')
                    ->where('resource_type', 'tool_run')->where('resource_id', (int) $actualRun->id)->find();
                if ($audit === null || ($audit->context['error_code'] ?? null) !== $actualRun->error_code) return null;
                return ['proof_kind' => 'never_dispatched', 'run_id' => (int) $actualRun->id, 'step_id' => (int) $actualStep->id,
                    'receipt_id' => null, 'state' => 'cancelled', 'finalization_state' => 'failed', 'usage_event_id' => null, 'quantity' => 0];
            }
            return null;
        } catch (\Throwable) { return null; }
    }

    /**
     * Internal expired-parent proof. No authorization, transport or metering writes.
     * Call under the finalizer's parent-first transaction; never expose receipt contents.
     * @return array{proof_kind:string,run_id:int,step_id:int,receipt_id:?int,state:string,finalization_state:string,usage_event_id:?int,quantity:int}|null
     */
    public function agentKnownReceiptProof(\app\Infrastructure\Persistence\Model\TaskRun $parent, \app\Infrastructure\Persistence\Model\TaskStep $step, ToolRun $run): ?array
    {
        try {
            $actualParent = \app\Infrastructure\Persistence\Model\TaskRun::where('id', (int) $parent->id)->where('task_type', 'agent_run')
                ->where('state', 'recovery_pending')->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
            $actualStep = \app\Infrastructure\Persistence\Model\TaskStep::where('id', (int) $step->id)->where('task_id', (int) $parent->id)->find();
            $actualRun = ToolRun::where('id', (int) $run->id)->where('parent_task_run_id', (int) $parent->id)
                ->where('parent_task_step_id', (int) $step->id)->find();
            if ($actualParent === null || $actualStep === null || $actualRun === null
                || !in_array((string) $actualStep->state, ['running', 'recovery_pending', 'succeeded', 'failed', 'canceled'], true)
                || !in_array((string) $actualRun->state, ['running', 'succeeded', 'failed'], true)
                || ((string) $actualRun->state === 'running' && !$this->expired($actualRun->lease_expires_at))) return null;
            $verifiedParent = \app\Identity\Infrastructure\TaskInvocationFacts::fromTask($actualParent, 'sand_ai.agent.submit');
            $parentOwner = \app\Application\Usage\UsageAttribution::fromCurrentPersisted(
                $actualParent->input['_usage_attribution'] ?? null, (int) $actualParent->environment_id, 'agent', 'sand_ai.agent.submit');
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ((string) $actualRun->{$field} !== (string) $verifiedParent[$field]) return null;
            }
            foreach (['organization_ref', 'application_ref', 'workload_client_ref'] as $field) {
                if (($actualRun->identity_snapshot[$field] ?? null) !== ($parentOwner->identitySnapshot[$field] ?? null)) return null;
            }
            $authorization = $actualParent->input['iam_authorization'] ?? null;
            if (is_string($actualStep->context['continuation_key'] ?? null)) {
                $authorization = null;
                foreach (($actualParent->input['_agent_continuations'] ?? []) as $record) {
                    if (($record['key'] ?? null) === $actualStep->context['continuation_key']) $authorization = $record['authorization']['task_authorization'] ?? null;
                }
            }
            if (!is_array($authorization) || ($authorization['version'] ?? null) !== 1
                || ($authorization['task_type'] ?? null) !== 'agent_run' || !is_array($authorization['context'] ?? null)) return null;
            $aad = new \app\Identity\Infrastructure\QueuedSandIamTaskBinding((int) $actualParent->id, 'agent_run',
                $authorization['authorization_id'], $authorization['source_ip'], $authorization['request_id'], $authorization['expires_at']);
            (new \app\Identity\Infrastructure\QueuedSandIamContextCipher((array) config('sand_ai_iam_context', [])))
                ->assertExpiredAuthentic($authorization['context'], $aad);
            $bindingStep = \app\Application\Agent\AgentReadonlyToolContinuationNode::originalStep($actualParent, $actualStep);
            $index = array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
            $binding = $index === false ? null : ($actualParent->input['_agent_readonly_tools'][$index] ?? null);
            if (!is_array($binding) || !is_string($binding['snapshot_sha256'] ?? null)
                || !\app\Application\Agent\AgentReadonlyToolProtocol::valid($actualParent->input['_agent_readonly_protocol'] ?? null)) return null;
            $unsigned = $binding;
            unset($unsigned['snapshot_sha256']);
            if (!hash_equals($binding['snapshot_sha256'], ToolFingerprint::for('agent_readonly_binding', $unsigned))
                || !\app\Application\Agent\AgentReadonlyToolProtocol::valid($binding['execution_limits'] ?? null)) return null;
            $context = $actualStep->context;
            $inputHash = ToolFingerprint::for('agent_readonly_input', ['input' => $actualRun->input]);
            $childKey = isset($context['original_step_id']) ? \app\Application\Agent\AgentReadonlyToolContinuationNode::childKey(
                (int) $actualParent->id, $context['original_step_id'], $context['original_run_id'], $context['continuation_key'])
                : 'agent-tool:' . $actualParent->id . ':' . $actualStep->id . ':' . substr($inputHash, 0, 32);
            if (!is_array($context) || ($context['input_sha256'] ?? null) !== $inputHash
                || ($context['readonly_binding_sha256'] ?? null) !== $binding['snapshot_sha256']
                || ($context['child_request_id'] ?? null) !== $childKey || $actualRun->request_id !== $childKey
                || (array_key_exists('tool_run_id', $context) && $context['tool_run_id'] !== (int) $actualRun->id)) return null;
            foreach (['adapter', 'effect_class', 'tool_id', 'tool_version_id', 'binding_id', 'connection_id', 'connection_revision_id',
                'connection_config_fingerprint', 'data_class', 'timeout_ms', 'response_max_bytes', 'wire_contract', 'mcp_snapshot_item_id', 'mcp_snapshot_item_hash'] as $field) {
                if (($actualRun->execution_snapshot[$field] ?? null) !== ($binding[$field] ?? null)) return null;
            }
            $selection = $binding['selection'];
            $payload = ['environment_id' => (int) $actualParent->environment_id, 'code' => $selection['code'],
                'version_no' => $selection['version'], 'business_scope' => $selection['business_scope'], 'input' => $actualRun->input];
            $parentFacts = ['task_id' => (int) $actualParent->id, 'step_id' => (int) $actualStep->id,
                'binding_sha256' => $binding['snapshot_sha256'], 'child_request_id' => $childKey];
            $payloadHash = ToolFingerprint::for('agent_tool_run_request', ['request' => ToolFingerprint::for('tool_run_request', $payload), 'parent' => $parentFacts]);
            $request = $payload + ['idempotency_key' => $childKey, 'payload_fingerprint' => $payloadHash, 'agent_parent' => $parentFacts, 'agent_binding' => $binding];
            if (!hash_equals((string) $actualRun->request_payload_fingerprint, $payloadHash)
                || !hash_equals((string) $actualRun->request_fingerprint, ToolFingerprint::for('tool_run_execution', ['request' => $request, 'execution_snapshot' => $actualRun->execution_snapshot]))) return null;
            $attempt = ToolAttempt::where('tool_run_id', (int) $actualRun->id)->where('attempt_no', 1)->find();
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $actualRun->id)->where('state', 'available')->find();
            if ($attempt !== null && (string) $actualRun->state === 'failed' && (string) $attempt->state === 'cancelled'
                && $actualRun->lease_expires_at === null && $attempt->dispatched_time === null && $attempt->http_status === null
                && is_string($actualRun->completed_time) && $actualRun->completed_time !== ''
                && is_string($attempt->completed_time) && $attempt->completed_time !== ''
                && is_string($actualRun->error_code) && $actualRun->error_code !== ''
                && $actualRun->error_code === $attempt->error_code
                && $actualRun->result === null && ($actualRun->citations ?? []) === []
                && ($actualParent->lease_expire_time === null || $this->expired($actualParent->lease_expire_time))) {
                if (!hash_equals((string) $attempt->dispatch_fingerprint,
                    ToolFingerprint::for('tool_dispatch', ['run_id' => (int) $actualRun->id, 'request_fingerprint' => (string) $actualRun->request_fingerprint]))
                    || ToolOutcomeReceipt::where('tool_run_id', (int) $actualRun->id)->count() !== 0
                    || \app\Infrastructure\Persistence\Model\UsageEvent::where('tool_run_id', (int) $actualRun->id)->count() !== 0
                    || \app\Infrastructure\Persistence\Model\ToolProtocolRequest::where('tool_run_id', (int) $actualRun->id)->count() !== 0) return null;
                $audit = AuditLog::where('id', (int) $actualRun->audit_log_id)->where('action', 'sand_ai.tool.run_rejected')
                    ->where('resource_type', 'tool_run')->where('resource_id', (int) $actualRun->id)->find();
                if ($audit === null || ($audit->context['error_code'] ?? null) !== $actualRun->error_code) return null;
                return ['proof_kind' => 'never_dispatched', 'run_id' => (int) $actualRun->id, 'step_id' => (int) $actualStep->id,
                    'receipt_id' => null, 'state' => 'cancelled', 'finalization_state' => 'failed', 'usage_event_id' => null, 'quantity' => 0];
            }
            $decision = $actualRun->queued_authorization;
            if (!is_array($decision) || !is_int($decision['authorization_id'] ?? null) || $decision['authorization_id'] < 1
                || !is_string($decision['context_id'] ?? null) || $decision['context_id'] === ''
                || ($decision['parent_authorization_id'] ?? null) !== $authorization['authorization_id']
                || ($decision['expires_at'] ?? null) !== $authorization['expires_at']
                || ($decision['action_code'] ?? null) !== 'sand_ai.tool.run'
                || ($decision['data_class'] ?? null) !== ($binding['data_class'] ?? null)
                || ($decision['operation_id'] ?? null) !== $childKey) return null;

            if ($attempt === null || $receipt === null || ($actualParent->lease_expire_time !== null && !$this->expired($actualParent->lease_expire_time))
                || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')
                || !in_array((string) $attempt->state, ['dispatched', 'succeeded', 'failed'], true)) return null;
            if (!hash_equals((string) $attempt->dispatch_fingerprint,
                ToolFingerprint::for('tool_dispatch', ['run_id' => (int) $actualRun->id, 'request_fingerprint' => (string) $actualRun->request_fingerprint]))) return null;
            $outcome = $this->decodeReceipt($actualRun, $receipt);
            if (!in_array($outcome->state, ['succeeded', 'failed'], true)
                || ((string) $actualRun->state !== 'running' && (string) $actualRun->state !== $outcome->state)) return null;
            if ((string) $actualRun->state === 'running') {
                if ((string) $attempt->state !== 'dispatched') return null;
            } else {
                if ((string) $attempt->state !== $outcome->state
                    || !hash_equals(ToolFingerprint::for('tool_terminal_receipt', ['result' => $actualRun->result, 'citations' => $actualRun->citations ?? []]),
                        ToolFingerprint::for('tool_terminal_receipt', ['result' => $outcome->result, 'citations' => $outcome->citations]))) return null;
            }
            $resources = $this->resourcesForRun($actualRun);
            $adapter = (string) $actualRun->execution_snapshot['adapter'];
            $meter = $this->usageMeter ?? new ToolUsageMeter();
            $quantity = $adapter === 'mcp_streamable_http' ? $meter->protocolRequestCount($actualRun) : 1;
            $meter->requireRecordedQuantity($actualRun, $resources['version'], $resources['connection'], $resources['revision'], $quantity, $adapter);
            $event = \app\Infrastructure\Persistence\Model\UsageEvent::where('tool_run_id', (int) $actualRun->id)->find();
            if ($event === null || $quantity < 1 || $quantity > 1024) return null;
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ((string) $event->{$field} !== (string) $verifiedParent[$field]) return null;
            }
            foreach (['organization_ref', 'application_ref', 'workload_client_ref'] as $field) {
                if (($event->identity_snapshot[$field] ?? null) !== ($parentOwner->identitySnapshot[$field] ?? null)) return null;
            }
            return ['proof_kind' => 'sealed_receipt', 'run_id' => (int) $actualRun->id, 'step_id' => (int) $actualStep->id, 'receipt_id' => (int) $receipt->id,
                'state' => $outcome->state, 'finalization_state' => (string) $actualRun->state === 'running' ? 'failed' : (string) $actualRun->state, 'usage_event_id' => (int) $event->id, 'quantity' => $quantity];
        } catch (\Throwable) { return null; }
    }

    /** Parent transaction must prove every child first. This only finalizes original local receipts. */
    public function finalizeAgentKnownReceipt(\app\Infrastructure\Persistence\Model\TaskRun $parent, \app\Infrastructure\Persistence\Model\TaskStep $step, ToolRun $run): array
    {
        return Db::connect('pgsql')->transaction(function () use ($parent, $step, $run): array {
        $lockedParent = \app\Infrastructure\Persistence\Model\TaskRun::where('id', (int) $parent->id)->lock(true)->find();
        $lockedRun = ToolRun::where('id', (int) $run->id)->lock(true)->find();
        $lockedStep = \app\Infrastructure\Persistence\Model\TaskStep::where('id', (int) $step->id)->where('task_id', (int) $parent->id)->lock(true)->find();
        if ($lockedParent === null || $lockedRun === null || $lockedStep === null
            || ($proof = $this->agentKnownReceiptProof($lockedParent, $lockedStep, $lockedRun)) === null) {
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        if ((string) $lockedRun->state === 'running') {
            // Expired AEAD proves original ownership; it does not authorize publishing receipt contents.
            $code = 'SAND_AI_IDENTITY_CONTEXT_EXPIRED';
            $audit = AuditLog::create(['actor_type' => 'system', 'actor_ref' => 'sand-ai-agent-expired-receipt',
                'action' => 'sand_ai.tool.expired_receipt_finalized', 'resource_type' => 'tool_run', 'resource_id' => (int) $lockedRun->id,
                'summary' => 'Expired parent authorization denied; original encrypted tool receipt and accounting retained',
                'context' => ['parent_task_run_id' => (int) $lockedParent->id, 'receipt_id' => $proof['receipt_id'],
                    'usage_event_id' => $proof['usage_event_id'], 'original_outcome_state' => $proof['state'], 'provider_reissued' => false]]);
            $lockedRun->save(['state' => 'failed', 'error_code' => $code, 'error_detail' => ToolRunError::fromCode($code),
                'lease_expires_at' => null, 'lease_fence' => (int) $lockedRun->lease_fence + 1,
                'completed_time' => gmdate('Y-m-d H:i:s'), 'audit_log_id' => (int) $audit->id]);
            $attempt = ToolAttempt::where('tool_run_id', (int) $lockedRun->id)->where('attempt_no', 1)->lock(true)->find();
            if ($attempt === null || (string) $attempt->state !== 'dispatched') throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
            $attempt->save(['state' => 'failed', 'error_code' => $code, 'completed_time' => gmdate('Y-m-d H:i:s')]);
        }
        if ((string) $lockedRun->state !== $proof['finalization_state']) throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        // A known successful tool receipt cannot turn a failed authorization node into success.
        if (in_array((string) $lockedStep->state, ['running', 'recovery_pending'], true)) {
            $lockedStep->save(['state' => 'failed', 'completed_at' => $lockedStep->completed_at ?? date('Y-m-d H:i:s'),
                'error_code' => $lockedStep->error_code ?? $lockedRun->error_code ?? 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
                'context' => $lockedStep->context + ['tool_run_id' => (int) $lockedRun->id]]);
        }
        return $proof;
        });
    }

    /** Shared HTTP/queued runtime; authorization always addresses the persisted run. */
    private function execute(IdentityContext $identity, array $request, \Closure $authorize, \Closure $revalidate): array
    {
        $claim = $this->claim($identity, $request);
        /** @var ToolRun $run */ $run = $claim['run'];
        if (isset($request['agent_parent'])) {
            $expected = $request['agent_binding'];
            foreach (['adapter', 'effect_class', 'tool_id', 'tool_version_id', 'binding_id', 'connection_id', 'connection_revision_id', 'connection_config_fingerprint', 'data_class', 'timeout_ms', 'response_max_bytes', 'wire_contract', 'mcp_snapshot_item_id', 'mcp_snapshot_item_hash'] as $field) {
                if (($run->execution_snapshot[$field] ?? null) !== ($expected[$field] ?? null)) {
                    throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Agent tool execution differs from its published binding');
                }
            }
        }
        $claimModes = [];
        foreach (['replay', 'recover', 'recover_without_receipt', 'ambiguous', 'invalid_state', 'invalid_lease'] as $mode) {
            if (($claim[$mode] ?? false) === true) $claimModes[] = $mode;
        }
        if (count($claimModes) > 1) throw ToolRunError::problem('SAND_AI_TOOL_RUN_STATE_INVALID');
        $claimMode = $claimModes[0] ?? 'dispatch';
        if ($claimMode === 'dispatch' && (!(($claim['version'] ?? null) instanceof ToolVersion)
            || !(($claim['connection'] ?? null) instanceof ToolConnection)
            || !(($claim['revision'] ?? null) instanceof ToolConnectionRevision)
            || !(($claim['attempt'] ?? null) instanceof ToolAttempt)
            || (string) $claim['attempt']->state !== 'prepared' || !is_array($claim['lease'] ?? null))) {
            throw ToolRunError::problem('SAND_AI_TOOL_RUN_STATE_INVALID');
        }
        try {
            $decision = $authorize($run);
            $revalidate($run, $decision);
        } catch (ApiProblem $problem) {
            // A rejected read/recovery must retain original dispatched evidence and never fall through.
            // Only an owned, prepared ordinary claim may be closed as a durable pre-dispatch rejection.
            if ($claimMode === 'dispatch') {
                $this->finalizeWithoutReceipt($run, $claim['attempt'], $claim['lease'], $problem->errorCode);
            }
            throw $problem;
        }
        if ($claimMode === 'replay') {
            $summary = $this->replayTerminal($run);
            $revalidate($run, $decision);
            return $summary;
        }
        if ($claimMode === 'recover') {
            $summary = $this->recover($run, $claim['lease']);
            $revalidate($run, $decision);
            return $summary;
        }
        if ($claimMode === 'recover_without_receipt') {
            // This local-only service returns never: it records original evidence or keeps recovery pending.
            $this->recoverWithoutReceipt($run, $claim['attempt'], $claim['lease']);
        }
        if ($claimMode === 'ambiguous') {
            throw $this->finalizeWithoutReceipt($run, $claim['attempt'], $claim['lease'], 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS');
        }
        if (in_array($claimMode, ['invalid_state', 'invalid_lease'], true)) {
            throw $this->finalizeWithoutReceipt($run, $claim['attempt'], $claim['lease'], 'SAND_AI_TOOL_RUN_STATE_INVALID');
        }
        if ($claimMode !== 'dispatch') throw ToolRunError::problem('SAND_AI_TOOL_RUN_STATE_INVALID');
        /** @var ToolVersion $version */ $version = $claim['version'];
        /** @var ToolConnection $connection */ $connection = $claim['connection'];
        /** @var ToolConnectionRevision $revision */ $revision = $claim['revision'];
        /** @var ToolAttempt $attempt */ $attempt = $claim['attempt'];
        $lease = $claim['lease'];
        try {
            $config = $this->connectionConfig($revision);
        } catch (ApiProblem $problem) {
            throw $this->finalizeWithoutReceipt($run, $attempt, $lease, $problem->errorCode);
        } catch (\Throwable) {
            throw $this->finalizeWithoutReceipt($run, $attempt, $lease, 'SAND_AI_TOOL_CONFIGURATION_DRIFTED');
        }
        try {
            $endpoint = ($this->endpointPolicy ?? new ToolEndpointPolicy())->verify($config['origin'], $config['path']);
        } catch (ApiProblem $problem) {
            throw $this->finalizeWithoutReceipt($run, $attempt, $lease, $problem->errorCode);
        }
        $headers = [];
        if ($config['authorization']['mode'] === 'bearer') $headers['Authorization'] = 'Bearer ' . $config['authorization']['credential'];
        $authorizationRejected = null;
        try {
            $adapter = (string) (($run->execution_snapshot['adapter'] ?? '') ?: 'standard_readonly_http');
            if ($adapter === 'mcp_streamable_http') {
                $item = $this->mcpSnapshotItem($run, $version);
                $client = $this->mcpClient ?? new McpStreamableHttpClient(
                    $this->transport ?? new GuzzleStandardReadonlyHttpTransport(),
                    (int) config('sand_ai_mcp.max_pages', 20),
                    (int) config('sand_ai_mcp.max_tools', 500),
                    (int) config('sand_ai_mcp.max_schema_bytes', 524288),
                );
                $outcome = $client->call(
                    $endpoint,
                    $headers,
                    (int) $version->timeout_ms,
                    (int) $version->response_max_bytes,
                    $item,
                    $request['input'],
                    $this->mcpRegistry ?? new McpReadonlyToolRegistry(),
                    function (string $kind, int $sequence, string $eventKind, ?int $httpStatus) use ($revalidate, $decision, $run, $attempt, $lease, &$authorizationRejected): array {
                        if ($eventKind === 'dispatch_started') {
                            try {
                                $revalidate($run, $decision);
                            } catch (ApiProblem $problem) {
                                $authorizationRejected = $problem;
                                throw $problem;
                            }
                        }
                        return $this->markMcpExchangeEvent($run, $attempt, $lease, $kind, $sequence, $eventKind, $httpStatus);
                    },
                    McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null),
                );
            } else {
                $response = ($this->transport ?? new GuzzleStandardReadonlyHttpTransport())->request(
                    $endpoint,
                    $headers,
                    ['business_scope' => $request['business_scope'], 'input' => $request['input']],
                    (int) $version->timeout_ms,
                    (int) $version->response_max_bytes,
                    function () use ($revalidate, $decision, $run, $attempt, $version, $connection, $revision, $lease, &$authorizationRejected): array {
                        try {
                            $revalidate($run, $decision);
                        } catch (ApiProblem $problem) {
                            $authorizationRejected = $problem;
                            throw $problem;
                        }
                        return $this->markDispatched($run, $attempt, $version, $connection, $revision, $lease);
                    },
                );
                $outcome = ToolResponsePolicy::normalize(
                    $response,
                    (array) $version->output_schema,
                    $request['code'],
                    is_array($request['input']) && is_int($request['input']['limit'] ?? null) ? $request['input']['limit'] : 20,
                );
            }
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode, ['SAND_AI_BUDGET_UNPRICED', 'SAND_AI_BUDGET_SCOPE_UNVERIFIED'], true)) {
                throw $this->finalizeWithoutReceipt($run, $attempt, $lease, $problem->errorCode);
            }
            if ($authorizationRejected !== null) {
                $this->finalizeWithoutReceipt($run, $attempt, $lease, $authorizationRejected->errorCode);
                throw $authorizationRejected;
            }
            if ($adapter === 'standard_readonly_http') {
                if ($problem->errorCode === 'SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED') throw $this->finalizeWithoutReceipt($run, $attempt, $lease, $problem->errorCode);
                $outcome = ToolResponsePolicy::fromTransportProblem($problem);
            } else {
                $meter = $this->usageMeter ?? new ToolUsageMeter();
                $count = $meter->protocolRequestCount($run);
                if ($count === 0) throw $this->finalizeWithoutReceipt($run, $attempt, $lease, $problem->errorCode);
                try {
                    $outcome = $this->mcpFailureOutcome($meter->protocolRecoveryEvidence($run), $problem->errorCode);
                } catch (\Throwable) {
                    throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
                }
            }
        } catch (\Throwable) {
            try {
                $confirmedQuantity = $this->confirmedDispatchQuantity($run, $attempt, $adapter);
            } catch (\Throwable) {
                throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
            }
            if ($confirmedQuantity === 0) {
                throw $this->finalizeWithoutReceipt($run, $attempt, $lease, 'SAND_AI_TOOL_RUN_STATE_INVALID');
            }
            if ($adapter === 'mcp_streamable_http') {
                try {
                    $protocol = ($this->usageMeter ?? new ToolUsageMeter())->protocolRecoveryEvidence($run);
                    $outcome = $this->mcpFailureOutcome($protocol, null);
                } catch (\Throwable) {
                    throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
                }
            } else {
                $outcome = new ToolProviderOutcome('ambiguous', null, [], 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', null, 0);
            }
        }
        try {
            $this->persistUsageBeforeReceipt($run, $version, $connection, $revision, $adapter, $lease);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_TOOL_RUN_IN_PROGRESS') throw $problem;
            try {
                $confirmedQuantity = $this->confirmedDispatchQuantity($run, $attempt, $adapter);
            } catch (\Throwable) {
                throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
            }
            if ($confirmedQuantity === 0) {
                throw $this->finalizeWithoutReceipt($run, $attempt, $lease, 'SAND_AI_TOOL_RUN_STATE_INVALID');
            }
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        } catch (\Throwable) {
            try {
                $confirmedQuantity = $this->confirmedDispatchQuantity($run, $attempt, $adapter);
            } catch (\Throwable) {
                throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
            }
            if ($confirmedQuantity === 0) {
                throw $this->finalizeWithoutReceipt($run, $attempt, $lease, 'SAND_AI_TOOL_RUN_STATE_INVALID');
            }
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        try {
            $this->writeReceipt($run, $outcome, $lease);
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'External tool outcome could not be recorded', true);
        }
        try {
            $summary = $this->finalizeFromReceipt($run, $lease, false);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_TOOL_RECEIPT_INVALID') throw $problem;
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        } catch (\Throwable) {
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        if ($outcome->state !== 'succeeded') throw ToolRunError::problem((string) $outcome->errorCode);
        $revalidate($run, $decision);
        return $summary;
    }

    /** Pure lookup by the original tool request key; read owns current resource and parent authorization. */
    public function readByKey(IdentityContext $identity, string $key, Request $httpRequest, string $requestId): array
    {
        if ($identity->environmentId < 1 || trim($key) === '' || strlen($key) > 128) $this->invalid();
        $run = ToolRun::where('environment_id', $identity->environmentId)->where('request_id', trim($key))->find();
        if ($run === null || !$this->sameIdentity($run, $identity)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'External tool run not found');
        }
        return $this->read($identity, (int) $run->id, $httpRequest, $requestId);
    }

    /** Workload-only private projection of the original run; no recovery, transport or fee writes. */
    public function readPrivateFulltextByKey(IdentityContext $identity, string $key, Request $httpRequest, string $requestId): array
    {
        return Db::connect('pgsql')->transaction(function () use ($identity, $key, $httpRequest, $requestId): array {
        if ($identity->environmentId < 1 || trim($identity->workloadClientId) === ''
            || trim($key) === '' || strlen($key) > 128) $this->invalid();
        $run = ToolRun::where('environment_id', $identity->environmentId)->where('request_id', trim($key))->find();
        if ($run === null || !$this->sameIdentity($run, $identity)
            || ($run->execution_snapshot['result_visibility'] ?? null) !== 'private_fulltext') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private fulltext tool run not found');
        }
        $guard = $this->accessGuard ?? new ToolRunAccessGuard();
        $decision = $guard->authorize($httpRequest, $identity, (int) $run->id, 'sand_ai.tool.read', $requestId,
            $run->execution_snapshot['data_class'] ?? null);
        $parentRevalidate = (new \app\Application\Agent\AgentReadonlyToolReadGuard())->authorizeParent($httpRequest, $identity, $run, $requestId);
        $summary = $this->summary($run, false);
        $summary['private_file_id'] = (new ToolFulltextFileGateway())->boundFile($identity, $run, $httpRequest, $requestId);
        if ((string) $run->state === 'succeeded') {
            $version = ToolVersion::find((int) $run->tool_version_id);
            $result = $run->result;
            if ($version === null || !is_array($result) || array_is_list($result)
                || !is_string($summary['tool']) || !is_int($summary['version']) || $summary['version'] < 1
                || array_diff(array_keys($result), ['content', 'title', 'source_version', 'license_scope']) !== []
                || !is_string($result['content'] ?? null) || !is_string($result['title'] ?? null)) {
                throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'Original private fulltext result is unavailable');
            }
            ToolSchemaPolicy::assertValue((array) $version->output_schema, $result);
            $sources = $summary['citations'];
            if (count($sources) !== 1 || !is_array($sources[0])
                || !is_string($sources[0]['source_ref'] ?? null)
                || !str_starts_with($sources[0]['source_ref'], 'legal_fulltext:')
                || !is_string($sources[0]['content_hash'] ?? null)
                || !hash_equals($sources[0]['content_hash'], hash('sha256', $result['content']))) {
                throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'Original private fulltext source hash changed');
            }
            $summary['result'] = $result;
        }
        $guard->revalidate($httpRequest, $identity, (int) $run->id, 'sand_ai.tool.read', $requestId, $decision);
        $parentRevalidate();
        return $summary;
        });
    }

    /** @return array<string,mixed> */
    public function read(IdentityContext $identity, int $runId, Request $httpRequest, string $requestId): array
    {
        if ($runId < 1) $this->invalid();
        $run = ToolRun::where('id', $runId)->where('environment_id', $identity->environmentId)->find();
        if ($run === null || !$this->sameIdentity($run, $identity)) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'External tool run not found');
        $guard = $this->accessGuard ?? new ToolRunAccessGuard();
        $decision = $guard->authorize($httpRequest, $identity, $runId, 'sand_ai.tool.read', $requestId, $run->execution_snapshot['data_class'] ?? null);
        $parentRevalidate = (new \app\Application\Agent\AgentReadonlyToolReadGuard())->authorizeParent($httpRequest, $identity, $run, $requestId);
        $summary = $this->summary($run, false);
        $guard->revalidate($httpRequest, $identity, $runId, 'sand_ai.tool.read', $requestId, $decision);
        $parentRevalidate();
        return $summary;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function claim(IdentityContext $identity, array $request): array
    {
        return Db::connect('pgsql')->transaction(function () use ($identity, $request): array {
            $existing = ToolRun::where('environment_id', $identity->environmentId)->where('request_id', $request['idempotency_key'])->lock(true)->find();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_payload_fingerprint, $request['payload_fingerprint']) || !$this->sameIdentity($existing, $identity)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Tool Idempotency-Key belongs to another request');
                }
                if ((string) $existing->state !== 'running') return ['replay' => true, 'run' => $existing];
                $attempt = ToolAttempt::where('tool_run_id', (int) $existing->id)->where('attempt_no', 1)->lock(true)->find();
                if ($attempt === null) throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool attempt is unavailable');
                $invalidLease = !is_string($existing->lease_expires_at) || trim($existing->lease_expires_at) === '';
                if (!$invalidLease && !$this->expired($existing->lease_expires_at)) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
                $lease = $this->lease((int) $existing->lease_fence + 1, (int) $existing->timeout_ms);
                $updated = ToolRun::where('id', (int) $existing->id)->where('state', 'running')->where('lease_fence', (int) $existing->lease_fence)->update($lease);
                if ($updated !== 1) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
                $existing = ToolRun::find((int) $existing->id) ?? $existing;
                $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $existing->id)->find();
                if ($receipt !== null) {
                    if ((string) $attempt->state !== 'dispatched') {
                        return ['invalid_state' => true, 'run' => $existing, 'attempt' => $attempt, 'lease' => $lease];
                    }
                    if (!is_string($receipt->expire_time) || $receipt->expire_time <= gmdate('Y-m-d H:i:s')) {
                        return ['ambiguous' => true, 'run' => $existing, 'attempt' => $attempt, 'lease' => $lease];
                    }
                    return ['recover' => true, 'run' => $existing, 'lease' => $lease];
                }
                if ((string) $attempt->state === 'dispatched') return ['recover_without_receipt' => true, 'run' => $existing, 'attempt' => $attempt, 'lease' => $lease];
                if ((string) $attempt->state !== 'prepared') throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool attempt state is invalid');
                if ($invalidLease) return ['invalid_lease' => true, 'run' => $existing, 'attempt' => $attempt, 'lease' => $lease];
                ($this->receiptCipher ?? new ToolOutcomeReceiptCipher())->assertAvailable();
                $resources = $this->resourcesForRun($existing);
                return ['run' => $existing, 'attempt' => $attempt, 'lease' => $lease] + $resources;
            }

            $version = ToolVersion::where('version_no', $request['version_no'])
                ->join('sand_ai_tool tool', 'tool.id=sand_ai_tool_version.tool_id')->where('tool.code', $request['code'])
                ->whereIn('tool.adapter', ['standard_readonly_http', 'mcp_streamable_http'])->where('tool.effect_class', 'read_only')
                ->where('tool.state', 'published')->where('tool.status', 1)->where('sand_ai_tool_version.state', 'published')
                ->field('sand_ai_tool_version.*')->lock(true)->find();
            if ($version === null) throw new ApiProblem('SAND_AI_TOOL_VERSION_NOT_PUBLISHED', 'External tool version is not published');
            ToolSchemaPolicy::assertValue((array) $version->input_schema, $request['input']);
            $tool = Tool::where('id', (int) $version->tool_id)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $binding = ToolEnvironmentBinding::where('environment_id', $identity->environmentId)->where('tool_id', (int) $version->tool_id)->where('tool_version_id', (int) $version->id)->where('state', 'published')->where('status', 1)->lock(true)->find();
            if ($tool === null || $binding === null) throw new ApiProblem('SAND_AI_TOOL_NOT_BOUND', 'External tool version is not bound in this environment');
            $connection = ToolConnection::where('id', (int) $binding->tool_connection_id)->where('environment_id', $identity->environmentId)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $revision = ToolConnectionRevision::where('id', (int) $binding->tool_connection_revision_id)->where('tool_connection_id', (int) $binding->tool_connection_id)->where('state', 'published')->lock(true)->find();
            if ($connection === null || $revision === null) throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool connection is unavailable');
            $dataClass = $revision->data_class;
            if (!is_string($dataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1) {
                throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool source classification is unavailable');
            }
            $mcpItem = null;
            $mcpProtocolVersion = McpStreamableHttpClient::PROTOCOL_VERSION;
            if ((string) $tool->adapter === 'mcp_streamable_http') {
                $mcpItem = ToolMcpSnapshotItem::where('id', (int) $version->mcp_snapshot_item_id)->lock(true)->find();
                $mcpSnapshot = $mcpItem === null ? null : ToolMcpSnapshot::where('id', (int) $mcpItem->mcp_snapshot_id)->where('state', 'tested')->find();
                if ($mcpItem === null || $mcpSnapshot === null
                    || (int) $mcpSnapshot->environment_id !== $identity->environmentId
                    || (int) $mcpSnapshot->tool_connection_id !== (int) $binding->tool_connection_id
                    || (int) $mcpSnapshot->tool_connection_revision_id !== (int) $binding->tool_connection_revision_id) {
                    throw new ApiProblem('SAND_AI_MCP_SNAPSHOT_NOT_TESTED', 'Published MCP tool snapshot does not match the bound environment and connection revision');
                }
                $mcpProtocolVersion = (string) $mcpSnapshot->protocol_version;
                McpStreamableHttpClient::assertProtocolVersion($mcpProtocolVersion);
                McpSnapshotPolicy::assertFrozen($this->snapshotItem($mcpItem), $this->mcpRegistry ?? new McpReadonlyToolRegistry());
            } elseif ($version->mcp_snapshot_item_id !== null) {
                throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Standard HTTP tool cannot use an MCP snapshot item');
            }
            $snapshot = [
                'adapter' => (string) $tool->adapter, 'effect_class' => 'read_only', 'tool_id' => (int) $tool->id,
                'tool_version_id' => (int) $version->id, 'tool_version' => (int) $version->version_no,
                'binding_id' => (int) $binding->id, 'connection_id' => (int) $connection->id,
                'connection_revision_id' => (int) $revision->id, 'connection_config_fingerprint' => (string) $revision->config_fingerprint,
                'data_class' => $dataClass,
                'timeout_ms' => (int) $version->timeout_ms, 'response_max_bytes' => (int) $version->response_max_bytes,
                'retry_policy' => 'none', 'request_method' => 'POST',
                'wire_contract' => (string) $tool->adapter === 'mcp_streamable_http' ? 'mcp_streamable_http/' . $mcpProtocolVersion . '/json' : 'standard_readonly_http/v1',
                'mcp_snapshot_item_id' => $mcpItem === null ? null : (int) $mcpItem->id,
                'mcp_snapshot_item_hash' => $mcpItem === null ? null : (string) $mcpItem->item_hash,
            ];
            if ($mcpItem !== null) {
                $definition = ($this->mcpRegistry ?? new McpReadonlyToolRegistry())->require((string) $mcpItem->local_tool_code);
                if (LegalFulltextAdapter::supports($definition['adapter']['kind'] ?? null)) {
                    // Persist the projection boundary with the original request fingerprint.
                    $snapshot['result_visibility'] = 'private_fulltext';
                }
            }
            $requestFingerprint = ToolFingerprint::for('tool_run_execution', ['request' => $request, 'execution_snapshot' => $snapshot]);
            ($this->receiptCipher ?? new ToolOutcomeReceiptCipher())->assertAvailable();
            $lease = $this->lease(1, (int) $version->timeout_ms);
            $run = ToolRun::create([
                'environment_id' => $identity->environmentId, 'organization_id' => $this->numeric($identity->organizationId),
                'application_id' => $this->numeric($identity->applicationId), 'workload_client_id' => $this->numeric($identity->workloadClientId),
                'identity_snapshot' => $this->identitySnapshot($identity), 'tool_id' => (int) $tool->id, 'tool_version_id' => (int) $version->id,
                'tool_environment_binding_id' => (int) $binding->id, 'tool_connection_id' => (int) $connection->id,
                'tool_connection_revision_id' => (int) $revision->id, 'request_id' => $request['idempotency_key'],
                'request_payload_fingerprint' => $request['payload_fingerprint'], 'request_fingerprint' => $requestFingerprint,
                'business_scope' => $request['business_scope'], 'input' => $request['input'], 'execution_snapshot' => $snapshot,
                ...(!isset($request['agent_parent']) ? [] : ['parent_task_run_id' => $request['agent_parent']['task_id'], 'parent_task_step_id' => $request['agent_parent']['step_id']]),
                'timeout_ms' => (int) $version->timeout_ms, 'mcp_snapshot_item_id' => $mcpItem === null ? null : (int) $mcpItem->id, 'state' => 'running', ...$lease,
            ]);
            $attempt = ToolAttempt::create(['tool_run_id' => (int) $run->id, 'attempt_no' => 1, 'state' => 'prepared', 'dispatch_fingerprint' => ToolFingerprint::for('tool_dispatch', ['run_id' => (int) $run->id, 'request_fingerprint' => $requestFingerprint])]);
            return compact('run', 'attempt', 'version', 'connection', 'revision', 'lease');
        });
    }

    /** @return array{version:ToolVersion,connection:ToolConnection,revision:ToolConnectionRevision} */
    private function resourcesForRun(ToolRun $run): array
    {
        $version = ToolVersion::where('id', (int) $run->tool_version_id)->find();
        $connection = ToolConnection::where('id', (int) $run->tool_connection_id)->find();
        $revision = ToolConnectionRevision::where('id', (int) $run->tool_connection_revision_id)->find();
        if ($version === null || $connection === null || $revision === null) throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Frozen external tool resources are unavailable');
        return compact('version', 'connection', 'revision');
    }

    /** @return array<string,mixed> */
    private function markDispatched(ToolRun $run, ToolAttempt $attempt, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, array $lease): array
    {
        return Db::connect('pgsql')->transaction(function () use ($run, $attempt, $version, $connection, $revision, $lease): array {
            $this->assertUnpricedDispatchAllowed($run);
            $lockedRun = ToolRun::where('id', (int) $run->id)->where('state', 'running')->lock(true)->find();
            $lockedAttempt = ToolAttempt::where('id', (int) $attempt->id)->where('state', 'prepared')->lock(true)->find();
            if ($lockedRun === null || $lockedAttempt === null || !$this->owns($lockedRun, $lease)) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            try {
                $metering = ($this->usageMeter ?? new ToolUsageMeter())->record($lockedRun, $version, $connection, $revision);
                $lockedAttempt->save(['state' => 'dispatched', 'dispatched_time' => gmdate('Y-m-d H:i:s')]);
                return $metering;
            } catch (ApiProblem $problem) {
                throw $problem;
            } catch (\Throwable) {
                throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage could not be recorded', true);
            }
        });
    }

    /** @param array<string,mixed> $lease @return array<string,mixed> */
    private function markMcpExchangeEvent(ToolRun $run, ToolAttempt $attempt, array $lease, string $kind, int $sequence, string $eventKind, ?int $httpStatus): array
    {
        return Db::connect('pgsql')->transaction(function () use ($run, $attempt, $lease, $kind, $sequence, $eventKind, $httpStatus): array {
            if ($eventKind === 'dispatch_started') {
                $this->assertUnpricedDispatchAllowed($run);
            }
            $lockedRun = ToolRun::where('id', (int) $run->id)->where('state', 'running')->lock(true)->find();
            $lockedAttempt = ToolAttempt::where('id', (int) $attempt->id)->lock(true)->find();
            if ($lockedRun === null || $lockedAttempt === null || !$this->owns($lockedRun, $lease)
                || !in_array((string) $lockedAttempt->state, ['prepared', 'dispatched'], true)) {
                throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            }
            $meter = $this->usageMeter ?? new ToolUsageMeter();
            $meter->recordProtocolExchangeEvent($lockedRun, $kind, $sequence, $eventKind, $httpStatus);
            if ($eventKind === 'dispatch_started' && (string) $lockedAttempt->state === 'prepared') {
                $lockedAttempt->save(['state' => 'dispatched', 'dispatched_time' => gmdate('Y-m-d H:i:s')]);
            }
            return ['unit' => 'external_request', 'quantity' => $meter->protocolRequestCount($lockedRun), 'pricing_state' => 'unpriced', 'metered_amount' => null, 'reconciled_amount' => null, 'currency' => null];
        });
    }

    private function assertUnpricedDispatchAllowed(ToolRun $run): void
    {
        try {
            $attribution = UsageAttribution::fromCurrentPersisted([
                'format_version' => UsageAttribution::PERSISTED_FORMAT_VERSION,
                'environment_id' => (int) $run->environment_id,
                'organization_id' => $run->organization_id === null ? null : (int) $run->organization_id,
                'application_id' => $run->application_id === null ? null : (int) $run->application_id,
                'workload_client_id' => $run->workload_client_id === null ? null : (int) $run->workload_client_id,
                'identity_snapshot' => is_array($run->identity_snapshot) ? $run->identity_snapshot : null,
                'capability_code' => 'external_readonly',
            ], (int) $run->environment_id, 'tool', 'sand_ai.tool.run');
        } catch (\InvalidArgumentException $exception) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Frozen tool identity cannot be verified for budget checking');
        }
        (new BudgetPolicyService())->assertUnpricedCallAllowed($attribution, 'external_readonly', gmdate('Y-m-d H:i:s'));
    }

    /** @param array<string,mixed> $lease */
    private function writeReceipt(ToolRun $run, ToolProviderOutcome $outcome, array $lease): void
    {
        ($this->boundaryPause ?? new NullToolRunBoundaryPause())->pause('before_receipt', $run, $lease);
        Db::connect('pgsql')->transaction(function () use ($run, $outcome, $lease): void {
            $lockedRun = ToolRun::where('id', (int) $run->id)->lock(true)->find();
            if ($lockedRun === null || (string) $lockedRun->state !== 'running' || !$this->owns($lockedRun, $lease)) {
                throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            }
            $existing = ToolOutcomeReceipt::where('tool_run_id', (int) $lockedRun->id)->lock(true)->find();
            if ($existing !== null) {
                $decoded = $this->decodeReceipt($lockedRun, $existing);
                if (!hash_equals($decoded->encode(), $outcome->encode())) throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool outcome receipt conflicts with the original result');
                return;
            }
            $cipher = $this->receiptCipher ?? new ToolOutcomeReceiptCipher();
            $envelope = $cipher->encrypt($outcome->encode(), $this->receiptContext($lockedRun));
            $rows = Db::connect('pgsql')->query(
                "INSERT INTO sand_ai_tool_outcome_receipt
                    (tool_run_id, environment_id, tool_id, tool_version_id, request_fingerprint,
                     receipt_version, cipher_version, key_id, nonce, tag, ciphertext,
                     plaintext_bytes, ciphertext_bytes, content_hmac, state, expire_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), decode(?, 'hex'), ?, ?, decode(?, 'hex'), 'available', ?)
                 RETURNING id",
                [
                    (int) $lockedRun->id, (int) $lockedRun->environment_id, (int) $lockedRun->tool_id,
                    (int) $lockedRun->tool_version_id, (string) $lockedRun->request_fingerprint,
                    $envelope['receipt_version'], $envelope['cipher_version'], $envelope['key_id'],
                    bin2hex($envelope['nonce']), bin2hex($envelope['tag']), bin2hex($envelope['ciphertext']),
                    $envelope['plaintext_bytes'], $envelope['ciphertext_bytes'], bin2hex($envelope['content_hmac']),
                    gmdate('Y-m-d H:i:s', time() + $cipher->ttlSeconds()),
                ],
            );
            if (!isset($rows[0]['id'])) {
                throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'External tool outcome could not be recorded', true);
            }
        });
    }

    /** @return array<string,mixed> */
    private function recover(ToolRun $run, array $lease): array
    {
        try {
            $summary = $this->finalizeFromReceipt($run, $lease, true);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_TOOL_RECEIPT_INVALID') throw $problem;
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        } catch (\Throwable) {
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        if (($summary['state'] ?? null) !== 'succeeded') throw ToolRunError::persisted((array) ($summary['error'] ?? []));
        return $summary;
    }

    private function recoverWithoutReceipt(ToolRun $run, ToolAttempt $attempt, array $lease): never
    {
        try {
            $decision = Db::connect('pgsql')->transaction(function () use ($run, $attempt, $lease): array {
                $locked = ToolRun::where('id', (int) $run->id)->where('state', 'running')->lock(true)->find();
                $lockedAttempt = ToolAttempt::where('id', (int) $attempt->id)->where('state', 'dispatched')->lock(true)->find();
                if ($locked === null || $lockedAttempt === null || !$this->owns($locked, $lease)) {
                    throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
                }
                if (ToolOutcomeReceipt::where('tool_run_id', (int) $locked->id)->lock(true)->find() !== null) {
                    throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
                }
                $resources = $this->resourcesForRun($locked);
                $adapter = (string) (($locked->execution_snapshot['adapter'] ?? '') ?: 'standard_readonly_http');
                $meter = $this->usageMeter ?? new ToolUsageMeter();
                $sealedQuantity = $meter->recordedQuantity($locked, $resources['version'], $resources['connection'], $resources['revision'], $adapter);
                $protocol = $adapter === 'mcp_streamable_http' ? $meter->protocolRecoveryEvidence($locked) : null;
                $decision = ToolNoReceiptRecoveryPolicy::decide($adapter, $sealedQuantity, $protocol);
                if ($decision['materialize_usage']) {
                    $quantity = $decision['quantity'];
                    $meter->recordQuantity($locked, $resources['version'], $resources['connection'], $resources['revision'], $quantity, $adapter);
                }
                return $decision;
            });
        } catch (\Throwable) {
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        ($this->boundaryPause ?? new NullToolRunBoundaryPause())->pause('before_recovery_terminal', $run, $lease);
        try {
            $problem = $this->finalizeWithoutReceipt($run, $attempt, $lease, $decision['terminal_error_code']);
        } catch (\Throwable) {
            throw ToolRunError::problem('SAND_AI_TOOL_FINALIZATION_PENDING');
        }
        throw $problem;
    }

    /** @return array<string,mixed> */
    private function finalizeFromReceipt(ToolRun $run, array $lease, bool $recovered): array
    {
        if ($recovered) ($this->boundaryPause ?? new NullToolRunBoundaryPause())->pause('before_recovery_terminal', $run, $lease);
        ($this->boundaryPause ?? new NullToolRunBoundaryPause())->pause('before_finalize', $run, $lease);
        return Db::connect('pgsql')->transaction(function () use ($run, $lease, $recovered): array {
            $locked = ToolRun::where('id', (int) $run->id)->lock(true)->find();
            if ($locked === null) throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool run is unavailable');
            if (!$this->owns($locked, $lease)) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            if ((string) $locked->state !== 'running') return $this->summary($locked, true);
            $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $locked->id)->where('state', 'available')->lock(true)->find();
            if ($receipt === null || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')) throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt is unavailable for replay');
            $outcome = $this->decodeReceipt($locked, $receipt);
            $attempt = ToolAttempt::where('tool_run_id', (int) $locked->id)->where('attempt_no', 1)->lock(true)->find();
            if ($attempt === null || (string) $attempt->state !== 'dispatched') throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool attempt is unavailable');
            $resources = $this->resourcesForRun($locked);
            $adapter = (string) (($locked->execution_snapshot['adapter'] ?? '') ?: 'standard_readonly_http');
            $meter = $this->usageMeter ?? new ToolUsageMeter();
            $quantity = $adapter === 'mcp_streamable_http' ? $meter->protocolRequestCount($locked) : 1;
            if ($quantity < 1) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage is unavailable', true);
            $metering = $meter->requireRecordedQuantity($locked, $resources['version'], $resources['connection'], $resources['revision'], $quantity, $adapter);
            $audit = AuditLog::create(['actor_type' => 'workload', 'actor_ref' => (string) (($locked->identity_snapshot['workload_client_ref'] ?? '') ?: 'sandiam-workload'), 'action' => 'sand_ai.tool.run', 'resource_type' => 'tool_run', 'resource_id' => (int) $locked->id, 'summary' => 'SandAI managed external tool run finalized', 'context' => ['tool_id' => (int) $locked->tool_id, 'tool_version_id' => (int) $locked->tool_version_id, 'state' => $outcome->state, 'recovered' => $recovered, 'adapter' => $adapter, 'external_request_quantity' => $quantity]]);
            $changes = ['state' => $outcome->state === 'succeeded' ? 'succeeded' : ($outcome->state === 'ambiguous' ? 'ambiguous' : 'failed'), 'latency_ms' => $outcome->latencyMs, 'completed_time' => gmdate('Y-m-d H:i:s'), 'lease_expires_at' => null, 'audit_log_id' => (int) $audit->id, 'metering' => $metering];
            if ($outcome->state === 'succeeded') {
                $frozenVersion = ToolVersion::where('id', (int) $locked->tool_version_id)->find();
                if ($frozenVersion === null || !is_array($frozenVersion->review_policy)) {
                    throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Published external tool review policy is unavailable');
                }
                $review = $frozenVersion->review_policy;
                $changes += ['result' => json_encode($outcome->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'citations' => $outcome->citations, 'review_required' => (bool) ($review['review_required'] ?? false)];
                foreach ($outcome->citations as $sequence => $source) ToolSource::create(['tool_run_id' => (int) $locked->id, 'sequence_no' => $sequence + 1, 'source_ref' => $source['source_ref'], 'locator' => $source['locator'], 'source_version' => $source['source_version'], 'content_hash' => $source['content_hash']]);
            } else {
                $changes += ['error_code' => $outcome->errorCode, 'error_detail' => ToolRunError::fromCode((string) $outcome->errorCode)];
            }
            $locked->save($changes);
            $attempt->save(['state' => $outcome->state === 'succeeded' ? 'succeeded' : ($outcome->state === 'ambiguous' ? 'ambiguous' : 'failed'), 'http_status' => $outcome->httpStatus, 'error_code' => $outcome->errorCode, 'completed_time' => gmdate('Y-m-d H:i:s')]);
            return $this->summary($locked, $recovered);
        });
    }

    private function finalizeWithoutReceipt(ToolRun $run, ToolAttempt $attempt, array $lease, string $errorCode): ApiProblem
    {
        Db::connect('pgsql')->transaction(function () use ($run, $attempt, $lease, $errorCode): void {
            $locked = ToolRun::where('id', (int) $run->id)->where('state', 'running')->lock(true)->find();
            $lockedAttempt = ToolAttempt::where('id', (int) $attempt->id)->lock(true)->find();
            if ($locked === null || $lockedAttempt === null || !$this->owns($locked, $lease)) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            $audit = AuditLog::create(['actor_type' => 'system', 'actor_ref' => 'sand-ai-tool-runtime', 'action' => 'sand_ai.tool.run_rejected', 'resource_type' => 'tool_run', 'resource_id' => (int) $locked->id, 'summary' => 'SandAI external tool run stopped without an external retry', 'context' => ['error_code' => $errorCode]]);
            $state = $errorCode === 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS' ? 'ambiguous' : 'failed';
            $changes = ['state' => $state, 'error_code' => $errorCode, 'error_detail' => ToolRunError::fromCode($errorCode), 'lease_expires_at' => null, 'completed_time' => gmdate('Y-m-d H:i:s'), 'audit_log_id' => (int) $audit->id];
            $adapter = (string) (($locked->execution_snapshot['adapter'] ?? '') ?: 'standard_readonly_http');
            if ($adapter === 'mcp_streamable_http') {
                $resources = $this->resourcesForRun($locked);
                $meter = $this->usageMeter ?? new ToolUsageMeter();
                $quantity = $meter->protocolRequestCount($locked);
                if ($quantity > 0) $changes['metering'] = $meter->recordQuantity($locked, $resources['version'], $resources['connection'], $resources['revision'], $quantity, $adapter);
            } elseif ($state === 'ambiguous') {
                $resources = $this->resourcesForRun($locked);
                $changes['metering'] = ($this->usageMeter ?? new ToolUsageMeter())->requireRecordedQuantity($locked, $resources['version'], $resources['connection'], $resources['revision'], 1, $adapter);
            }
            $locked->save($changes);
            $attemptState = $state === 'ambiguous' ? 'ambiguous'
                : ((string) $lockedAttempt->state === 'dispatched' ? 'failed' : 'cancelled');
            $lockedAttempt->save(['state' => $attemptState, 'error_code' => $errorCode, 'completed_time' => gmdate('Y-m-d H:i:s')]);
        });
        return ToolRunError::problem($errorCode);
    }

    private function confirmedDispatchQuantity(ToolRun $run, ToolAttempt $attempt, string $adapter): int
    {
        if ($adapter === 'mcp_streamable_http') {
            return ($this->usageMeter ?? new ToolUsageMeter())->protocolRequestCount($run);
        }
        $current = ToolAttempt::where('id', (int) $attempt->id)->find();
        return $current !== null && (string) $current->state === 'dispatched' ? 1 : 0;
    }

    /**
     * @param array{request_count:int,tools_call_dispatched:bool,tools_call_response_known:bool,tools_call_http_status:?int} $protocol
     */
    private function mcpFailureOutcome(array $protocol, ?string $problemCode): ToolProviderOutcome
    {
        if (!$protocol['tools_call_dispatched']) {
            return new ToolProviderOutcome('failed', null, [], 'SAND_AI_MCP_TOOL_NOT_DISPATCHED', null, 0);
        }
        if (!$protocol['tools_call_response_known']) {
            return new ToolProviderOutcome('ambiguous', null, [], 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', null, 0);
        }
        $status = $protocol['tools_call_http_status'];
        if (!is_int($status)) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request recovery evidence is incomplete', true);
        if ($status >= 300) {
            return new ToolProviderOutcome('failed', null, [], 'SAND_AI_MCP_UPSTREAM_REJECTED', $status, 0);
        }
        $ambiguous = $problemCode === null || in_array($problemCode, [
            'SAND_AI_TOOL_DISPATCH_AMBIGUOUS',
            'SAND_AI_TOOL_TIMEOUT',
            'SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED',
        ], true);
        return new ToolProviderOutcome(
            $ambiguous ? 'ambiguous' : 'failed',
            null,
            [],
            $ambiguous ? 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS' : $problemCode,
            $status,
            0,
        );
    }

    /** @param array<string,mixed> $lease */
    private function persistUsageBeforeReceipt(ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, string $adapter, array $lease): void
    {
        ($this->boundaryPause ?? new NullToolRunBoundaryPause())->pause('before_usage', $run, $lease);
        Db::connect('pgsql')->transaction(function () use ($run, $version, $connection, $revision, $adapter, $lease): void {
            $locked = ToolRun::where('id', (int) $run->id)->where('state', 'running')->lock(true)->find();
            if ($locked === null || !$this->owns($locked, $lease)) throw ToolRunError::problem('SAND_AI_TOOL_RUN_IN_PROGRESS');
            $meter = $this->usageMeter ?? new ToolUsageMeter();
            if ($adapter === 'mcp_streamable_http') {
                $quantity = $meter->protocolRequestCount($locked);
                if ($quantity < 1) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request usage evidence is unavailable', true);
                $meter->recordQuantity($locked, $version, $connection, $revision, $quantity, $adapter);
                return;
            }
            $meter->requireRecordedQuantity($locked, $version, $connection, $revision, 1, $adapter);
        });
    }

    /** Original Agent tool receipt proof, without execution or terminalization. */
    public function assertAgentReceiptAvailable(\app\Infrastructure\Persistence\Model\TaskRun $task, ToolRun $run): void
    {
        $attempt = ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->find();
        $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->where('state', 'available')->find();
        if ((int) $run->parent_task_run_id !== (int) $task->id || (int) $run->environment_id !== (int) $task->environment_id
            || (string) $run->state !== 'succeeded' || $attempt === null || (string) $attempt->state !== 'succeeded'
            || $receipt === null || (string) $receipt->expire_time <= gmdate('Y-m-d H:i:s')) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'Original Agent tool receipt is unavailable');
        }
        $this->decodeReceipt($run, $receipt);
    }

    private function decodeReceipt(ToolRun $run, ToolOutcomeReceipt $receipt): ToolProviderOutcome
    {
        $this->assertReceiptBinding($run, $receipt);
        $record = [];
        foreach (['receipt_version', 'cipher_version', 'key_id', 'nonce', 'tag', 'ciphertext', 'plaintext_bytes', 'ciphertext_bytes', 'content_hmac'] as $field) $record[$field] = $receipt->getAttr($field);
        return ToolProviderOutcome::decode(($this->receiptCipher ?? new ToolOutcomeReceiptCipher())->decrypt($record, $this->receiptContext($run)));
    }

    private function assertReceiptBinding(ToolRun $run, ToolOutcomeReceipt $receipt): void
    {
        foreach (['environment_id', 'tool_id', 'tool_version_id'] as $field) {
            if ((int) $receipt->getAttr($field) !== (int) $run->getAttr($field)) {
                throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt identity does not match its run');
            }
        }
        if ((int) $receipt->tool_run_id !== (int) $run->id
            || !is_string($receipt->request_fingerprint) || !is_string($run->request_fingerprint)
            || !hash_equals($run->request_fingerprint, $receipt->request_fingerprint)) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt identity does not match its run');
        }
    }

    /** @return array{environment_id:int,tool_id:int,tool_version_id:int,tool_run_id:int,request_fingerprint:string,version:string} */
    private function receiptContext(ToolRun $run): array
    {
        $adapter = (string) (($run->execution_snapshot['adapter'] ?? '') ?: 'standard_readonly_http');
        $wireContract = 'standard_readonly_http/v1';
        if ($adapter === 'mcp_streamable_http') {
            $protocol = McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null);
            $wireContract = 'mcp_streamable_http/' . $protocol . '/json';
        }
        return ['environment_id' => (int) $run->environment_id, 'tool_id' => (int) $run->tool_id, 'tool_version_id' => (int) $run->tool_version_id, 'tool_run_id' => (int) $run->id, 'request_fingerprint' => (string) $run->request_fingerprint, 'version' => $wireContract];
    }

    /** @return array<string,mixed> */
    private function mcpSnapshotItem(ToolRun $run, ToolVersion $version): array
    {
        if ((int) $run->mcp_snapshot_item_id < 1 || (int) $version->mcp_snapshot_item_id !== (int) $run->mcp_snapshot_item_id
            || (int) ($run->execution_snapshot['mcp_snapshot_item_id'] ?? 0) !== (int) $run->mcp_snapshot_item_id) {
            throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Published MCP snapshot binding is inconsistent');
        }
        $item = ToolMcpSnapshotItem::find((int) $run->mcp_snapshot_item_id);
        if ($item === null) throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Published MCP snapshot item is unavailable');
        $snapshot = ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)->where('state', 'tested')->find();
        if ($snapshot === null || !hash_equals((string) ($run->execution_snapshot['mcp_snapshot_item_hash'] ?? ''), (string) $item->item_hash)
            || (int) $snapshot->environment_id !== (int) $run->environment_id
            || (int) $snapshot->tool_connection_id !== (int) $run->tool_connection_id
            || (int) $snapshot->tool_connection_revision_id !== (int) $run->tool_connection_revision_id
            || ToolFingerprint::canonicalJson((array) $version->input_schema) !== ToolFingerprint::canonicalJson((array) $item->input_schema)
            || ToolFingerprint::canonicalJson((array) $version->output_schema) !== ToolFingerprint::canonicalJson((array) $item->output_schema)) {
            throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Published MCP snapshot item changed after claim');
        }
        $protocolVersion = McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null);
        if ((string) $snapshot->protocol_version !== $protocolVersion) throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Frozen MCP protocol changed after claim');
        $snapshotItems = [];
        foreach (ToolMcpSnapshotItem::where('mcp_snapshot_id', (int) $snapshot->id)->order('sequence_no', 'asc')->select() as $snapshotItem) {
            $snapshotItems[] = $this->snapshotItem($snapshotItem);
        }
        if ((int) $snapshot->item_count !== count($snapshotItems)) throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Published MCP snapshot item count changed');
        McpSnapshotPolicy::assertSnapshot((string) $snapshot->snapshot_hash, $snapshotItems, $this->mcpRegistry ?? new McpReadonlyToolRegistry(), $protocolVersion);
        return $this->snapshotItem($item);
    }

    /** @return array<string,mixed> */
    private function snapshotItem(ToolMcpSnapshotItem $item): array
    {
        return [
            'local_tool_code' => (string) $item->local_tool_code,
            'remote_name' => (string) $item->remote_name,
            'remote_description' => (string) $item->remote_description,
            'remote_input_schema' => (array) $item->remote_input_schema,
            'remote_output_schema' => is_array($item->remote_output_schema) ? $item->remote_output_schema : null,
            'input_schema' => (array) $item->input_schema,
            'output_schema' => (array) $item->output_schema,
            'citation_mode' => (string) $item->citation_mode,
            'registry_definition_hash' => (string) $item->registry_definition_hash,
            'item_hash' => (string) $item->item_hash,
        ];
    }

    /** @return array<string,mixed> */
    private function replayTerminal(ToolRun $run): array
    {
        if (in_array((string) $run->state, ['failed', 'ambiguous'], true)) throw ToolRunError::persisted(is_array($run->error_detail) ? $run->error_detail : []);
        if ((string) $run->state !== 'succeeded') throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool run state is invalid');
        return $this->summary($run, true);
    }

    /** @return array<string,mixed> */
    private function summary(ToolRun $run, bool $replay): array
    {
        $tool = Tool::find((int) $run->tool_id); $version = ToolVersion::find((int) $run->tool_version_id);
        $citations = is_array($run->citations) ? $run->citations : [];
        $private = ($run->execution_snapshot['result_visibility'] ?? null) === 'private_fulltext';
        // The opaque source family also preserves privacy if an old snapshot is incomplete.
        foreach ($citations as $citation) {
            if (is_array($citation) && is_string($citation['source_ref'] ?? null)
                && str_starts_with($citation['source_ref'], 'legal_fulltext:')) $private = true;
        }
        return ['run_id' => (int) $run->id, 'tool' => $tool === null ? null : (string) $tool->code, 'version' => $version === null ? null : (int) $version->version_no, 'state' => (string) $run->state, 'result' => $private ? null : $run->result, 'citations' => $citations, 'review_required' => (bool) $run->review_required, 'metering' => $run->metering, 'audit_ref' => $run->audit_log_id === null ? null : 'audit:' . (int) $run->audit_log_id, 'error' => $run->error_detail, 'idempotent_replay' => $replay];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function request(IdentityContext $identity, string $code, int $versionNo, string $businessScope, array|\stdClass $input, string $key): array
    {
        $key = trim($key); $businessScope = trim($businessScope);
        if ($identity->environmentId < 1 || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1 || $versionNo < 1 || $key === '' || strlen($key) > 128
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/', $businessScope) !== 1) $this->invalid();
        $payload = ['environment_id' => $identity->environmentId, 'code' => $code, 'version_no' => $versionNo, 'business_scope' => $businessScope, 'input' => $input];
        return $payload + ['idempotency_key' => $key, 'payload_fingerprint' => ToolFingerprint::for('tool_run_request', $payload)];
    }

    /** @return array{origin:string,path:string,authorization:array{mode:string,credential:?string}} */
    private function connectionConfig(ToolConnectionRevision $revision): array
    {
        return (new ToolConnectionConfigLoader($this->connectionCipher))->load($revision);
    }

    /** @return array{lease_token:string,lease_fence:int,lease_expires_at:string} */
    private function lease(int $fence, int $timeoutMs): array
    {
        if ($fence < 1 || $timeoutMs < 100 || $timeoutMs > 30000) throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'External tool run lease is invalid');
        return ['lease_token' => bin2hex(random_bytes(24)), 'lease_fence' => $fence, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + (int) ceil($timeoutMs / 1000) + 60)];
    }

    /** @param array<string,mixed> $lease */
    private function owns(ToolRun $run, array $lease): bool
    {
        return ToolRunLeaseFence::owns((string) $run->state, $run->lease_token, (int) $run->lease_fence, $lease);
    }

    private function expired(mixed $value): bool
    {
        return is_string($value) && $value <= gmdate('Y-m-d H:i:s');
    }

    private function sameIdentity(ToolRun $run, IdentityContext $identity): bool
    {
        $snapshot = is_array($run->identity_snapshot) ? $run->identity_snapshot : [];
        return ($snapshot['organization_ref'] ?? null) === $identity->organizationId && ($snapshot['application_ref'] ?? null) === $identity->applicationId && ($snapshot['workload_client_ref'] ?? null) === $identity->workloadClientId;
    }

    /** @return array<string,string> */
    private function identitySnapshot(IdentityContext $identity): array
    {
        return ['organization_ref' => $identity->organizationId, 'application_ref' => $identity->applicationId, 'workload_client_ref' => $identity->workloadClientId];
    }

    private function numeric(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'External tool request envelope is invalid');
    }
}
