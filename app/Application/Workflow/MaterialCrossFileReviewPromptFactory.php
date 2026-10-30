<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Builds one complete review request from sealed per-file results and their cited blocks. */
final class MaterialCrossFileReviewPromptFactory
{
    /**
     * The caller must first reload sealed results and source blocks under the fixed run
     * snapshot, including ownership and parse checks. This class checks their local
     * consistency and refuses to silently omit a result or referenced block.
     *
     * @param array<string,mixed> $profile
     * @param list<array{workflow_run_file_id:int,file_id:int,parse_id:int,payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>,blocks:list<array{id:int,file_id:int,parse_id:int,content:string,content_sha256:string}>}> $files
     * @return array{messages:list<array{role:string,content:string}>,source_file_ids:array<int,int>,candidates:list<array{workflow_run_file_id:int,file_id:int,field_path:string,source_block_ids:list<int>}>,input_sha256:string}
     */
    public function build(array $profile, array $files, ?MaterialReviewOutputContract $output = null, bool $chunked = false): array
    {
        $maximum = $profile['max_prompt_characters'] ?? null;
        $language = $profile['output_language'] ?? null;
        if (!is_int($maximum) || $maximum < 1 || !is_string($language) || trim($language) === '') {
            self::invalid();
        }
        if (count($files) < 2) {
            self::invalid();
        }
        $runFileIds = [];
        $fileIds = [];
        $sourceFileIds = [];
        $candidates = [];
        $results = [];
        $blocks = [];
        foreach ($files as $file) {
            $runFileId = $file['workflow_run_file_id'] ?? null;
            $fileId = $file['file_id'] ?? null;
            $parseId = $file['parse_id'] ?? null;
            $payload = $file['payload'] ?? null;
            $fieldSources = $file['field_sources'] ?? null;
            $fileBlocks = $file['blocks'] ?? null;
            if (!is_int($runFileId) || $runFileId < 1 || !is_int($fileId) || $fileId < 1
                || !is_int($parseId) || $parseId < 1 || !is_array($payload)
                || !is_array($fieldSources) || !array_is_list($fieldSources)
                || !is_array($fileBlocks) || !array_is_list($fileBlocks)
                || isset($runFileIds[$runFileId]) || isset($fileIds[$fileId])) {
                self::invalid();
            }
            $runFileIds[$runFileId] = true;
            $fileIds[$fileId] = true;
            $available = [];
            foreach ($fileBlocks as $block) {
                $id = $block['id'] ?? null;
                $content = $block['content'] ?? null;
                if (!is_int($id) || $id < 1 || isset($sourceFileIds[$id])
                    || ($block['file_id'] ?? null) !== $fileId
                    || ($block['parse_id'] ?? null) !== $parseId
                    || !is_string($content)
                    || !is_string($block['content_sha256'] ?? null)
                    || !hash_equals($block['content_sha256'], hash('sha256', $content))) {
                    self::invalid();
                }
                $sourceFileIds[$id] = $fileId;
                $available[$id] = $block;
            }
            $cited = [];
            $this->collectCitations($payload, $cited);
            $expectedSources = $this->expectedSources($payload);
            $fieldMap = [];
            foreach ($fieldSources as $source) {
                $path = $source['field_path'] ?? null;
                $id = $source['source_block_id'] ?? null;
                if (!is_string($path) || !str_starts_with($path, '/')
                    || !is_int($id) || !isset($available[$id])
                    || !isset($expectedSources[$path][$id]) || isset($fieldMap[$path][$id])) {
                    self::invalid();
                }
                $fieldMap[$path][$id] = true;
                $cited[$id] = true;
            }
            if ($fieldMap != $expectedSources) {
                self::invalid();
            }
            foreach (array_keys($cited) as $id) {
                if (!isset($available[$id])) {
                    self::invalid();
                }
                $blocks[$id] = [
                    'file_id' => $fileId,
                    'parse_id' => $parseId,
                    'source_block_id' => $id,
                    'content' => $available[$id]['content'],
                    'content_sha256' => $available[$id]['content_sha256'],
                    'locator_type' => $available[$id]['locator_type'] ?? null,
                    'locator' => $available[$id]['locator'] ?? [],
                ];
            }
            $this->addCandidates($candidates, $runFileId, $fileId, $payload, $fieldMap);
            $results[] = [
                'workflow_run_file_id' => $runFileId,
                'file_id' => $fileId,
                'parse_id' => $parseId,
                'payload' => $payload,
                'field_sources' => $fieldSources,
            ];
        }
        if ($chunked) return $this->boundedReview($profile, $results, $candidates, $blocks, $output);
        // Do not advertise blocks absent from the prompt to the output validator.
        $promptSourceFileIds = array_intersect_key($sourceFileIds, $blocks);
        foreach ($blocks as &$block) unset($block['content_sha256'], $block['locator_type'], $block['locator']);
        unset($block);
        ksort($blocks, SORT_NUMERIC);
        ksort($promptSourceFileIds, SORT_NUMERIC);
        $system = 'Return JSON only: {"items_to_verify":[]}. For each cross-file item use '
            . '{reason:"conflict|ambiguous",field,summary,source_block_ids,affected_candidates}. '
            . 'Each affected candidate must name workflow_run_file_id and field_path from the supplied candidates. '
            . 'Cite the actual source blocks of at least two candidates from different files. '
            . 'If different values may concern different matters, use ambiguous; never infer identity from type alone. '
            . 'Use only the supplied results and blocks. Write human-readable values in ' . $language . '.';
        if ($output?->strategy === \app\Application\Skill\SkillOutputStrategy::EXTRACT) $system .= $output->instruction();
        try {
            $user = json_encode(
                ['sealed_results' => $results, 'review_candidates' => $candidates, 'source_blocks' => array_values($blocks)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            self::invalid();
        }
        if (mb_strlen($system, 'UTF-8') + mb_strlen($user, 'UTF-8') > $maximum) {
            throw new ApiProblem('SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE', 'The complete cross-file review input exceeds the profile prompt limit');
        }
        return [
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'source_file_ids' => $promptSourceFileIds,
            'candidates' => $candidates,
            'input_sha256' => hash('sha256', $system . "\n" . $user),
        ];
    }

    /** Review every cross-file candidate pair without resending the complete source text. */
    private function boundedReview(array $profile, array $results, array $candidates, array $blocks,
        ?MaterialReviewOutputContract $output): array
    {
        $facts = [];
        foreach ($results as $result) {
            foreach ($candidates as $candidate) {
                if ($candidate['workflow_run_file_id'] !== $result['workflow_run_file_id']) continue;
                $parts = explode('/', substr($candidate['field_path'], 1));
                $value = $result['payload'];
                array_pop($parts);
                foreach ($parts as $part) {
                    $segment = str_replace(['~1', '~0'], ['/', '~'], $part);
                    $value = is_object($value) ? ($value->{$segment} ?? null) : ($value[$segment] ?? null);
                }
                if (!is_array($value)) self::invalid();
                $facts[$candidate['workflow_run_file_id'] . ':' . $candidate['field_path']] = $candidate + ['sealed_value' => $value];
            }
        }
        $system = 'Return JSON only: {"items_to_verify":[]}. Review every supplied cross_file_pair. '
            . 'Use only the complete sealed candidate values and their source metadata; the file analyses have already sealed the source evidence. '
            . 'Each item is {reason:"conflict|ambiguous",field,summary,source_block_ids,affected_candidates}. '
            . 'Each affected candidate names its unchanged workflow_run_file_id and absolute field_path. '
            . 'Cite at least one source from each affected candidate and at least two different files. '
            . 'Use ambiguous when identity is uncertain; never infer identity from type alone. '
            . 'Write human-readable values in ' . $profile['output_language'] . '.';
        if ($output?->strategy === \app\Application\Skill\SkillOutputStrategy::EXTRACT) $system .= $output->instruction();
        $pairs = [];
        $batches = [];
        $maximumPairs = max(1, intdiv($profile['max_output_characters'], 4096));
        $count = count($candidates);
        for ($left = 0; $left < $count; ++$left) {
            for ($right = $left + 1; $right < $count; ++$right) {
                if ($candidates[$left]['file_id'] === $candidates[$right]['file_id']) continue;
                $pair = [$candidates[$left], $candidates[$right]];
                $next = $this->batch($system, [...$pairs, $pair], $facts, $blocks);
                if ($pairs !== [] && (count($pairs) >= $maximumPairs || !$this->fits($next, $profile['max_prompt_characters']))) {
                    $batches[] = $this->batch($system, $pairs, $facts, $blocks);
                    $pairs = [];
                    $next = $this->batch($system, [$pair], $facts, $blocks);
                }
                if (!$this->fits($next, $profile['max_prompt_characters'])) {
                    throw new ApiProblem('SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE', 'A complete sealed candidate pair exceeds the frozen profile prompt limit');
                }
                $pairs[] = $pair;
            }
        }
        if ($pairs !== [] || $batches === []) $batches[] = $this->batch($system, $pairs, $facts, $blocks);
        if (count($batches) > 999999) self::invalid();
        $sources = [];
        foreach ($batches as $index => &$batch) {
            $batch['id'] = sprintf('batch_%06d', $index + 1);
            $sources += $batch['source_file_ids'];
            if (!$this->fits($batch, $profile['max_prompt_characters'])) self::invalid();
        }
        unset($batch);
        ksort($sources, SORT_NUMERIC);
        $plan = array_map(static fn (array $batch): array => [
            'id' => $batch['id'], 'input_sha256' => $batch['input_sha256'],
            'source_file_ids' => $batch['source_file_ids'], 'candidates' => $batch['candidates'],
            'pairs' => $batch['pairs'], 'messages' => $batch['messages'],
        ], $batches);
        return ['messages' => [], 'source_file_ids' => $sources, 'candidates' => $candidates,
            'review_batches' => $batches, 'review_plan' => $plan,
            'input_sha256' => StructuredWorkflowPolicy::fingerprint($plan)];
    }

    private function batch(string $system, array $pairs, array $facts, array $blocks): array
    {
        $candidates = $values = $sources = $metadata = $references = [];
        foreach ($pairs as $pair) {
            $refs = [];
            foreach ($pair as $candidate) {
                $key = $candidate['workflow_run_file_id'] . ':' . $candidate['field_path'];
                $candidates[$key] = $candidate;
                $values[$key] = $facts[$key];
                $refs[] = ['workflow_run_file_id' => $candidate['workflow_run_file_id'], 'field_path' => $candidate['field_path']];
                foreach ($candidate['source_block_ids'] as $id) {
                    if (!isset($blocks[$id])) self::invalid();
                    $sources[$id] = $blocks[$id]['file_id'];
                    $metadata[$id] = array_diff_key($blocks[$id], ['content' => true]);
                }
            }
            $references[] = $refs;
        }
        ksort($sources, SORT_NUMERIC);
        ksort($metadata, SORT_NUMERIC);
        $user = json_encode(['sealed_candidates' => array_values($values), 'cross_file_pairs' => $references,
            'source_metadata' => array_values($metadata)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'source_file_ids' => $sources, 'candidates' => array_values($candidates), 'pairs' => $references,
            'input_sha256' => hash('sha256', $system . "\n" . $user)];
    }

    private function fits(array $batch, int $maximum): bool
    {
        return mb_strlen($batch['messages'][0]['content'], 'UTF-8') + mb_strlen($batch['messages'][1]['content'], 'UTF-8') <= $maximum;
    }

    /** @param array<int,true> $ids */
    private function collectCitations(mixed $value, array &$ids): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if ($key === 'source_block_ids') {
                if (!is_array($child) || !array_is_list($child)) {
                    self::invalid();
                }
                foreach ($child as $id) {
                    if (!is_int($id) || $id < 1) {
                        self::invalid();
                    }
                    $ids[$id] = true;
                }
                continue;
            }
            $this->collectCitations($child, $ids);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,array<int,true>> */
    private function expectedSources(array $payload): array
    {
        $expected = [];
        foreach (['documents', 'entities', 'events', 'conflicts', 'items_to_verify'] as $kind) {
            $items = $payload[$kind] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                self::invalid();
            }
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    self::invalid();
                }
                if ($kind === 'documents') {
                    foreach (['classification', 'title'] as $field) {
                        $this->sourceMapping($expected, "/documents/{$index}/{$field}/value", $item[$field] ?? null, 'value', true);
                    }
                } elseif ($kind === 'entities') {
                    $this->sourceMapping($expected, "/entities/{$index}/value", $item, 'value');
                } elseif ($kind === 'events') {
                    $this->sourceMapping($expected, "/events/{$index}/summary", $item, 'summary');
                    if (($item['occurred_on'] ?? null) !== null) {
                        if (!is_string($item['occurred_on'])) {
                            self::invalid();
                        }
                        $this->sourceMapping($expected, "/events/{$index}/occurred_on", $item, 'occurred_on');
                    }
                } elseif ($kind === 'conflicts') {
                    $this->sourceMapping($expected, "/conflicts/{$index}/summary", $item, 'summary');
                } elseif (($item['summary'] ?? null) !== null) {
                    $this->sourceMapping($expected, "/items_to_verify/{$index}/summary", $item, 'summary');
                }
            }
        }
        $extensions = $payload['extensions'] ?? null;
        if (!is_array($extensions) && !$extensions instanceof \stdClass) {
            self::invalid();
        }
        foreach ($extensions as $code => $items) {
            if (!is_string($code) || $code === '' || !is_array($items) || !array_is_list($items)) {
                self::invalid();
            }
            $segment = str_replace(['~', '/'], ['~0', '~1'], $code);
            foreach ($items as $index => $item) {
                $this->sourceMapping($expected, "/extensions/{$segment}/{$index}/value", $item, 'value');
            }
        }
        return $expected;
    }

    /** @param array<string,array<int,true>> $expected */
    private function sourceMapping(array &$expected, string $path, mixed $item, string $valueKey, bool $allowMissing = false): void
    {
        if ($allowMissing && is_array($item) && array_key_exists($valueKey, $item)
            && $item[$valueKey] === null && ($item['source_block_ids'] ?? null) === []
            && ($item['evidence_state'] ?? null) === 'missing_source') {
            return;
        }
        if (!is_array($item) || !is_string($item[$valueKey] ?? null) || trim($item[$valueKey]) === ''
            || !is_array($item['source_block_ids'] ?? null)
            || !array_is_list($item['source_block_ids']) || $item['source_block_ids'] === []) {
            self::invalid();
        }
        foreach ($item['source_block_ids'] as $id) {
            if (!is_int($id) || $id < 1 || isset($expected[$path][$id])) {
                self::invalid();
            }
            $expected[$path][$id] = true;
        }
    }

    /**
     * @param list<array{workflow_run_file_id:int,file_id:int,field_path:string,source_block_ids:list<int>}> $candidates
     * @param array<string,mixed> $payload
     * @param array<string,array<int,true>> $fieldMap
     */
    private function addCandidates(array &$candidates, int $runFileId, int $fileId, array $payload, array $fieldMap): void
    {
        foreach (['entities' => 'value', 'events' => 'summary'] as $kind => $field) {
            $items = $payload[$kind] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                self::invalid();
            }
            foreach ($items as $index => $item) {
                $this->appendCandidate($candidates, $runFileId, $fileId, "/{$kind}/{$index}/{$field}", $item, $fieldMap);
            }
        }
        $extensions = $payload['extensions'] ?? null;
        if (!is_array($extensions) && !$extensions instanceof \stdClass) {
            self::invalid();
        }
        foreach ($extensions as $code => $items) {
            if (!is_string($code) || $code === '' || !is_array($items) || !array_is_list($items)) {
                self::invalid();
            }
            $segment = str_replace(['~', '/'], ['~0', '~1'], $code);
            foreach ($items as $index => $item) {
                $this->appendCandidate($candidates, $runFileId, $fileId, "/extensions/{$segment}/{$index}/value", $item, $fieldMap);
            }
        }
    }

    /**
     * @param list<array{workflow_run_file_id:int,file_id:int,field_path:string,source_block_ids:list<int>}> $candidates
     * @param array<string,array<int,true>> $fieldMap
     */
    private function appendCandidate(array &$candidates, int $runFileId, int $fileId, string $path, mixed $item, array $fieldMap): void
    {
        $valueKey = str_ends_with($path, '/summary') ? 'summary' : 'value';
        if (!is_array($item) || !is_string($item[$valueKey] ?? null) || trim($item[$valueKey]) === ''
            || !is_array($item['source_block_ids'] ?? null)
            || !array_is_list($item['source_block_ids']) || $item['source_block_ids'] === []) {
            self::invalid();
        }
        $ids = $item['source_block_ids'];
        $mapped = $fieldMap[$path] ?? [];
        if (count($mapped) !== count(array_unique($ids))
            || array_diff(array_keys($mapped), $ids) !== []) {
            self::invalid();
        }
        $candidates[] = [
            'workflow_run_file_id' => $runFileId,
            'file_id' => $fileId,
            'field_path' => $path,
            'source_block_ids' => $ids,
        ];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The cross-file review input is not a complete sealed source set');
    }
}
