<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\agent;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\infrastructure\agent\AgentToolRegistry;

final class AgentPolicy
{
    /** @param array<string, mixed> $input @return array{model: string, instructions: string, query: string, tools: list<string>, review_required: bool} */
    public static function normalizeInput(array $input): array
    {
        $model = trim((string) ($input['model'] ?? ''));
        $instructions = trim((string) ($input['instructions'] ?? ''));
        $query = trim((string) ($input['query'] ?? ''));
        if ($model === '' || $instructions === '' || $query === '') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model, instructions and query are required');
        }
        if (mb_strlen($instructions, 'UTF-8') > 16000 || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'instructions or query exceeds the permitted length');
        }
        $tools = $input['tools'] ?? ['retrieval.search'];
        if (!is_array($tools) || $tools === [] || count($tools) > 10) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'tools must contain one to ten registered names');
        }
        $normalizedTools = [];
        foreach ($tools as $tool) {
            $tool = trim((string) $tool);
            AgentToolRegistry::tool($tool);
            $normalizedTools[] = $tool;
        }
        $reviewRequired = $input['review_required'] ?? false;
        if (!is_bool($reviewRequired)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'review_required must be boolean');
        }

        return [
            'model' => $model, 'instructions' => $instructions, 'query' => $query,
            'tools' => array_values(array_unique($normalizedTools)), 'review_required' => $reviewRequired,
        ];
    }
}
