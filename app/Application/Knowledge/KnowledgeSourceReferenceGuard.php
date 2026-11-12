<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use think\facade\Db;

final class KnowledgeSourceReferenceGuard
{
    public function lockFile(int $fileId): void { Db::connect('pgsql')->execute("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", ['sand-ai-knowledge-file:' . $fileId]); }
    /** @param list<int> $fileIds */
    public function lockFiles(array $fileIds): void
    {
        $fileIds = array_values(array_unique($fileIds)); sort($fileIds, SORT_NUMERIC);
        foreach ($fileIds as $fileId) $this->lockFile($fileId);
    }
    public function rejectPublishedReference(int $fileId): void
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT DISTINCT kb.name FROM sand_ai_knowledge_base_source s
             INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id
             INNER JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id
             WHERE s.file_id=? AND v.state='published' AND kb.delete_time IS NULL
             ORDER BY kb.name",
            [$fileId],
        );
        if ($rows !== []) {
            $names = array_map(static fn (array $row): string => (string) $row['name'], $rows);
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_REFERENCED', self::describeReference($names));
        }
    }

    /**
     * Operator-facing reason naming the published bases that still reference a
     * file, so the rejection can show where to act next.
     * @param list<string> $names
     */
    public static function describeReference(array $names): string
    {
        $clean = array_map(
            static fn (string $name): string => str_replace(['「', '」'], '', $name),
            $names,
        );
        return '这份资料正被已发布的知识库「' . implode('、', $clean) . '」使用，先从知识库里移除再删除';
    }
}
