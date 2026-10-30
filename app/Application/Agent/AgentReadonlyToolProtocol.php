<?php

declare(strict_types=1);

namespace app\Application\Agent;

/** Immutable limits for new task-bound readonly tool versions, never backfilled into legacy tasks. */
final class AgentReadonlyToolProtocol
{
    public const LIMITS = [
        'version' => 'agent_readonly_tools@1',
        'max_tool_executions' => 3,
        'max_retrieval_executions' => 1,
        'max_readonly_executions_per_tool' => 1,
        'max_model_rounds' => 4,
        'max_output_tokens_per_round' => 512,
        'parallel_tool_calls' => false,
        'implicit_retries' => 0,
    ];
    public static function valid(mixed $limits): bool
    {
        if (!is_array($limits) || count($limits) !== count(self::LIMITS)
            || array_diff_key($limits, self::LIMITS) !== []) return false;
        foreach (self::LIMITS as $key => $value) {
            if (!array_key_exists($key, $limits) || $limits[$key] !== $value) return false;
        }
        return true;
    }
}
