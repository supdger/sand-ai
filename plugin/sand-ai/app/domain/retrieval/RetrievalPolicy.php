<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\retrieval;

use plugin\SandAi\app\api\support\ApiProblem;

final class RetrievalPolicy
{
    /** @param array<string, mixed> $input @return array{query: string, limit: int} */
    public static function normalizeSearch(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '' || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'query must contain one to 500 characters');
        }
        $limit = (int) ($input['limit'] ?? 10);
        if ($limit < 1 || $limit > 20) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'limit must be between 1 and 20');
        }

        return ['query' => $query, 'limit' => $limit];
    }
}
