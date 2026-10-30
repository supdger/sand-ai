<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Builds one deterministic view from already validated, immutable file results. */
final class MaterialAnalysisFileResultMerger
{
    /**
     * @param list<array{workflow_run_file_id:int,file_id:int,payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>,source_block_ids:list<int>}> $results
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function merge(array $results): array
    {
        if ($results === []) {
            self::invalid();
        }
        foreach ($results as $result) {
            if (!is_array($result) || !is_int($result['workflow_run_file_id'] ?? null)
                || $result['workflow_run_file_id'] < 1) {
                self::invalid();
            }
        }
        usort($results, static fn (array $left, array $right): int => $left['workflow_run_file_id'] <=> $right['workflow_run_file_id']);
        $merged = [
            'schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
            'documents' => [], 'entities' => [], 'events' => [], 'conflicts' => [],
            'items_to_verify' => [], 'warnings' => [], 'extensions' => new \stdClass(),
        ];
        $sources = [];
        $seenFiles = [];
        $seenFileIds = [];
        foreach ($results as $result) {
            $runFileId = $result['workflow_run_file_id'] ?? null;
            $fileId = $result['file_id'] ?? null;
            $payload = $result['payload'] ?? null;
            if (!is_int($runFileId) || $runFileId < 1 || !is_int($fileId) || $fileId < 1
                || isset($seenFiles[$runFileId]) || isset($seenFileIds[$fileId])
                || !is_array($payload) || ($payload['schema'] ?? null) !== $merged['schema']
                || !is_object($payload['extensions'] ?? null)
                || !is_array($result['field_sources'] ?? null)
                || !is_array($result['source_block_ids'] ?? null)) {
                self::invalid();
            }
            $seenFiles[$runFileId] = true;
            $seenFileIds[$fileId] = true;
            $offsets = [];
            foreach (['documents', 'entities', 'events', 'conflicts', 'items_to_verify'] as $field) {
                $items = $payload[$field] ?? null;
                if (!is_array($items) || !array_is_list($items)) {
                    self::invalid();
                }
                $offsets[$field] = count($merged[$field]);
                if ($field === 'documents') {
                    if ($items === []) {
                        self::invalid();
                    }
                    foreach ($items as $item) {
                        if (!is_array($item) || ($item['file_id'] ?? null) !== $fileId) {
                            self::invalid();
                        }
                    }
                }
                array_push($merged[$field], ...$items);
            }
            $warnings = $payload['warnings'] ?? null;
            if (!is_array($warnings) || !array_is_list($warnings)) {
                self::invalid();
            }
            foreach ($warnings as $warning) {
                if (!is_string($warning)) {
                    self::invalid();
                }
                $merged['warnings'][] = $warning;
            }
            foreach ($payload['extensions'] as $key => $items) {
                if (!is_string($key) || !is_array($items) || !array_is_list($items)) {
                    self::invalid();
                }
                $offsets['extensions'][$key] = count($merged['extensions']->{$key} ?? []);
                $merged['extensions']->{$key} = [
                    ...($merged['extensions']->{$key} ?? []),
                    ...$items,
                ];
            }
            $allowedSources = [];
            foreach ($result['source_block_ids'] as $sourceId) {
                if (!is_int($sourceId) || $sourceId < 1) {
                    self::invalid();
                }
                $allowedSources[$sourceId] = true;
            }
            foreach ($result['field_sources'] as $source) {
                $path = $source['field_path'] ?? null;
                $sourceId = $source['source_block_id'] ?? null;
                if (!is_string($path) || !is_int($sourceId) || !isset($allowedSources[$sourceId])) {
                    self::invalid();
                }
                $sources[] = [
                    'field_path' => $this->rebasePath($path, $payload, $offsets),
                    'source_block_id' => $sourceId,
                ];
            }
        }
        $merged['warnings'] = array_values(array_unique($merged['warnings']));
        return ['payload' => $merged, 'field_sources' => $sources];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $offsets */
    private function rebasePath(string $path, array $payload, array $offsets): string
    {
        if (preg_match('~^/(documents|entities|events|conflicts|items_to_verify)/(0|[1-9][0-9]*)(/.+)$~D', $path, $match) === 1) {
            $allowed = match ($match[1]) {
                'documents' => ['/classification/value', '/title/value'],
                'entities' => ['/value'],
                'events' => ['/summary', '/occurred_on'],
                'conflicts', 'items_to_verify' => ['/summary'],
            };
            if (!in_array($match[3], $allowed, true)
                || !array_key_exists((int) $match[2], $payload[$match[1]])) {
                self::invalid();
            }
            return '/' . $match[1] . '/' . ((int) $match[2] + $offsets[$match[1]]) . $match[3];
        }
        if (preg_match('~^/extensions/([^/]+)/(0|[1-9][0-9]*)(/value)$~D', $path, $match) === 1) {
            $key = $this->decodeSegment($match[1]);
            if (!array_key_exists($key, $offsets['extensions'] ?? [])
                || !array_key_exists((int) $match[2], $payload['extensions']->{$key})) {
                self::invalid();
            }
            return '/extensions/' . $match[1] . '/' . ((int) $match[2] + $offsets['extensions'][$key]) . $match[3];
        }
        self::invalid();
    }

    private function decodeSegment(string $segment): string
    {
        if (preg_match('/~(?![01])/', $segment) === 1) {
            self::invalid();
        }
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A material file result cannot be merged');
    }
}
