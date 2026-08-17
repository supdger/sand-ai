<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\retrieval;

use plugin\SandAi\app\contract\IdentityContext;
use think\facade\Db;

/** Searches only parsed private source blocks in the authorized environment. */
final class RetrievalGateway
{
    /** @param array<string, mixed> $input @return array{query: string, matches: list<array<string, mixed>>} */
    public function search(IdentityContext $context, array $input): array
    {
        $input = RetrievalPolicy::normalizeSearch($input);
        $rows = Db::connect('pgsql')->query(
            "SELECT source_block.id, source_block.file_id, source_block.locator_type, source_block.locator,\n" .
            "       source_block.content,\n" .
            "       (CASE WHEN to_tsvector('simple', source_block.content) @@ websearch_to_tsquery('simple', ?)\n" .
            "             THEN ts_rank_cd(to_tsvector('simple', source_block.content), websearch_to_tsquery('simple', ?))\n" .
            "             ELSE 0 END\n" .
            "        + CASE WHEN source_block.content ILIKE '%' || ? || '%' THEN 0.1 ELSE 0 END) AS score\n" .
            "FROM sand_ai_source_block AS source_block\n" .
            "INNER JOIN sand_ai_file AS file ON file.id = source_block.file_id\n" .
            "WHERE file.environment_id = ?\n" .
            "  AND file.status = 1\n" .
            "  AND file.state = 'parsed'\n" .
            "  AND source_block.status = 1\n" .
            "  AND (to_tsvector('simple', source_block.content) @@ websearch_to_tsquery('simple', ?)\n" .
            "       OR source_block.content ILIKE '%' || ? || '%')\n" .
            "ORDER BY score DESC, source_block.id ASC\n" .
            "LIMIT " . $input['limit'],
            [$input['query'], $input['query'], $input['query'], $context->environmentId, $input['query'], $input['query']],
        );

        $matches = array_map(static function (array $row): array {
            $locator = $row['locator'] ?? [];
            if (is_string($locator)) {
                $decoded = json_decode($locator, true);
                $locator = is_array($decoded) ? $decoded : [];
            }

            return [
                'source_block_id' => (int) $row['id'],
                'file_id' => (int) $row['file_id'],
                'locator_type' => (string) $row['locator_type'],
                'locator' => is_array($locator) ? $locator : [],
                'score' => round((float) $row['score'], 6),
                'excerpt' => mb_strcut(trim((string) $row['content']), 0, 500, 'UTF-8'),
            ];
        }, $rows);

        return ['query' => $input['query'], 'matches' => $matches];
    }
}
