<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use think\facade\Db;

class KnowledgeVersionSourceScope
{
    /** @param array{organization_id:int,application_id:int,environment_id:int} $owner
     *  @return array{source_scope_sha256:string,data_class:?string}
     */
    public function resolve(int $versionId, array $owner): array
    {
        if ($versionId < 1) self::invalid();
        $rows = Db::connect('pgsql')->query(
            'SELECT DISTINCT f.id,f.status,f.delete_time,f.state,f.environment_id,f.owner_scope,f.owner_attribution
             FROM sand_ai_knowledge_base_source s
             JOIN sand_ai_file f ON f.id=s.file_id
             WHERE s.knowledge_base_version_id=?
             ORDER BY f.id',
            [$versionId],
        );
        return self::fromFiles($rows, $owner);
    }

    /** @param list<array<string,mixed>> $files
     *  @param array{organization_id:int,application_id:int,environment_id:int} $owner
     *  @return array{source_scope_sha256:string,data_class:?string}
     */
    public static function fromFiles(array $files, array $owner): array
    {
        if ($files === []) self::invalid();
        $entries = [];
        $expected = null;
        foreach ($files as $file) {
            if (is_string($file['owner_attribution'] ?? null)) {
                try {
                    $file['owner_attribution'] = json_decode($file['owner_attribution'], true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    self::invalid();
                }
            }
            $facts = KnowledgeSourceAttribution::fromFile($file, $owner['environment_id']);
            if ($facts['organization_id'] !== $owner['organization_id']
                || $facts['application_id'] !== $owner['application_id']) {
                self::invalid();
            }
            if ($expected === null) $expected = $facts;
            else KnowledgeSourceAttribution::assertCompatible($expected, $facts);
            $fileId = (int) $file['id'];
            if ($fileId < 1 || isset($entries[$fileId])) self::invalid();
            $entries[$fileId] = [
                'file_id' => $fileId,
                'owner_scope' => $file['owner_scope'],
                'data_class' => $facts['data_class'],
            ];
        }
        ksort($entries, SORT_NUMERIC);
        return [
            'source_scope_sha256' => hash('sha256', json_encode(array_values($entries), JSON_THROW_ON_ERROR)),
            'data_class' => $expected['data_class'],
        ];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Knowledge source ownership is unavailable');
    }
}
