<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskGateway;
use app\Application\Tool\ToolFingerprint;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;

/** Validates durable parent facts; SDK arguments cannot select a parent or scope. */
final class AgentReadonlyToolParentFacts
{
    /** @return array{task:TaskRun,step:TaskStep,parent:array<string,mixed>} */
    public function require(TaskExecutionLease $lease, int $stepId, array $binding): array
    {
        (new TaskGateway())->heartbeat($lease);
        $task = TaskRun::where('id', $lease->taskId)->where('state', 'running')
            ->where('task_type', 'agent_run')->where('status', 1)->whereNull('delete_time')
            ->whereNull('content_purged_time')->where('lease_owner', $lease->owner)
            ->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->find();
        $step = TaskStep::where('id', $stepId)->where('task_id', $lease->taskId)->where('state', 'running')->find();
        if ($task === null || $step === null) self::unverified();
        $bindingStep = AgentReadonlyToolContinuationNode::originalStep($task, $step);
        if (isset($step->context['original_step_id'])) {
            $context = $step->context;
            if (($task->input['_agent_continuation']['key'] ?? null) !== $context['continuation_key']) self::unverified();
            AgentContinuationGateway::revalidateAdmission($task);
            $original = TaskStep::where('id', $context['original_step_id'])->where('task_id', (int) $task->id)->find();
            $run = \app\Infrastructure\Persistence\Model\ToolRun::where('id', $context['original_run_id'])->find();
            if ($original === null || $run === null || (new \app\Application\Tool\ToolRunGateway())->agentContinuationNeverDispatchedProof($task, $original, $run) === null) self::unverified();
        }
        $bindings = $task->input['_agent_readonly_tools'] ?? null;
        if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) < 1 || count($bindings) > 2
            || !AgentReadonlyToolProtocol::valid($task->input['_agent_readonly_protocol'] ?? null)) self::unverified();
        $index = (int) substr((string) $bindingStep->step_code, -1) - 1;
        $frozen = $bindings[$index] ?? null;
        if (!is_array($frozen) || !hash_equals(ToolFingerprint::for('agent_readonly_binding', $frozen),
            ToolFingerprint::for('agent_readonly_binding', $binding))) self::unverified();
        $context = $step->context;
        if (!is_array($context) || ($context['readonly_binding_sha256'] ?? null) !== ($binding['snapshot_sha256'] ?? null)
            || !is_string($context['child_request_id'] ?? null)
            || !is_string($context['input_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $context['input_sha256']) !== 1
            || $context['child_request_id'] !== (isset($context['original_step_id'])
                ? AgentReadonlyToolContinuationNode::childKey((int) $task->id, $context['original_step_id'], $context['original_run_id'], $context['continuation_key'])
                : 'agent-tool:' . $task->id . ':' . $step->id . ':' . substr($context['input_sha256'], 0, 32))) self::unverified();
        (new AgentReadonlyToolCatalog())->assertCurrent((int) $task->environment_id, [$binding]);
        return [
            'task' => $task, 'step' => $step,
            'parent' => [
                'task_id' => (int) $task->id, 'step_id' => (int) $step->id,
                'binding_sha256' => $binding['snapshot_sha256'],
                'child_request_id' => $context['child_request_id'],
            ],
        ];
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent tool parent facts are unavailable');
    }
}
