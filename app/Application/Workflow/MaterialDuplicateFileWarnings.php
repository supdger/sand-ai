<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Exact frozen file bytes only; preserves every sealed result and source. */
final class MaterialDuplicateFileWarnings
{
    /**
     * @param array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} $manifest
     * @param list<array{file_id:int,sha256:string,source_block_ids:list<int>}> $files
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function append(array $manifest, array $files): array
    {
        if ($files === [] || count($files) > 100
            || !is_array($manifest['payload']['warnings'] ?? null)
            || !array_is_list($manifest['payload']['warnings'])
            || !is_array($manifest['field_sources'] ?? null)) {
            self::invalid();
        }
        $groups = [];
        $seen = [];
        foreach ($files as $file) {
            $id = $file['file_id'] ?? null;
            $sha256 = $file['sha256'] ?? null;
            $sources = $file['source_block_ids'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id])
                || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
                || !is_array($sources) || !array_is_list($sources) || $sources === []) {
                self::invalid();
            }
            foreach ($sources as $sourceId) {
                if (!is_int($sourceId) || $sourceId < 1) {
                    self::invalid();
                }
            }
            $seen[$id] = true;
            sort($sources, SORT_NUMERIC);
            $groups[$sha256][] = ['file_id' => $id, 'source_block_id' => $sources[0]];
        }
        // Stable order follows the frozen file IDs, never provider output order.
        $duplicateGroups = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static fn (array $left, array $right): int => $left['file_id'] <=> $right['file_id']);
            $duplicateGroups[] = $group;
        }
        usort($duplicateGroups, static fn (array $left, array $right): int => $left[0]['file_id'] <=> $right[0]['file_id']);
        foreach ($duplicateGroups as $group) {
            $anchor = $group[0];
            foreach (array_slice($group, 1) as $member) {
                if ($anchor['source_block_id'] === $member['source_block_id']) {
                    self::invalid();
                }
                $index = count($manifest['payload']['warnings']);
                $manifest['payload']['warnings'][] = '重复材料（完整文件字节相同）：文件 #'
                    . $anchor['file_id'] . ' 与文件 #' . $member['file_id']
                    . '。请查看两份来源；各文件成果和已记录费用均保留。此提示不判断近似或语义重复。';
                foreach ([$anchor, $member] as $participant) {
                    $manifest['field_sources'][] = [
                        'field_path' => '/warnings/' . $index,
                        'source_block_id' => $participant['source_block_id'],
                    ];
                }
            }
        }
        return $manifest;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Frozen duplicate material evidence is invalid');
    }
}
