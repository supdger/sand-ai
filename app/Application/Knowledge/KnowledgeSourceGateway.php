<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Reads a live publication version member; the controller owns its exact-version IAM decision. */
final class KnowledgeSourceGateway
{
    /**
     * Caller keeps one transaction through the final current-source and IAM revalidation.
     * @param array<string,mixed> $publication
     * @return array{knowledge_base_code:string,knowledge_base_version:int,file_id:int,parse_id:int,source_block_id:int,locator_type:string,locator:array<string,mixed>,content_sha256:string,content:string}
     */
    public function read(array $publication, int $versionNo, int $blockId): array
    {
        if ($versionNo < 1 || $blockId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge source target is invalid');
        }
        $db = Db::connect('pgsql');
        $versions = $db->query(
            "SELECT kb.code AS owner_code,v.version_no,v.source_count,v.snapshot_sha256
             FROM sand_ai_knowledge_publication publication
             JOIN sand_ai_knowledge_base kb ON kb.id=publication.knowledge_base_id
                  AND kb.environment_id=publication.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=?
                  AND v.knowledge_base_id=kb.id
             WHERE publication.id=? AND publication.code=? AND publication.state='published'
               AND publication.consumer_environment_id=? AND publication.knowledge_base_id=?
               AND publication.owner_environment_id=?
               AND kb.status=1 AND kb.state='published' AND kb.delete_time IS NULL
               AND v.id=? AND v.version_no=? AND v.state='published'
               AND v.source_scope_sha256=? AND v.data_class IS NOT DISTINCT FROM ?
             FOR SHARE OF publication,kb,v",
            [
                $publication['active_version_id'], $publication['id'], $publication['code'], $publication['consumer_environment_id'],
                $publication['knowledge_base_id'], $publication['owner_environment_id'],
                $publication['active_version_id'], $versionNo, $publication['source_scope_sha256'],
                $publication['data_class'],
            ],
        );
        if (count($versions) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Current published knowledge version not found');
        }
        $version = $versions[0];
        $sources = $db->query(
            "SELECT s.file_id,s.parse_id,s.source_block_id,s.sequence_no,s.locator_type,s.locator,s.content_sha256,
                    p.source_block_count AS parse_source_count
             FROM sand_ai_knowledge_base_source s
             JOIN sand_ai_file f ON f.id=s.file_id
             JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id
             JOIN sand_ai_source_block b ON b.id=s.source_block_id
                  AND b.file_id=s.file_id AND b.parse_id=s.parse_id
             WHERE s.knowledge_base_version_id=? AND f.environment_id=?
               AND f.status=1 AND f.state='parsed' AND f.delete_time IS NULL
               AND p.status=1 AND p.state='succeeded' AND p.delete_time IS NULL
               AND b.status=1 AND b.delete_time IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM sand_ai_file_parse latest
                   WHERE latest.file_id=p.file_id AND latest.id>p.id
                     AND latest.status=1 AND latest.state='succeeded' AND latest.delete_time IS NULL
               )
               AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator
               AND b.content_sha256=s.content_sha256
               AND encode(sha256(convert_to(b.content,'UTF8')),'hex')=s.content_sha256
             ORDER BY s.file_id,s.parse_id,s.sequence_no,s.source_block_id
             FOR SHARE OF s,f,p,b",
            [$publication['active_version_id'], $publication['owner_environment_id']],
        );
        if ($sources === [] || count($sources) !== (int) $version['source_count']) self::drifted();
        $snapshot = [];
        $selected = null;
        $parseCounts = [];
        foreach ($sources as $source) {
            $parseId = (int) $source['parse_id'];
            $expectedParseCount = (int) $source['parse_source_count'];
            if ($expectedParseCount < 1) self::drifted();
            if (!isset($parseCounts[$parseId])) {
                $parseCounts[$parseId] = ['expected' => $expectedParseCount, 'actual' => 0];
            }
            if ($parseCounts[$parseId]['expected'] !== $expectedParseCount) self::drifted();
            $parseCounts[$parseId]['actual']++;
            $locator = $source['locator'];
            if (is_string($locator)) {
                try {
                    $locator = json_decode($locator, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    self::drifted();
                }
            }
            if (!is_array($locator) || array_is_list($locator)) self::drifted();
            $reference = [
                'file_id' => (int) $source['file_id'],
                'parse_id' => (int) $source['parse_id'],
                'source_block_id' => (int) $source['source_block_id'],
                'sequence_no' => (int) $source['sequence_no'],
                'locator_type' => (string) $source['locator_type'],
                'locator' => $locator,
                'content_sha256' => (string) $source['content_sha256'],
            ];
            $snapshot[] = $reference;
            if ($reference['source_block_id'] === $blockId) $selected = $reference;
        }
        foreach ($parseCounts as $parseCount) {
            if ($parseCount['actual'] !== $parseCount['expected']) self::drifted();
        }
        if (!hash_equals((string) $version['snapshot_sha256'], KnowledgeBasePolicy::fingerprint([
            'code' => (string) $version['owner_code'],
            'version' => (int) $version['version_no'],
            'sources' => $snapshot,
        ]))) self::drifted();
        if ($selected === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge source is not a version member');
        }
        $contents = $db->query(
            'SELECT content FROM sand_ai_source_block
             WHERE id=? AND file_id=? AND parse_id=? AND status=1 AND delete_time IS NULL',
            [$blockId, $selected['file_id'], $selected['parse_id']],
        );
        if (count($contents) !== 1 || !is_string($contents[0]['content'])
            || !hash_equals($selected['content_sha256'], hash('sha256', $contents[0]['content']))) self::drifted();
        return [
            'knowledge_base_code' => (string) $publication['code'],
            'knowledge_base_version' => $versionNo,
            'file_id' => $selected['file_id'],
            'parse_id' => $selected['parse_id'],
            'source_block_id' => $blockId,
            'locator_type' => $selected['locator_type'],
            'locator' => $selected['locator'],
            'content_sha256' => $selected['content_sha256'],
            'content' => $contents[0]['content'],
        ];
    }

    private static function drifted(): never
    {
        throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Published knowledge source snapshot changed');
    }
}
