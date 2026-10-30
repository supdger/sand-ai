<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\Validation\Validator;

final class MaterialAnalysisOutputValidator
{
    /**
     * @param array<string,mixed> $profile
     * @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function validate(string $json, array $profile, array $files, array $allowedSourceBlockIds): array
    {
        if (mb_strlen($json, 'UTF-8') > (int) $profile['max_output_characters']) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis output is too large');
        }
        // The frozen material wire format requires a complete JSON value.
        // Use the SDK parser without its Markdown or bracket recovery modes.
        $structuredJson = (new JsonExtractor())
            ->setExtractors([static fn (string $text): array => [$text]])
            ->getJson($json);
        if ($structuredJson === null) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis output must be JSON');
        }
        try {
            // These projections retain the business object/list distinction and
            // exact field/source checks; JSON extraction belongs to Neuron.
            $rawValue = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis output must be JSON');
        }
        if (!$rawValue instanceof \stdClass || !is_array($value) || array_is_list($value)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis output must be an object');
        }
        $expected = ['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings', 'extensions'];
        if (array_diff(array_keys($value), $expected) !== [] || array_diff($expected, array_keys($value)) !== []) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis output has unsupported fields');
        }
        foreach (['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings'] as $key) {
            if (!is_array($value[$key]) || !array_is_list($value[$key])) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "material analysis {$key} must be a list");
            }
        }
        if (!property_exists($rawValue, 'extensions') || !$rawValue->extensions instanceof \stdClass || !is_array($value['extensions'])) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'material analysis extensions must be an object');
        }

        $sourceBlocks = $this->sourceBlocks($files, $allowedSourceBlockIds);
        $fileIds = array_fill_keys(array_column($files, 'file_id'), true);
        $fileSourceBlocks = [];
        foreach ($files as $file) {
            foreach ($file['blocks'] as $block) {
                if (isset($sourceBlocks[$block['id']])) {
                    $fileSourceBlocks[$file['file_id']][(int) $block['id']] = true;
                }
            }
        }
        $fieldSources = [];
        $documents = [];
        $coveredFileIds = [];
        foreach ($value['documents'] as $index => $document) {
            $this->exactObject($document, ['file_id', 'classification', 'title'], 'documents[]');
            $fileId = $this->positiveInt($document['file_id'] ?? null, 'documents[].file_id');
            if (!isset($fileIds[$fileId])) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A document references a file outside the fixed workflow snapshot');
            }
            $coveredFileIds[$fileId] = true;
            $documents[] = [
                'file_id' => $fileId,
                'classification' => $this->documentCandidate($document['classification'] ?? null, "documents[{$index}].classification", $fileSourceBlocks[$fileId] ?? [], $fieldSources, "/documents/{$index}/classification/value", (array) $profile['document_classifications']),
                'title' => $this->documentCandidate($document['title'] ?? null, "documents[{$index}].title", $fileSourceBlocks[$fileId] ?? [], $fieldSources, "/documents/{$index}/title/value"),
            ];
        }
        if (count($coveredFileIds) !== count($fileIds)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The material analysis output omits a fixed source file');
        }
        $entities = [];
        foreach ($value['entities'] as $index => $entity) {
            $this->exactObject($entity, ['type', 'value', 'source_block_ids', 'evidence_state'], 'entities[]');
            $type = $this->stringValue($entity['type'] ?? null, 'entities[].type', MaterialAnalysisProfilePolicy::MAX_ENTITY_TYPE_CHARACTERS);
            if (!in_array($type, $profile['entity_types'], true)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis entity type is not allowed by the profile');
            }
            $entities[] = ['type' => $type] + $this->candidate([
                'value' => $entity['value'] ?? null,
                'source_block_ids' => $entity['source_block_ids'] ?? null,
                'evidence_state' => $entity['evidence_state'] ?? null,
            ], "entities[{$index}]", $sourceBlocks, $fieldSources, "/entities/{$index}/value");
        }
        $events = [];
        foreach ($value['events'] as $index => $event) {
            $this->exactObject($event, ['summary', 'occurred_on', 'source_block_ids', 'evidence_state'], 'events[]');
            $candidate = $this->candidate(['value' => $event['summary'] ?? null, 'source_block_ids' => $event['source_block_ids'] ?? null, 'evidence_state' => $event['evidence_state'] ?? null], "events[{$index}]", $sourceBlocks, $fieldSources, "/events/{$index}/summary");
            $occurredOn = $event['occurred_on'] ?? null;
            if ($occurredOn !== null && !$this->calendarDate($occurredOn)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'An event date must use YYYY-MM-DD or null');
            }
            if ($occurredOn !== null) {
                $this->addSources($fieldSources, "/events/{$index}/occurred_on", $candidate['source_block_ids']);
            }
            $events[] = ['summary' => $candidate['value'], 'occurred_on' => $occurredOn, 'source_block_ids' => $candidate['source_block_ids'], 'evidence_state' => $candidate['evidence_state']];
        }
        $conflicts = [];
        foreach ($value['conflicts'] as $index => $conflict) {
            $this->exactObject($conflict, ['field', 'summary', 'source_block_ids'], 'conflicts[]');
            $sources = $this->sourceIds($conflict['source_block_ids'] ?? null, $sourceBlocks, 'conflicts[].source_block_ids', 2);
            $conflicts[] = [
                'field' => $this->stringValue($conflict['field'] ?? null, 'conflicts[].field', 128),
                'summary' => $this->stringValue($conflict['summary'] ?? null, 'conflicts[].summary', 2000),
                'source_block_ids' => $sources,
                'evidence_state' => 'conflicted',
            ];
            $this->addSources($fieldSources, "/conflicts/{$index}/summary", $sources);
        }
        $itemsToVerify = [];
        foreach ($value['items_to_verify'] as $index => $item) {
            $this->exactObject($item, ['reason', 'summary', 'source_block_ids'], 'items_to_verify[]');
            $reason = $this->stringValue($item['reason'] ?? null, 'items_to_verify[].reason', 64);
            if (!in_array($reason, ['missing_source', 'single_source', 'conflict', 'ambiguous'], true)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis verification reason is invalid');
            }
            $minimumSources = match ($reason) {
                'missing_source' => 0,
                'conflict' => 2,
                default => 1,
            };
            $sources = $this->sourceIds($item['source_block_ids'] ?? null, $sourceBlocks, 'items_to_verify[].source_block_ids', $minimumSources);
            if ($reason === 'single_source' && count($sources) !== 1) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A single-source item must cite exactly one source block');
            }
            $summary = $item['summary'] ?? null;
            if ($reason === 'missing_source') {
                if ($sources !== [] || $summary !== null) {
                    throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A missing-source item must not retain an unsupported claim');
                }
            } else {
                $summary = $this->stringValue($summary, 'items_to_verify[].summary', 2000);
                $this->addSources($fieldSources, "/items_to_verify/{$index}/summary", $sources);
            }
            $itemsToVerify[] = ['reason' => $reason, 'summary' => $summary, 'source_block_ids' => $sources];
        }
        $warnings = [];
        foreach ($value['warnings'] as $warning) {
            $warnings[] = $this->stringValue($warning, 'warnings[]', 160);
        }
        $extensions = new \stdClass();
        foreach ($value['extensions'] as $fieldCode => $items) {
            if (!is_string($fieldCode) || !in_array($fieldCode, $profile['extension_fields'], true) || !is_array($items) || !array_is_list($items)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis extension is not allowed by the profile');
            }
            $extensions->{$fieldCode} = [];
            foreach ($items as $index => $item) {
                $extensions->{$fieldCode}[] = $this->candidate($item, "extensions.{$fieldCode}[{$index}]", $sourceBlocks, $fieldSources, '/extensions/' . $this->jsonPointerSegment($fieldCode) . "/{$index}/value");
            }
        }

        return [
            'payload' => [
                'schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
                'documents' => $documents,
                'entities' => $entities,
                'events' => $events,
                'conflicts' => $conflicts,
                'items_to_verify' => $itemsToVerify,
                'warnings' => array_values(array_unique($warnings)),
                'extensions' => $extensions,
            ],
            'field_sources' => $fieldSources,
        ];
    }

    /**
     * The prompt/source preflight must not be mistaken for invalid model output.
     *
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $files
     * @param list<int> $allowedSourceBlockIds
     * @return array<string,mixed>
     */
    public function validateProviderOutput(string $json, array $profile, array $files, array $allowedSourceBlockIds): array
    {
        $this->sourceBlocks($files, $allowedSourceBlockIds);
        try {
            return $this->validate($json, $profile, $files, $allowedSourceBlockIds);
        } catch (ApiProblem $problem) {
            if (!in_array($problem->errorCode, [
                'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
            ], true)) {
                throw $problem;
            }
            throw new MaterialAnalysisOutputRejected($problem->errorCode);
        }
    }

    /** @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files @param list<int> $allowedSourceBlockIds @return array<int,true> */
    private function sourceBlocks(array $files, array $allowedSourceBlockIds): array
    {
        $allowed = [];
        foreach ($allowedSourceBlockIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The material analysis prompt has an invalid source block id');
            }
            $allowed[$id] = true;
        }
        $sourceBlocks = [];
        foreach ($files as $file) {
            foreach ($file['blocks'] as $block) {
                if (isset($allowed[$block['id']])) {
                    $sourceBlocks[(int) $block['id']] = true;
                }
            }
        }
        if (count($sourceBlocks) !== count($allowed)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The material analysis prompt source set is outside the fixed workflow snapshot');
        }
        return $sourceBlocks;
    }

    /** @param array<int,true> $allowedSources @param list<array{field_path:string,source_block_id:int}> $fieldSources @param list<string>|null $allowedValues @return array{value:string,source_block_ids:list<int>,evidence_state:string} */
    private function candidate(mixed $value, string $name, array $allowedSources, array &$fieldSources, string $fieldPath, ?array $allowedValues = null): array
    {
        $this->exactObject($value, ['value', 'source_block_ids', 'evidence_state'], $name);
        if (!is_string($value['value']) || !is_string($value['evidence_state'])) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A material analysis candidate must use strict JSON text fields');
        }
        $sources = $this->sourceIds($value['source_block_ids'], $allowedSources, "{$name}.source_block_ids", 1);
        try {
            $candidate = (new Deserializer())->fromJson(json_encode([
                'value' => trim($value['value']),
                'source_block_ids' => $sources,
                'evidence_state' => $value['evidence_state'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), MaterialAnalysisCandidateSchema::class);
            if (!$candidate instanceof MaterialAnalysisCandidateSchema || Validator::validate($candidate) !== []) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A material analysis candidate does not match its typed schema');
            }
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'A material analysis candidate does not match its typed schema');
        }
        $candidateValue = $candidate->value;
        if ($allowedValues !== null && !in_array($candidateValue, $allowedValues, true)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The candidate value is not allowed by the material analysis profile');
        }
        $state = $candidate->evidence_state->value;
        if (($state === 'confirmed' && count($sources) < 2) || ($state === 'uncertain' && count($sources) !== 1)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Candidate evidence state does not match its source count');
        }
        $this->addSources($fieldSources, $fieldPath, $sources);
        return ['value' => $candidateValue, 'source_block_ids' => $sources, 'evidence_state' => $state];
    }

    /** @param array<int,true> $allowedSources @param list<array{field_path:string,source_block_id:int}> $fieldSources @param list<string>|null $allowedValues @return array{value:string|null,source_block_ids:list<int>,evidence_state:string} */
    private function documentCandidate(mixed $value, string $name, array $allowedSources, array &$fieldSources, string $fieldPath, ?array $allowedValues = null): array
    {
        $this->exactObject($value, ['value', 'source_block_ids', 'evidence_state'], $name);
        if ($value['value'] === null) {
            if ($value['source_block_ids'] !== [] || $value['evidence_state'] !== 'missing_source') {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A missing document field must have no claim or source');
            }
            return ['value' => null, 'source_block_ids' => [], 'evidence_state' => 'missing_source'];
        }
        return $this->candidate($value, $name, $allowedSources, $fieldSources, $fieldPath, $allowedValues);
    }

    /** @param array<int,true> $allowedSources @return list<int> */
    private function sourceIds(mixed $value, array $allowedSources, string $name, int $minimum): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', "{$name} must be a list");
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || !isset($allowedSources[$id])) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', "{$name} contains a source outside the fixed workflow snapshot");
            }
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        if (count($ids) < $minimum) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', "{$name} has too few sources");
        }
        return $ids;
    }

    /** @param list<array{field_path:string,source_block_id:int}> $fieldSources @param list<int> $sourceIds */
    private function addSources(array &$fieldSources, string $fieldPath, array $sourceIds): void
    {
        foreach ($sourceIds as $sourceId) {
            $fieldSources[] = ['field_path' => $fieldPath, 'source_block_id' => $sourceId];
        }
    }

    /** @param list<string> $keys */
    private function exactObject(mixed $value, array $keys, string $name): void
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name} has unsupported fields");
        }
    }

    private function positiveInt(mixed $value, string $name): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name} must be a positive integer");
        }
        return $value;
    }

    private function stringValue(mixed $value, string $name, int $maximum): string
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name} must be a string");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name} has an invalid length");
        }
        return $value;
    }

    private function calendarDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function jsonPointerSegment(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }
}
