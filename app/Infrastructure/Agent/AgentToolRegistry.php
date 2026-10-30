<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;

final class AgentToolRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function metadata(): array
    {
        return [
            'retrieval.search' => [
                'description' => 'Search private source blocks within the current environment',
                'required_scope' => 'retrieval:read',
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
