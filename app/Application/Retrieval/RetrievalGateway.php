<?php

declare(strict_types=1);

namespace app\Application\Retrieval;

use app\Application\Knowledge\KnowledgeSearchExpression;
use think\facade\Db;

final class RetrievalGateway
{
    /** @param array<string, mixed> $input @return array{query: string, matches: list<array<string, mixed>>} */
    public function search(int $environmentId, array $input, string $trustedIdentityScope): array
    {
        $input = RetrievalPolicy::normalizeSearch($input);
        $search = KnowledgeSearchExpression::build($input['query'], 'source_block');
        $rows = Db::connect('pgsql')->query(
            "SELECT source_block.id, source_block.file_id, source_block.locator_type, source_block.locator,\n" .
            "       source_block.content,\n" .
            "       {$search['score_sql']} AS score\n" .
            "FROM sand_ai_source_block AS source_block\n" .
            "INNER JOIN sand_ai_file AS file ON file.id = source_block.file_id\n" .
            "INNER JOIN sand_ai_file_parse AS file_parse ON file_parse.id = source_block.parse_id AND file_parse.file_id = file.id\n" .
            "WHERE file.environment_id = ?\n" .
            "  AND file.owner_scope = ?\n" .
            "  AND file.status = 1\n" .
            "  AND file.delete_time IS NULL\n" .
            "  AND file.state = 'parsed'\n" .
            "  AND file_parse.status = 1 AND file_parse.delete_time IS NULL AND file_parse.state = 'succeeded'\n" .
            "  AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse AS latest\n" .
            "      WHERE latest.file_id = file.id AND latest.id > file_parse.id\n" .
            "        AND latest.status = 1 AND latest.delete_time IS NULL AND latest.state = 'succeeded')\n" .
            "  AND source_block.status = 1\n" .
            "  AND source_block.delete_time IS NULL\n" .
            "  AND encode(sha256(convert_to(source_block.content, 'UTF8')), 'hex') = source_block.content_sha256\n" .
            "  AND {$search['match_sql']}\n" .
            "ORDER BY score DESC, source_block.id ASC\n" .
            "LIMIT " . $input['limit'],
            [...$search['score_binds'], $environmentId, $trustedIdentityScope, ...$search['match_binds']]
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
