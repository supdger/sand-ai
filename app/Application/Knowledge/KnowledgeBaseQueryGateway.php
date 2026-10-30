<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use think\facade\Db;

final class KnowledgeBaseQueryGateway
{
    /**
     * Merge lexical and current-index semantic candidates after both readers
     * have checked the same published version and live private sources.
     * Query-vector creation and its authorization/usage belong to the caller.
     *
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $input
     * @param list<float> $queryVector
     * @return array<string,mixed>
     */
    public function queryWithVector(array $publication, array $input, array $queryVector, int $indexId): array
    {
        $normalized = KnowledgeBasePolicy::normalizeQuery($input);
        $candidateLimit = min(20, max(10, $normalized['limit'] * 2));
        $lexical = new NeuronKnowledgeScopedRetrieval(
            $normalized['query'], 'lexical',
            function (string $query) use ($publication, $candidateLimit): array {
                return $this->query($publication, ['query' => $query, 'limit' => $candidateLimit]);
            },
        );
        $semantic = new NeuronKnowledgeScopedRetrieval(
            $normalized['query'], 'semantic',
            static function (string $query) use ($publication, $queryVector, $candidateLimit, $indexId): array {
                return ['citations' => (new KnowledgeEmbeddingIndexGateway())->search(
                    $publication, $query, $queryVector, $candidateLimit, $indexId,
                )];
            },
        );
        $question = new \NeuronAI\Chat\Messages\UserMessage($normalized['query']);
        $retrieval = new \NeuronAI\RAG\Retrieval\CompositeRetrieval([$lexical, $semantic]);
        // Let the SDK execute the post-processing stage; only the published RRF
        // ranking and source metadata validation remain SandAI business adaptation.
        $processed = (new \NeuronAI\RAG\Nodes\PostProcessNode([
            new NeuronKnowledgeRankFusion($normalized['limit']),
        ]))(
            new \NeuronAI\RAG\Events\DocumentsRetrievedEvent($question, $retrieval->retrieve($question)),
            new \NeuronAI\Agent\AgentState(),
        );
        $documents = $processed->documents;
        $citations = [];
        foreach ($documents as $document) {
            $citations[] = $document->getMetadata()['citation'] + ['score' => round((float) $document->getScore(), 8)];
        }
        return [
            'knowledge_base' => $lexical->knowledgeBase(),
            'query' => $normalized['query'],
            'citations' => $citations,
        ];
    }

    /** Internal exact-version lookup for immutable consumers such as Skill. */
    public function queryVersion(
        int $environmentId, int $versionId, string $query, array $input = [],
        string $retrievalAlgorithm = KnowledgeSearchExpression::CURRENT_ALGORITHM,
    ): array
    {
        $normalized = KnowledgeBasePolicy::normalizeQuery(['query' => $query, 'limit' => $input['limit'] ?? 5]);
        $base = Db::connect('pgsql')->query("SELECT kb.code,v.version_no,v.source_count,v.snapshot_sha256 FROM sand_ai_knowledge_base kb JOIN sand_ai_knowledge_base_version v ON v.id=? AND v.knowledge_base_id=kb.id WHERE kb.environment_id=? AND kb.delete_time IS NULL AND kb.status=1 AND kb.state='published' AND v.state='published'", [$versionId, $environmentId]);
        if (count($base) !== 1) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Exact published knowledge version is unavailable');
        $base = $base[0];
        $valid = "s.knowledge_base_version_id=? AND kb.environment_id=? AND kb.delete_time IS NULL AND f.delete_time IS NULL AND p.delete_time IS NULL AND b.delete_time IS NULL AND f.status=1 AND f.state='parsed' AND p.status=1 AND p.state='succeeded' AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest WHERE latest.file_id=p.file_id AND latest.delete_time IS NULL AND latest.status=1 AND latest.state='succeeded' AND latest.id>p.id) AND b.status=1 AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator AND b.content_sha256=s.content_sha256 AND encode(sha256(convert_to(b.content, 'UTF8')), 'hex')=b.content_sha256";
        $joins = ' FROM sand_ai_knowledge_base_source s JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id JOIN sand_ai_file f ON f.id=s.file_id JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id ';
        $available = Db::connect('pgsql')->query('SELECT count(*) total' . $joins . ' WHERE ' . $valid, [$versionId, $environmentId]);
        if ((int) ($available[0]['total'] ?? -1) !== (int) $base['source_count']) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source snapshot is no longer available');
        $search = KnowledgeSearchExpression::build($normalized['query'], 'b', $retrievalAlgorithm);
        $rows = Db::connect('pgsql')->query(
            "SELECT s.file_id,s.parse_id,s.source_block_id,s.locator_type,s.locator,b.content,
                    {$search['score_sql']} score
             {$joins} WHERE {$valid}
               AND {$search['match_sql']}
             ORDER BY score DESC,s.source_block_id ASC LIMIT " . $normalized['limit'],
            [...$search['score_binds'], $versionId, $environmentId, ...$search['match_binds']],
        );
        $citations = array_map(static function (array $row) use ($base): array { $locator=is_string($row['locator']) ? json_decode($row['locator'], true) : $row['locator']; return ['knowledge_base_code'=>(string)$base['code'],'knowledge_base_version'=>(int)$base['version_no'],'file_id'=>(int)$row['file_id'],'parse_id'=>(int)$row['parse_id'],'source_block_id'=>(int)$row['source_block_id'],'locator_type'=>(string)$row['locator_type'],'locator'=>is_array($locator)?$locator:[],'excerpt'=>mb_strcut(trim((string)$row['content']),0,500,'UTF-8')]; }, $rows);
        return ['citations' => $citations, 'items' => $citations];
    }

    /** @param array<string,mixed> $publication
     *  @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function query(array $publication, array $input): array
    {
        $input = KnowledgeBasePolicy::normalizeQuery($input);
        $environmentId = (int) ($publication['owner_environment_id'] ?? 0);
        $versionId = (int) ($publication['active_version_id'] ?? 0);
        $publicationId = (int) ($publication['id'] ?? 0);
        $code = $publication['code'] ?? null;
        if ($environmentId < 1 || $versionId < 1 || $publicationId < 1
            || !is_string($code) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge publication is invalid');
        }
        $base = Db::connect('pgsql')->query(
            "SELECT kb.id,kb.active_version_id,v.version_no FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE p.id=? AND p.code=? AND p.state='published' AND kb.environment_id=?
               AND kb.active_version_id=? AND kb.delete_time IS NULL AND kb.state='published'
               AND kb.status=1 AND v.state='published' AND v.source_scope_sha256=?
               AND v.data_class IS NOT DISTINCT FROM ? FOR SHARE OF p,kb,v",
            [$publicationId, $code, $environmentId, $versionId, $publication['source_scope_sha256'] ?? null, $publication['data_class'] ?? null],
        );
        if ($base === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge base not found');
        $base = $base[0];
        $base['code'] = $code;
        $availability = Db::connect('pgsql')->query("SELECT count(*) AS total FROM sand_ai_knowledge_base_source s INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id INNER JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id INNER JOIN sand_ai_file f ON f.id=s.file_id INNER JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id INNER JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id WHERE s.knowledge_base_version_id=? AND kb.environment_id=? AND kb.delete_time IS NULL AND f.delete_time IS NULL AND p.delete_time IS NULL AND b.delete_time IS NULL AND f.status=1 AND f.state='parsed' AND p.status=1 AND p.state='succeeded' AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest WHERE latest.file_id=p.file_id AND latest.delete_time IS NULL AND latest.status=1 AND latest.state='succeeded' AND latest.id>p.id) AND b.status=1 AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator AND b.content_sha256=s.content_sha256 AND encode(sha256(convert_to(b.content, 'UTF8')), 'hex')=b.content_sha256", [$versionId, $environmentId]);
        $expected = Db::connect('pgsql')->query('SELECT source_count FROM sand_ai_knowledge_base_version WHERE id=?', [$versionId]);
        if ($expected === [] || (int) $availability[0]['total'] !== (int) $expected[0]['source_count']) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source snapshot is no longer available');
        $search = KnowledgeSearchExpression::build($input['query']);
        $rows = Db::connect('pgsql')->query("SELECT s.file_id,s.parse_id,s.source_block_id,s.locator_type,s.locator,b.content,{$search['score_sql']} score FROM sand_ai_knowledge_base_source s INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id INNER JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id INNER JOIN sand_ai_file f ON f.id=s.file_id INNER JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id INNER JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id WHERE s.knowledge_base_version_id=? AND kb.environment_id=? AND kb.delete_time IS NULL AND f.delete_time IS NULL AND p.delete_time IS NULL AND b.delete_time IS NULL AND f.status=1 AND f.state='parsed' AND p.status=1 AND p.state='succeeded' AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest WHERE latest.file_id=p.file_id AND latest.delete_time IS NULL AND latest.status=1 AND latest.state='succeeded' AND latest.id>p.id) AND b.status=1 AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator AND b.content_sha256=s.content_sha256 AND encode(sha256(convert_to(b.content, 'UTF8')), 'hex')=b.content_sha256 AND {$search['match_sql']} ORDER BY score DESC,s.source_block_id ASC LIMIT " . $input['limit'], [...$search['score_binds'], $versionId, $environmentId, ...$search['match_binds']]);
        return ['knowledge_base' => ['code' => (string) $base['code'], 'version' => (int) $base['version_no']], 'query' => $input['query'], 'citations' => array_map(static function (array $row) use ($base): array { $locator = $row['locator']; if (is_string($locator)) $locator = json_decode($locator, true); return ['knowledge_base_code' => (string) $base['code'], 'knowledge_base_version' => (int) $base['version_no'], 'file_id' => (int) $row['file_id'], 'parse_id' => (int) $row['parse_id'], 'source_block_id' => (int) $row['source_block_id'], 'locator_type' => (string) $row['locator_type'], 'locator' => is_array($locator) ? $locator : [], 'excerpt' => mb_strcut(trim((string) $row['content']), 0, 500, 'UTF-8'), 'score' => round((float) $row['score'], 6)]; }, $rows)];
    }
}
