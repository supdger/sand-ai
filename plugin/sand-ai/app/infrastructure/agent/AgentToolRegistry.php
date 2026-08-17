<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\agent;

use plugin\SandAi\app\api\support\ApiProblem;

final class AgentToolRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function metadata(): array
    {
        return [
            'retrieval.search' => [
                'description' => 'Search private source blocks within the current environment',
                'required_action' => 'sand_ai.retrieval.search',
                'data_egress' => 'none',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function tool(string $name): array
    {
        $tool = self::metadata()[$name] ?? null;
        if ($tool === null) {
            throw new ApiProblem('SAND_AI_AGENT_TOOL_FORBIDDEN', 'The agent tool is not registered');
        }

        return $tool;
    }
}
