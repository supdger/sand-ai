<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use NeuronAI\StructuredOutput\JsonExtractor;

/** Checks review claims against the exact sealed candidates and sources sent to the model. */
final class MaterialCrossFileReviewOutputValidator
{
    /**
     * @param array<int,int> $promptSourceFileIds source_block_id => fixed file_id
     * @param list<array{workflow_run_file_id:int,file_id:int,field_path:string,source_block_ids:list<int>}> $promptCandidates
     * @return array{payload:array{conflicts:list<array<string,mixed>>,items_to_verify:list<array<string,mixed>>},field_sources:list<array{field_path:string,source_block_id:int}>,withheld_candidates:list<array{workflow_run_file_id:int,field_path:string}>}
     */
    public function validate(string $json, array $promptSourceFileIds, array $promptCandidates, int $maxCharacters, ?MaterialReviewOutputContract $output = null): array
    {
        if ($maxCharacters < 1) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review output limit is invalid');
        }
        foreach ($promptSourceFileIds as $sourceId => $fileId) {
            if (!is_int($sourceId) || $sourceId < 1 || !is_int($fileId) || $fileId < 1) {
                self::invalidSource();
            }
        }
        $candidates = [];
        $runFileIds = [];
        $fileRunIds = [];
        foreach ($promptCandidates as $candidate) {
            $runFileId = $candidate['workflow_run_file_id'] ?? null;
            $fileId = $candidate['file_id'] ?? null;
            $path = $candidate['field_path'] ?? null;
            $ids = $candidate['source_block_ids'] ?? null;
            if (!is_int($runFileId) || $runFileId < 1 || !is_int($fileId) || $fileId < 1
                || !is_string($path) || !$this->candidatePath($path)
                || !is_array($ids) || !array_is_list($ids) || $ids === []) {
                self::invalidSource();
            }
            $idSet = [];
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1 || ($promptSourceFileIds[$id] ?? null) !== $fileId) {
                    self::invalidSource();
                }
                $idSet[$id] = true;
            }
            $key = $runFileId . ':' . $path;
            if (isset($candidates[$key])
                || (isset($runFileIds[$runFileId]) && $runFileIds[$runFileId] !== $fileId)
                || (isset($fileRunIds[$fileId]) && $fileRunIds[$fileId] !== $runFileId)) {
                self::invalidSource();
            }
            $runFileIds[$runFileId] = $fileId;
            $fileRunIds[$fileId] = $runFileId;
            $candidates[$key] = ['workflow_run_file_id' => $runFileId, 'file_id' => $fileId, 'field_path' => $path, 'source_ids' => $idSet];
        }
        if (mb_strlen($json, 'UTF-8') > $maxCharacters) {
            throw new MaterialReviewOutputRejected('SAND_AI_SCHEMA_UNSUPPORTED');
        }
        try {
            // Preserve the frozen complete-JSON format through the SDK parser.
            $structuredJson = (new JsonExtractor())
                ->setExtractors([static fn (string $text): array => [$text]])
                ->getJson($json);
            if ($structuredJson === null) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review output must be JSON');
            }
            try {
                // Projections retain business object/list, field and source rules.
                $raw = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
                $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review output must be JSON');
            }
            if (!$raw instanceof \stdClass || !property_exists($raw, 'items_to_verify')
                || !is_array($raw->items_to_verify) || !is_array($value)
                || array_diff(array_keys($value), ['items_to_verify']) !== []
                || !array_key_exists('items_to_verify', $value)
                || !is_array($value['items_to_verify']) || !array_is_list($value['items_to_verify'])) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review output has unsupported fields');
            }

            $conflicts = [];
            $itemsToVerify = [];
            $fieldSources = [];
            $withheld = [];
            foreach ($value['items_to_verify'] as $index => $item) {
                $rawItem = $raw->items_to_verify[$index] ?? null;
                if (!$rawItem instanceof \stdClass
                    || !is_array($rawItem->source_block_ids ?? null)
                    || !is_array($rawItem->affected_candidates ?? null)) {
                    throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review item has invalid JSON types');
                }
                $this->exactKeys($item, ['reason', 'field', 'summary', 'source_block_ids', 'affected_candidates']);
                $reason = $item['reason'] ?? null;
                if (!in_array($reason, ['conflict', 'ambiguous'], true)) {
                    throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review reason is invalid');
                }
                $field = $this->boundedString($item['field'] ?? null, 128);
                $summary = $this->boundedString($item['summary'] ?? null, 2000);
                $affected = $item['affected_candidates'] ?? null;
                if (!is_array($affected) || !array_is_list($affected) || count($affected) < 2) {
                    self::invalidSource();
                }
                $affectedKeys = [];
                $affectedFileIds = [];
                foreach ($affected as $referenceIndex => $reference) {
                    if (!(($rawItem->affected_candidates[$referenceIndex] ?? null) instanceof \stdClass)) {
                        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A cross-file review candidate has invalid JSON type');
                    }
                    $this->exactKeys($reference, ['workflow_run_file_id', 'field_path']);
                    $runFileId = $reference['workflow_run_file_id'] ?? null;
                    $path = $reference['field_path'] ?? null;
                    if (!is_int($runFileId) || !is_string($path)) {
                        self::invalidSource();
                    }
                    $key = $runFileId . ':' . $path;
                    if (!isset($candidates[$key]) || isset($affectedKeys[$key])) {
                        self::invalidSource();
                    }
                    $affectedKeys[$key] = true;
                    $affectedFileIds[$candidates[$key]['file_id']] = true;
                }
                if (count($affectedFileIds) < 2) {
                    self::invalidSource();
                }
                $ids = $this->citedSources($item['source_block_ids'] ?? null, $affectedKeys, $candidates, $promptSourceFileIds);
                foreach (array_keys($affectedKeys) as $affectedKey) {
                    $candidate = $candidates[$affectedKey];
                    $withheld[$affectedKey] = [
                        'workflow_run_file_id' => $candidate['workflow_run_file_id'],
                        'field_path' => $candidate['field_path'],
                    ];
                }
                if ($reason === 'conflict') {
                    $index = count($conflicts);
                    $conflicts[] = ['field' => $field, 'summary' => $summary, 'source_block_ids' => $ids, 'evidence_state' => 'conflicted'];
                    $this->addSources($fieldSources, "/conflicts/{$index}/summary", $ids);
                }
                $index = count($itemsToVerify);
                $itemsToVerify[] = ['reason' => $reason, 'summary' => $summary, 'source_block_ids' => $ids];
                $this->addSources($fieldSources, "/items_to_verify/{$index}/summary", $ids);
            }
            // Strict raw JSON and dynamic source checks precede SDK casting.
            $output?->validateTyped($json);
            return [
                'payload' => ['conflicts' => $conflicts, 'items_to_verify' => $itemsToVerify],
                'field_sources' => $fieldSources,
                'withheld_candidates' => array_values($withheld),
            ];
        } catch (ApiProblem $failure) {
            if (!in_array($failure->errorCode, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'], true)) {
                throw $failure;
            }
            throw new MaterialReviewOutputRejected($failure->errorCode);
        }
    }

    /** @param array<string,true> $affected @param array<string,array<string,mixed>> $candidates @param array<int,int> $sourceFileIds @return list<int> */
    private function citedSources(mixed $value, array $affected, array $candidates, array $sourceFileIds): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::invalidSource();
        }
        $allowed = [];
        foreach (array_keys($affected) as $key) {
            foreach ($candidates[$key]['source_ids'] as $id => $_) {
                $allowed[$id] = true;
            }
        }
        $ids = [];
        $files = [];
        foreach ($value as $id) {
            if (!is_int($id) || !isset($allowed[$id]) || !isset($sourceFileIds[$id])) {
                self::invalidSource();
            }
            $ids[$id] = true;
            $files[$sourceFileIds[$id]] = true;
        }
        if (count($ids) < 2 || count($files) < 2) {
            self::invalidSource();
        }
        foreach (array_keys($affected) as $key) {
            if (array_intersect_key($candidates[$key]['source_ids'], $ids) === []) {
                self::invalidSource();
            }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function candidatePath(string $path): bool
    {
        return preg_match('~^/(?:entities/(?:0|[1-9][0-9]*)/value|events/(?:0|[1-9][0-9]*)/summary|extensions/[^/]+/(?:0|[1-9][0-9]*)/value)$~D', $path) === 1;
    }

    /** @param list<array{field_path:string,source_block_id:int}> $sources @param list<int> $ids */
    private function addSources(array &$sources, string $path, array $ids): void
    {
        foreach ($ids as $id) {
            $sources[] = ['field_path' => $path, 'source_block_id' => $id];
        }
    }

    /** @param list<string> $keys */
    private function exactKeys(mixed $value, array $keys): void
    {
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The cross-file review item has unsupported fields');
        }
    }

    private function boundedString(mixed $value, int $maximum): string
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A cross-file review value must be text');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A cross-file review value is invalid');
        }
        return $value;
    }

    private static function invalidSource(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The cross-file review source evidence is invalid');
    }
}
