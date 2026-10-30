<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\ToolRun;

/** Server-generated successor nodes preserve the original failed node and its unique tool parent. */
final class AgentReadonlyToolContinuationNode
{
    public static function code(int $originalStepId, string $key): string
    {
        return 'agent_tool_continue_' . substr(hash('sha256', $originalStepId . ':' . $key), 0, 32);
    }

    public static function childKey(int $taskId, int $originalStepId, int $originalRunId, string $key): string
    {
        return 'agent-tool-continue:' . $taskId . ':' . $originalStepId . ':' . substr(hash('sha256', $originalRunId . ':' . $key), 0, 32);
    }

    public static function originalStep(TaskRun $task, TaskStep $step): TaskStep
    {
        $seen = [];
        while (!in_array((string) $step->step_code, ['agent_managed_tool_1', 'agent_managed_tool_2'], true)) {
            if (count($seen) >= 16 || isset($seen[(int) $step->id])) self::invalid();
            $seen[(int) $step->id] = true;
            $context = $step->context;
            if (!is_array($context) || !is_int($context['original_step_id'] ?? null) || $context['original_step_id'] < 1
                || !is_int($context['original_run_id'] ?? null) || $context['original_run_id'] < 1
                || !is_string($context['continuation_key'] ?? null) || $context['continuation_key'] === ''
                || (string) $step->step_code !== self::code($context['original_step_id'], $context['continuation_key'])
                || ($context['child_request_id'] ?? null) !== self::childKey((int) $task->id, $context['original_step_id'], $context['original_run_id'], $context['continuation_key'])) self::invalid();
            $admission = null;
            foreach (($task->input['_agent_continuations'] ?? []) as $record) {
                if (($record['key'] ?? null) === $context['continuation_key']) $admission = $record;
            }
            if (!is_array($admission) || ($admission['task_id'] ?? null) !== (int) $task->id
                || ($admission['run_id'] ?? null) !== AgentGateway::checkpointRunId($task)) self::invalid();
            $original = TaskStep::where('id', $context['original_step_id'])->where('task_id', (int) $task->id)
                ->where('state', 'failed')->where('status', 1)->whereNull('delete_time')->find();
            $run = ToolRun::where('id', $context['original_run_id'])->where('parent_task_run_id', (int) $task->id)
                ->where('parent_task_step_id', $context['original_step_id'])->where('state', 'failed')->find();
            if ($original === null || $run === null
                || ($original->context['input_sha256'] ?? null) !== ($context['input_sha256'] ?? null)
                || ($original->context['readonly_binding_sha256'] ?? null) !== ($context['readonly_binding_sha256'] ?? null)) self::invalid();
            $step = $original;
        }
        return $step;
    }

    public static function latest(TaskRun $task, TaskStep $step): TaskStep
    {
        $seen = [];
        while (true) {
            if (count($seen) >= 16 || isset($seen[(int) $step->id])) self::invalid();
            $seen[(int) $step->id] = true;
            $children = [];
            foreach (TaskStep::where('task_id', (int) $task->id)->where('status', 1)->whereNull('delete_time')->select() as $candidate) {
                if (($candidate->context['original_step_id'] ?? null) === (int) $step->id) $children[] = $candidate;
            }
            if ($children === []) return $step;
            if (count($children) !== 1) self::invalid();
            self::originalStep($task, $children[0]);
            $step = $children[0];
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent continuation tool node is unavailable');
    }
}
