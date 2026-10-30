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
        $row = Db::connect('pgsql')->query("SELECT 1 FROM sand_ai_knowledge_base_source s INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id WHERE s.file_id=? AND v.state='published' LIMIT 1", [$fileId]);
        if ($row !== []) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_REFERENCED', 'File is referenced by a published knowledge base');
    }
}
