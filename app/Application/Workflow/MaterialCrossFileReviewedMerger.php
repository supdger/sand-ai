<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Applies a sealed review to immutable per-file results before deterministic merging. */
final class MaterialCrossFileReviewedMerger
{
    public function __construct(private readonly MaterialAnalysisFileResultMerger $merger = new MaterialAnalysisFileResultMerger())
    {
    }

    /**
     * @param list<array{workflow_run_file_id:int,file_id:int,payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>,source_block_ids:list<int>}> $results
     * @param array{payload:array{conflicts:list<array<string,mixed>>,items_to_verify:list<array<string,mixed>>},field_sources:list<array{field_path:string,source_block_id:int}>,withheld_candidates:list<array{workflow_run_file_id:int,field_path:string}>} $review
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function merge(array $results, array $review): array
    {
        if (count($results) < 2 || !is_array($review['withheld_candidates'] ?? null)
            || !is_array($review['payload']['conflicts'] ?? null)
            || !is_array($review['payload']['items_to_verify'] ?? null)
            || !is_array($review['field_sources'] ?? null)) {
            self::invalid();
        }
        $withheld = [];
        foreach ($review['withheld_candidates'] as $candidate) {
            $runFileId = $candidate['workflow_run_file_id'] ?? null;
            $path = $candidate['field_path'] ?? null;
            if (!is_int($runFileId) || $runFileId < 1 || !is_string($path)
                || !preg_match('~^/(?:entities|events|extensions)/~', $path)
                || isset($withheld[$runFileId][$path])) {
                self::invalid();
            }
            $withheld[$runFileId][$path] = true;
        }
        $withheldCount = array_sum(array_map('count', $withheld));
        if (($review['payload']['items_to_verify'] === [] && $withheldCount !== 0)
            || ($review['payload']['items_to_verify'] !== [] && $withheldCount < 2)) {
            self::invalid();
        }
        $expectedReviewSources = [];
        foreach (['conflicts', 'items_to_verify'] as $kind) {
            if (!array_is_list($review['payload'][$kind])) {
                self::invalid();
            }
            foreach ($review['payload'][$kind] as $index => $item) {
                $ids = $item['source_block_ids'] ?? null;
                if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2
                    || !is_string($item['summary'] ?? null) || trim($item['summary']) === '') {
                    self::invalid();
                }
                foreach ($ids as $id) {
                    if (!is_int($id) || $id < 1 || isset($expectedReviewSources["/{$kind}/{$index}/summary"][$id])) {
                        self::invalid();
                    }
                    $expectedReviewSources["/{$kind}/{$index}/summary"][$id] = true;
                }
            }
        }
        $seenRunFiles = [];
        $allSources = [];
        $filtered = [];
        foreach ($results as $result) {
            $runFileId = $result['workflow_run_file_id'] ?? null;
            if (!is_int($runFileId) || $runFileId < 1 || isset($seenRunFiles[$runFileId])) {
                self::invalid();
            }
            $seenRunFiles[$runFileId] = true;
            foreach ($result['source_block_ids'] ?? [] as $sourceId) {
                if (!is_int($sourceId) || $sourceId < 1) {
                    self::invalid();
                }
                $allSources[$sourceId] = true;
            }
            $payload = $result['payload'] ?? null;
            if (!is_array($payload) || !is_array($result['field_sources'] ?? null)) {
                self::invalid();
            }
            $removed = [];
            $renamed = [];
            foreach (['entities' => 'value', 'events' => 'summary'] as $kind => $field) {
                $items = $payload[$kind] ?? null;
                if (!is_array($items) || !array_is_list($items)) {
                    self::invalid();
                }
                $kept = [];
                foreach ($items as $index => $item) {
                    $path = "/{$kind}/{$index}/{$field}";
                    if (isset($withheld[$runFileId][$path])) {
                        $removed[$path] = true;
                        continue;
                    }
                    $renamed["/{$kind}/{$index}"] = "/{$kind}/" . count($kept);
                    $kept[] = $item;
                }
                $payload[$kind] = $kept;
            }
            $extensions = $payload['extensions'] ?? null;
            if (!$extensions instanceof \stdClass) {
                self::invalid();
            }
            $newExtensions = new \stdClass();
            foreach ($extensions as $code => $items) {
                if (!is_string($code) || !is_array($items) || !array_is_list($items)) {
                    self::invalid();
                }
                $segment = str_replace(['~', '/'], ['~0', '~1'], $code);
                $kept = [];
                foreach ($items as $index => $item) {
                    $path = "/extensions/{$segment}/{$index}/value";
                    if (isset($withheld[$runFileId][$path])) {
                        $removed[$path] = true;
                        continue;
                    }
                    $renamed["/extensions/{$segment}/{$index}"] = "/extensions/{$segment}/" . count($kept);
                    $kept[] = $item;
                }
                $newExtensions->{$code} = $kept;
            }
            $payload['extensions'] = $newExtensions;
            if (count($removed) !== count($withheld[$runFileId] ?? [])) {
                self::invalid();
            }
            $sources = [];
            foreach ($result['field_sources'] as $source) {
                $path = $source['field_path'] ?? null;
                if (!is_string($path)) {
                    self::invalid();
                }
                $base = preg_replace('~/(?:value|summary|occurred_on)$~', '', $path);
                if (!is_string($base)) {
                    self::invalid();
                }
                if (isset($removed[$base . '/value']) || isset($removed[$base . '/summary'])) {
                    continue;
                }
                $sources[] = [
                    'field_path' => isset($renamed[$base]) ? $renamed[$base] . substr($path, strlen($base)) : $path,
                    'source_block_id' => $source['source_block_id'] ?? null,
                ];
            }
            $result['payload'] = $payload;
            $result['field_sources'] = $sources;
            $filtered[] = $result;
        }
        foreach (array_keys($withheld) as $runFileId) {
            if (!isset($seenRunFiles[$runFileId])) {
                self::invalid();
            }
        }
        $merged = $this->merger->merge($filtered);
        // Review paths describe the immutable per-file candidates, some of
        // which were withheld above. Never reuse them as merged-array indexes.
        $review['payload'] = (new MaterialConflictFieldLocator())->project($review['payload'], $results);
        $offsets = [
            'conflicts' => count($merged['payload']['conflicts']),
            'items_to_verify' => count($merged['payload']['items_to_verify']),
        ];
        foreach (['conflicts', 'items_to_verify'] as $kind) {
            array_push($merged['payload'][$kind], ...$review['payload'][$kind]);
        }
        $actualReviewSources = [];
        foreach ($review['field_sources'] as $source) {
            $path = $source['field_path'] ?? null;
            $sourceId = $source['source_block_id'] ?? null;
            if (!is_string($path) || !is_int($sourceId) || !isset($allSources[$sourceId])
                || preg_match('~^/(conflicts|items_to_verify)/(0|[1-9][0-9]*)/summary$~D', $path, $match) !== 1
                || !isset($expectedReviewSources[$path][$sourceId])
                || isset($actualReviewSources[$path][$sourceId])) {
                self::invalid();
            }
            $actualReviewSources[$path][$sourceId] = true;
            $merged['field_sources'][] = [
                'field_path' => '/' . $match[1] . '/' . ((int) $match[2] + $offsets[$match[1]]) . '/summary',
                'source_block_id' => $sourceId,
            ];
        }
        if ($actualReviewSources != $expectedReviewSources) {
            self::invalid();
        }
        return $merged;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The cross-file review cannot be merged with sealed file results');
    }
}
