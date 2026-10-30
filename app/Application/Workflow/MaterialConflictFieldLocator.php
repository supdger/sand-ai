<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/**
 * Resolves conflict locations in verified immutable per-file results.
 * A model's original candidate path is never a pointer into a merged artifact.
 */
final class MaterialConflictFieldLocator
{
    /**
     * @param array<string,mixed> $payload
     * @param list<array{file_id:int,payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}> $sealedResults
     * @return array<string,mixed>
     */
    public function project(array $payload, array $sealedResults): array
    {
        if (!is_array($payload['conflicts'] ?? null) || !array_is_list($payload['conflicts'])) {
            return $payload;
        }
        foreach ($payload['conflicts'] as $index => $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            // Recompute this read projection; never trust a persisted or model-supplied marker.
            $payload['conflicts'][$index]['field_location'] = $this->location($conflict, $sealedResults);
        }
        return $payload;
    }

    /** @param array<string,mixed> $conflict @param list<array<string,mixed>> $sealedResults @return array<string,mixed> */
    private function location(array $conflict, array $sealedResults): array
    {
        $unconfirmed = [
            'version' => 'file_result_fields@1', 'state' => 'unconfirmed',
            'scope' => null, 'references' => [],
        ];
        $path = $conflict['field'] ?? null;
        $sourceIds = $this->sourceIds($conflict['source_block_ids'] ?? null);
        if (!is_string($path) || $sourceIds === null || count($sourceIds) < 2) {
            return $unconfirmed;
        }
        $references = [];
        $covered = [];
        $labels = [];
        $values = [];
        foreach ($sealedResults as $result) {
            $fileId = $result['file_id'] ?? null;
            if (!is_int($fileId) || $fileId < 1 || !is_array($result['payload'] ?? null)
                || !is_array($result['field_sources'] ?? null)) {
                return $unconfirmed;
            }
            $candidate = $this->candidate($result['payload'], $path);
            if ($candidate === null || array_diff($candidate['source_block_ids'], $sourceIds) !== []) {
                continue;
            }
            $mapped = [];
            foreach ($result['field_sources'] as $source) {
                if (is_array($source) && ($source['field_path'] ?? null) === $path
                    && is_int($source['source_block_id'] ?? null)) {
                    $mapped[$source['source_block_id']] = true;
                }
            }
            if (count($mapped) !== count($candidate['source_block_ids'])
                || array_diff($candidate['source_block_ids'], array_keys($mapped)) !== []) {
                return $unconfirmed;
            }
            if (isset($references[$fileId])) {
                return $unconfirmed;
            }
            $references[$fileId] = [
                'file_id' => $fileId, 'field_path' => $path,
                'label' => $candidate['label'], 'value' => $candidate['value'],
                'source_block_ids' => $candidate['source_block_ids'],
            ];
            foreach ($candidate['source_block_ids'] as $sourceId) {
                if (isset($covered[$sourceId])) {
                    // A source block cannot belong to two different fixed files.
                    return $unconfirmed;
                }
                $covered[$sourceId] = true;
            }
            $labels[$candidate['label']] = true;
            $values[$candidate['value']] = true;
        }
        if (count($references) < 2 || count($labels) !== 1 || count($values) < 2
            || count($covered) !== count($sourceIds) || array_diff($sourceIds, array_keys($covered)) !== []) {
            return $unconfirmed;
        }
        ksort($references, SORT_NUMERIC);
        return [
            'version' => 'file_result_fields@1', 'state' => 'verified',
            'scope' => 'file_result', 'references' => array_values($references),
        ];
    }

    /** @param array<string,mixed> $payload @return array{label:string,value:string,source_block_ids:list<int>}|null */
    private function candidate(array $payload, string $path): ?array
    {
        $label = null;
        $item = null;
        $valueKey = 'value';
        if (preg_match('~^/entities/(0|[1-9][0-9]*)/value$~D', $path, $match) === 1) {
            $item = $payload['entities'][(int) $match[1]] ?? null;
            $label = is_array($item) ? ($item['type'] ?? null) : null;
        } elseif (preg_match('~^/events/(0|[1-9][0-9]*)/summary$~D', $path, $match) === 1) {
            $item = $payload['events'][(int) $match[1]] ?? null;
            $label = '事件';
            $valueKey = 'summary';
        } elseif (preg_match('~^/extensions/([^/]+)/(0|[1-9][0-9]*)/value$~D', $path, $match) === 1) {
            if (preg_match('/~(?![01])/', $match[1]) === 1) {
                return null;
            }
            $code = str_replace(['~1', '~0'], ['/', '~'], $match[1]);
            $extensions = $payload['extensions'] ?? null;
            $items = $extensions instanceof \stdClass ? ($extensions->{$code} ?? null)
                : (is_array($extensions) ? ($extensions[$code] ?? null) : null);
            $item = is_array($items) ? ($items[(int) $match[2]] ?? null) : null;
            $label = '扩展字段 · ' . $code;
        }
        if (!is_array($item) || !is_string($label) || trim($label) === ''
            || !is_string($item[$valueKey] ?? null) || trim($item[$valueKey]) === '') {
            return null;
        }
        $ids = $this->sourceIds($item['source_block_ids'] ?? null);
        if ($ids === null || $ids === []) {
            return null;
        }
        return ['label' => $label, 'value' => $item[$valueKey], 'source_block_ids' => $ids];
    }

    /** @return list<int>|null */
    private function sourceIds(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                return null;
            }
            $ids[$id] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }
}
