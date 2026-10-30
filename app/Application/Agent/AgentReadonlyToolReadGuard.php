<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\ToolRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\ToolRun;
use support\Request;

/** Authorizes current reads of task-bound tool facts without using a queued execution identity. */
final class AgentReadonlyToolReadGuard
{
    public function __construct(private readonly ?\app\Identity\Infrastructure\HostSandIamInvocationAuthorizer $authorizer = null) {}
    /** Read old tool-backed dialogue using the current task's original, formally verified envelope. */
    public function authorizeQueuedHistory(array $queued, int $currentTaskId, array $historyTaskIds): \Closure
    {
        $parentIdentity = $queued['identity'] ?? null;
        if (!$parentIdentity instanceof IdentityContext || !array_is_list($historyTaskIds) || count($historyTaskIds) > 24) self::missing();
        $runs = [];
        foreach ($historyTaskIds as $id) {
            if (!is_int($id) || $id < 1 || $id >= $currentTaskId) self::missing();
            $task = TaskRun::where('id', $id)->where('environment_id', $parentIdentity->environmentId)->where('status', 1)
                ->whereNull('delete_time')->whereNull('content_purged_time')->where('task_type', 'agent_run')->where('state', 'succeeded')->find();
            if ($task === null) self::missing();
            foreach (['organization_id' => $parentIdentity->organizationId, 'application_id' => $parentIdentity->applicationId,
                'workload_client_id' => $parentIdentity->workloadClientId] as $field => $value) {
                if ((string) $task->{$field} !== $value) self::missing();
            }
            foreach ($this->readableRuns($task) as $run) {
                $this->assertParent($run, $task);
                $runs[] = [$task, $run];
            }
        }
        if ($runs === []) return static function (): void {};
        $provider = new HostSandIamIdentityContextProvider();
        try {
            $toolIdentity = $provider->requireSignedContext($queued['signed_context'], 'sand_ai.tool.read', $queued['source_ip'], $queued['request_id']);
            $taskIdentity = $provider->requireSignedContext($queued['signed_context'], 'sand_ai.task.read', $queued['source_ip'], $queued['request_id']);
        } catch (IdentityContextException $exception) { throw IdentityContextProblem::from($exception); }
        foreach ([$toolIdentity, $taskIdentity] as $identity) $this->assertSameIdentity($identity, $parentIdentity);
        $authorizer = $this->authorizer ?? new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer();
        $decisions = [];
        foreach ($runs as [$task, $run]) {
            $taskDecision = $authorizer->authorizeSigned('Bearer ' . $queued['signed_context'], $queued['source_ip'], $taskIdentity,
                'sand_ai.task.read', 'sand_ai.task_operation', (string) $task->id,
                'agent-history-task:' . $currentTaskId . ':' . $task->id, $queued['request_id']);
            $toolDecision = $authorizer->authorizeSigned('Bearer ' . $queued['signed_context'], $queued['source_ip'], $toolIdentity,
                'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id,
                'agent-history-tool:' . $currentTaskId . ':' . $run->id, $queued['request_id']);
            if (($toolDecision['data_class'] ?? null) !== ($run->execution_snapshot['data_class'] ?? null)) self::missing();
            $decisions[] = [$task, $run, $taskDecision, $toolDecision];
        }
        return function () use ($decisions, $authorizer, $queued, $taskIdentity, $toolIdentity): void {
            foreach ($decisions as [$task, $run, $taskDecision, $toolDecision]) {
                $this->assertParent($run, $task);
                $authorizer->revalidate($taskDecision['authorization_id'], $queued['signed_context'], $taskIdentity,
                    'sand_ai.task.read', 'sand_ai.task_operation', (string) $task->id, $queued['source_ip'], $queued['request_id']);
                $verified = $authorizer->revalidate($toolDecision['authorization_id'], $queued['signed_context'], $toolIdentity,
                    'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id, $queued['source_ip'], $queued['request_id']);
                if (($verified['data_class'] ?? null) !== ($toolDecision['data_class'] ?? null)) self::missing();
            }
        };
    }

    public function authorizeTask(Request $request, IdentityContext $identity, TaskRun $task, string $requestId, bool $continuation = false): \Closure
    {
        $pairs = $this->taskToolPairs($task, $continuation);
        if ($pairs === []) return static function (): void {};
        $toolIdentity = $this->identity($request, $identity, 'sand_ai.tool.read');
        $guard = new ToolRunAccessGuard($this->authorizer);
        $decisions = [];
        foreach ($pairs as [$parent, $run]) {
            $parentRevalidate = $this->authorizeParent($request, $toolIdentity, $run, $requestId);
            $this->assertParent($run, $parent);
            $decisions[] = [$parent, $run, $parentRevalidate, $guard->authorize($request, $toolIdentity, (int) $run->id,
                'sand_ai.tool.read', $requestId, $run->execution_snapshot['data_class'] ?? null)];
        }
        return function () use ($decisions, $guard, $request, $toolIdentity, $requestId): void {
            foreach ($decisions as [$parent, $run, $parentRevalidate, $decision]) {
                $parentRevalidate();
                $this->assertParent($run, $parent);
                $guard->revalidate($request, $toolIdentity, (int) $run->id, 'sand_ai.tool.read', $requestId, $decision);
            }
        };
    }

    /** Channel workers use a real issued context; read permission never grants tool execution. */
    public function authorizeTaskSigned(string $authorizationHeader, string $sourceIp, IdentityContext $identity,
        TaskRun $task, string $requestId): \Closure
    {
        $pairs = $this->taskToolPairs($task);
        if ($pairs === []) return static function (): void {};
        $signed = trim($authorizationHeader);
        if (!str_starts_with($signed, 'Bearer ') || trim(substr($signed, 7)) === '') self::missing();
        $signed = trim(substr($signed, 7));
        $provider = new HostSandIamIdentityContextProvider();
        try {
            $toolIdentity = $provider->requireSignedContext($signed, 'sand_ai.tool.read', $sourceIp, $requestId);
            $taskIdentity = $provider->requireSignedContext($signed, 'sand_ai.task.read', $sourceIp, $requestId);
        } catch (IdentityContextException $failure) { throw IdentityContextProblem::from($failure); }
        $this->assertSameIdentity($toolIdentity, $identity);
        $this->assertSameIdentity($taskIdentity, $identity);
        $authorizer = $this->authorizer ?? new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer();
        $decisions = [];
        foreach ($pairs as [$parent, $run]) {
            $this->assertParent($run, $parent);
            $parentDecision = $authorizer->authorizeSigned($authorizationHeader, $sourceIp, $taskIdentity,
                'sand_ai.task.read', 'sand_ai.task_operation', (string) $parent->id,
                'channel-tool-parent-read:' . bin2hex(random_bytes(16)), $requestId);
            $toolDecision = $authorizer->authorizeSigned($authorizationHeader, $sourceIp, $toolIdentity,
                'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id,
                'channel-tool-read:' . bin2hex(random_bytes(16)), $requestId);
            if (($toolDecision['data_class'] ?? null) !== ($run->execution_snapshot['data_class'] ?? null)) self::missing();
            $facts = $this->signedToolFacts($parent, $run, $identity);
            $decisions[] = [$parent, $run, $facts, $parentDecision, $toolDecision];
        }
        return function () use ($task, $pairs, $decisions, $authorizer, $signed, $sourceIp, $requestId, $taskIdentity, $toolIdentity, $identity): void {
            $currentPairs = $this->taskToolPairs($task);
            if (array_map(static fn (array $pair): int => (int) $pair[1]->id, $currentPairs)
                !== array_map(static fn (array $pair): int => (int) $pair[1]->id, $pairs)) self::missing();
            foreach ($decisions as [$parent, $run, $facts, $parentDecision, $toolDecision]) {
                if (!hash_equals($facts, $this->signedToolFacts($parent, $run, $identity))) self::missing();
                $authorizer->revalidate($parentDecision['authorization_id'], $signed, $taskIdentity,
                    'sand_ai.task.read', 'sand_ai.task_operation', (string) $parent->id, $sourceIp, $requestId);
                $verified = $authorizer->revalidate($toolDecision['authorization_id'], $signed, $toolIdentity,
                    'sand_ai.tool.read', 'sand_ai.tool_run', (string) $run->id, $sourceIp, $requestId);
                if (($verified['data_class'] ?? null) !== ($toolDecision['data_class'] ?? null)) self::missing();
            }
        };
    }

    /** @return list<array{TaskRun,ToolRun}> */
    private function taskToolPairs(TaskRun $task, bool $continuation = false): array
    {
        $runs = $this->readableRuns($task, $continuation);
        $pairs = [];
        foreach ($runs as $run) $pairs[] = [$task, $run];
        $historyIds = $task->input['_agent_conversation']['history_task_ids'] ?? [];
        if (!is_array($historyIds) || !array_is_list($historyIds) || count($historyIds) > 4) self::missing();
        foreach ($historyIds as $id) {
            if (!is_int($id) || $id < 1 || $id >= (int) $task->id) self::missing();
            $historyTask = TaskRun::where('id', $id)->where('environment_id', (int) $task->environment_id)
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
            if ($historyTask === null) self::missing();
            foreach ($this->readableRuns($historyTask) as $run) {
                $pairs[] = [$historyTask, $run];
            }
        }
        return $pairs;
    }

    /** Only sealed successes are read; failed originals are omitted solely with a proven linked successor. */
    private function readableRuns(TaskRun $task, bool $continuation = false): array
    {
        $runs = [];
        foreach (ToolRun::where('parent_task_run_id', (int) $task->id)->where('environment_id', (int) $task->environment_id)->order('id', 'asc')->select() as $run) {
            if ((string) $run->state === 'failed') {
                $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
                if ($step !== null && (new \app\Application\Tool\ToolRunGateway())->agentContinuationNeverDispatchedProof($task, $step, $run) !== null) {
                    if ($continuation) continue;
                    $latest = AgentReadonlyToolContinuationNode::latest($task, $step);
                    $success = ToolRun::where('parent_task_run_id', (int) $task->id)->where('parent_task_step_id', (int) $latest->id)
                        ->where('state', 'succeeded')->find();
                    if ((int) $latest->id !== (int) $step->id && $success !== null && (string) $latest->state === 'succeeded') continue;
                }
                // Ordinary task reads retain the existing tool metadata permission path for a failed task.
                if ($continuation || (string) $task->state === 'succeeded') self::missing();
            }
            if ((string) $run->state !== 'succeeded' && ($continuation || (string) $task->state === 'succeeded')) self::missing();
            $runs[] = $run;
        }
        return $runs;
    }

    /** @return list<array{run_id:int,decision:array<string,mixed>}> */
    public function authorizePendingContinuation(Request $request, IdentityContext $identity, TaskRun $task, string $requestId): array
    {
        $pending = [];
        foreach (ToolRun::where('parent_task_run_id', (int) $task->id)->select() as $run) {
            if ((string) $run->state === 'succeeded') continue;
            $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
            if ($step === null || (new \app\Application\Tool\ToolRunGateway())->agentContinuationNeverDispatchedProof($task, $step, $run) === null) self::missing();
            if ((int) AgentReadonlyToolContinuationNode::latest($task, $step)->id !== (int) $step->id) continue;
            $pending[] = $run;
        }
        if ($pending === []) return [];
        $toolIdentity = $this->identity($request, $identity, 'sand_ai.tool.run');
        $guard = new ToolRunAccessGuard($this->authorizer);
        $decisions = [];
        foreach ($pending as $run) {
            $this->signedToolFacts($task, $run, $toolIdentity);
            $decisions[] = ['run_id' => (int) $run->id, 'decision' => $guard->authorize($request, $toolIdentity,
                (int) $run->id, 'sand_ai.tool.run', $requestId, $run->execution_snapshot['data_class'] ?? null)];
        }
        $this->revalidatePendingContinuation($request, $identity, $task, $requestId, $decisions);
        return $decisions;
    }

    public function revalidatePendingContinuation(Request $request, IdentityContext $identity, TaskRun $task, string $requestId, array $decisions): void
    {
        if ($decisions === []) return;
        $toolIdentity = $this->identity($request, $identity, 'sand_ai.tool.run');
        $guard = new ToolRunAccessGuard($this->authorizer);
        foreach ($decisions as $item) {
            if (!is_array($item) || !is_int($item['run_id'] ?? null) || !is_array($item['decision'] ?? null)) self::missing();
            $run = ToolRun::where('id', $item['run_id'])->where('parent_task_run_id', (int) $task->id)->find();
            $step = $run === null ? null : TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
            if ($run === null || $step === null || (new \app\Application\Tool\ToolRunGateway())->agentContinuationNeverDispatchedProof($task, $step, $run) === null) self::missing();
            $this->signedToolFacts($task, $run, $toolIdentity);
            $guard->revalidate($request, $toolIdentity, (int) $run->id, 'sand_ai.tool.run', $requestId, $item['decision']);
        }
    }

    /** Re-read durable scope, exact version and source facts without changing any receipt or dispatch. */
    private function signedToolFacts(TaskRun $task, ToolRun $run, IdentityContext $identity): string
    {
        $parent = TaskRun::where('id', (int) $task->id)->where('environment_id', $identity->environmentId)
            ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
        $current = ToolRun::where('id', (int) $run->id)->where('environment_id', $identity->environmentId)->find();
        if ($parent === null || $current === null) self::missing();
        foreach (['organization_id' => $identity->organizationId, 'application_id' => $identity->applicationId,
            'workload_client_id' => $identity->workloadClientId] as $field => $value) {
            if ((string) $parent->{$field} !== $value || (string) $current->{$field} !== $value) self::missing();
        }
        $this->assertParent($current, $parent);
        $step = TaskStep::where('id', (int) $current->parent_task_step_id)->where('task_id', (int) $parent->id)->find();
        $bindingStep = $step === null ? null : AgentReadonlyToolContinuationNode::originalStep($parent, $step);
        $index = $bindingStep === null ? false : array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
        $binding = $index === false ? null : ($parent->input['_agent_readonly_tools'][$index] ?? null);
        if (!is_array($binding) || $step === null
            || ($step->context['child_request_id'] ?? null) !== (string) $current->request_id
            || ($step->context['input_sha256'] ?? null) !== \app\Application\Tool\ToolFingerprint::for('agent_readonly_input', ['input' => $current->input])) self::missing();
        (new AgentReadonlyToolCatalog())->assertCurrent((int) $parent->environment_id, [$binding]);
        foreach (['adapter', 'effect_class', 'tool_id', 'tool_version_id', 'binding_id', 'connection_id', 'connection_revision_id',
            'connection_config_fingerprint', 'data_class', 'timeout_ms', 'response_max_bytes', 'wire_contract', 'mcp_snapshot_item_id', 'mcp_snapshot_item_hash'] as $field) {
            if (($current->execution_snapshot[$field] ?? null) !== ($binding[$field] ?? null)) self::missing();
        }
        $sources = [];
        foreach (\app\Infrastructure\Persistence\Model\ToolSource::where('tool_run_id', (int) $current->id)->order('sequence_no')->select() as $source) {
            $sources[] = ['source_ref' => $source->source_ref, 'locator' => $source->locator,
                'source_version' => $source->source_version, 'content_hash' => $source->content_hash];
        }
        if ((string) $current->state === 'succeeded') {
            if (!hash_equals(\app\Application\Tool\ToolFingerprint::for('tool_sources', ['sources' => $current->citations ?? []]),
                \app\Application\Tool\ToolFingerprint::for('tool_sources', ['sources' => $sources]))) self::missing();
            \app\Application\Tool\ToolSchemaPolicy::assertValue($binding['output_schema'], $current->result);
            if ((bool) $current->review_required !== ($binding['review_required'] ?? null)) self::missing();
        }
        return \app\Application\Tool\ToolFingerprint::for('channel_tool_read', [
            'run_id' => (int) $current->id, 'parent_task_id' => (int) $parent->id, 'step_id' => (int) $current->parent_task_step_id,
            'request_fingerprint' => $current->request_fingerprint, 'queued_authorization' => $current->queued_authorization,
            'business_scope' => $current->business_scope, 'execution_snapshot' => $current->execution_snapshot,
            'state' => $current->state, 'result' => $current->result, 'sources' => $sources,
        ]);
    }

    /** Used by the existing tool read route; external tools without a parent retain their old behavior. */
    public function authorizeParent(Request $request, IdentityContext $identity, ToolRun $run, string $requestId): \Closure
    {
        if ($run->parent_task_run_id === null && $run->parent_task_step_id === null) return static function (): void {};
        $task = TaskRun::where('id', (int) $run->parent_task_run_id)->where('environment_id', $identity->environmentId)
            ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
        if ($task === null) self::missing();
        foreach (['organization_id' => $identity->organizationId, 'application_id' => $identity->applicationId,
            'workload_client_id' => $identity->workloadClientId] as $field => $value) {
            if ((string) $task->{$field} !== $value) self::missing();
        }
        $this->assertParent($run, $task);
        $taskIdentity = $this->identity($request, $identity, 'sand_ai.task.read');
        $guard = new TaskOperationAccessGuard($this->authorizer);
        $decision = $guard->authorize($request, $taskIdentity, (int) $task->id, 'sand_ai.task.read', $requestId);
        return function () use ($run, $task, $guard, $request, $taskIdentity, $requestId, $decision): void {
            $this->assertParent($run, $task);
            $guard->revalidate($request, $taskIdentity, (int) $task->id, 'sand_ai.task.read', $requestId, $decision);
        };
    }

    private function identity(Request $request, IdentityContext $original, string $action): IdentityContext
    {
        try { $identity = (new HostSandIamIdentityContextProvider())->requireContext($request, $action); }
        catch (IdentityContextException $exception) { throw IdentityContextProblem::from($exception); }
        $this->assertSameIdentity($identity, $original);
        return $identity;
    }

    private function assertSameIdentity(IdentityContext $identity, IdentityContext $original): void
    {
        if ($identity->organizationId !== $original->organizationId || $identity->applicationId !== $original->applicationId
            || $identity->environmentId !== $original->environmentId || $identity->workloadClientId !== $original->workloadClientId
            || $identity->audience !== $original->audience || $identity->expiresAt !== $original->expiresAt) self::missing();
    }

    private function assertParent(ToolRun $run, TaskRun $task): void
    {
        $current = TaskRun::where('id', (int) $task->id)->where('status', 1)
            ->whereNull('delete_time')->whereNull('content_purged_time')->find();
        $step = TaskStep::where('id', (int) $run->parent_task_step_id)->where('task_id', (int) $task->id)->find();
        if ($current === null || $step === null || (string) $current->task_type !== 'agent_run'
            || (int) $run->parent_task_run_id !== (int) $current->id
            || (int) $run->environment_id !== (int) $current->environment_id
            || !is_array($current->input) || !is_array($current->input['_agent_readonly_tools'] ?? null)) self::missing();
        $bindings = $current->input['_agent_readonly_tools'];
        $bindingStep = AgentReadonlyToolContinuationNode::originalStep($current, $step);
        $index = array_search((string) $bindingStep->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true);
        $binding = $index === false ? null : ($bindings[$index] ?? null);
        if (!is_array($binding) || ($step->context['readonly_binding_sha256'] ?? null) !== ($binding['snapshot_sha256'] ?? null)
            || (int) $run->tool_version_id !== ($binding['tool_version_id'] ?? null)
            || (string) $run->business_scope !== ($binding['selection']['business_scope'] ?? null)) self::missing();
    }

    private static function missing(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task-bound tool source not found');
    }
}
