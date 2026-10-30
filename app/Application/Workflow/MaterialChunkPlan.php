<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;

final class MaterialChunkPlan
{
    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $file
     * @return array<string,mixed>|null
     */
    public function build(array $profile, array $file): ?array
    {
        $blocks = $this->sourceBlocks($file);
        $factory = new MaterialAnalysisPromptFactory();
        if ($this->fits($factory, $profile, $file)) {
            return null;
        }

        $nodes = [];
        $pending = [];
        $characterCount = 0;
        foreach ($blocks as $block) {
            $content = $block['content'];
            $length = mb_strlen($content, 'UTF-8');
            $characterCount += $length;
            $emptyBlock = $block;
            $emptyBlock['content'] = '';
            $emptyFile = $file;
            $emptyFile['blocks'] = [$emptyBlock];
            $emptyPrompt = $factory->build($profile, [$emptyFile], true);
            $overhead = 0;
            foreach ($emptyPrompt->messages as $message) {
                $overhead += mb_strlen($message['content'], 'UTF-8');
            }
            $capacity = max(1, (int) $profile['max_prompt_characters'] - $overhead);

            foreach ($this->sourceBoundaries($content, $capacity) as $boundary) {
                $start = $boundary['start'];
                do {
                    $end = min($boundary['end'], $start + $capacity);
                    $range = ['source_block_id' => $block['id'], 'start' => $start, 'end' => $end];
                    $candidate = $this->compactRanges([...$pending, $range]);
                    $leaf = ['kind' => 'leaf', 'ranges' => $candidate];
                    if (!$this->fits($factory, $profile, $this->fileForLeaf($file, $leaf), true)) {
                        if ($pending === []) {
                            throw new ApiProblem(
                                'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE',
                                'A material source fragment cannot fit within the fixed profile prompt limit',
                            );
                        }
                        $nodes[] = $this->leaf(count($nodes) + 1, $pending);
                        $pending = [];
                        $candidate = [$range];
                        $leaf['ranges'] = $candidate;
                        if (!$this->fits($factory, $profile, $this->fileForLeaf($file, $leaf), true)) {
                            throw new ApiProblem(
                                'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE',
                                'A material source fragment cannot fit within the fixed profile prompt limit',
                            );
                        }
                    }
                    $pending = $candidate;
                    $start = $end;
                } while ($start < $boundary['end']);
            }
        }
        if ($pending !== []) {
            $nodes[] = $this->leaf(count($nodes) + 1, $pending);
        }
        $leafCount = count($nodes);
        if ($leafCount < 2 || $leafCount > 999999) {
            $this->invalid('The oversized material requires at least two bounded source leaves');
        }

        $level = $nodes;
        $mergeCount = 0;
        while (count($level) > 1) {
            $next = [];
            for ($index = 0, $count = count($level); $index < $count; $index += 2) {
                $left = $level[$index];
                if (!isset($level[$index + 1])) {
                    $next[] = $left;
                    continue;
                }
                $right = $level[$index + 1];
                $mergeCount++;
                $merge = [
                    'id' => sprintf('merge_%06d', $mergeCount),
                    'kind' => 'merge',
                    'children' => [$left['id'], $right['id']],
                    'ranges' => $this->compactRanges([...$left['ranges'], ...$right['ranges']]),
                ];
                $nodes[] = $merge;
                $next[] = $merge;
            }
            $level = $next;
        }
        $plan = [
            'version' => 'material_chunks@1',
            'source_block_count' => count($blocks),
            'source_character_count' => $characterCount,
            'leaf_count' => $leafCount,
            'merge_count' => $mergeCount,
            'nodes' => $nodes,
            'root_node' => $level[0]['id'],
        ];
        $plan['plan_sha256'] = $this->digest($plan);
        $this->assertCoverage($plan, $file);

        return $plan;
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    public function fileForLeaf(array $file, array $node): array
    {
        $blocks = $this->sourceBlocks($file);
        if (($node['kind'] ?? null) !== 'leaf') {
            $this->invalid('Only a material source leaf can supply original text');
        }
        $ranges = $this->ranges($node['ranges'] ?? null, $blocks);
        $sourceById = [];
        foreach ($blocks as $block) {
            $sourceById[$block['id']] = $block;
        }
        $fragments = [];
        foreach ($ranges as $range) {
            $block = $sourceById[$range['source_block_id']];
            $block['content'] = mb_substr(
                $block['content'],
                $range['start'],
                $range['end'] - $range['start'],
                'UTF-8',
            );
            // The hash and locator identify the complete frozen source block.
            $fragments[] = $block;
        }
        $file['blocks'] = $fragments;

        return $file;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $file
     */
    public function assertCoverage(array $plan, array $file): void
    {
        $blocks = $this->sourceBlocks($file);
        $this->keys($plan, [
            'version', 'plan_sha256', 'source_block_count', 'source_character_count',
            'leaf_count', 'merge_count', 'nodes', 'root_node',
        ]);
        if ($plan['version'] !== 'material_chunks@1'
            || !is_string($plan['plan_sha256'])
            || !hash_equals($this->digest($plan), $plan['plan_sha256'])
            || !is_array($plan['nodes']) || !array_is_list($plan['nodes']) || $plan['nodes'] === []
            || !is_string($plan['root_node'])) {
            $this->invalid('The material chunk plan identity or topology is invalid');
        }
        $expectedRanges = [];
        $characterCount = 0;
        foreach ($blocks as $block) {
            $length = mb_strlen($block['content'], 'UTF-8');
            $characterCount += $length;
            $expectedRanges[] = ['source_block_id' => $block['id'], 'start' => 0, 'end' => $length];
        }

        $byId = [];
        $references = [];
        $leafCount = 0;
        $mergeCount = 0;
        $sourceIndex = 0;
        $sourceOffset = 0;
        foreach ($plan['nodes'] as $node) {
            if (!is_array($node)) {
                $this->invalid('The material chunk plan contains an invalid node');
            }
            $this->keys($node, ['id', 'kind', 'children', 'ranges']);
            if (!is_string($node['id']) || isset($byId[$node['id']])
                || !is_array($node['children']) || !array_is_list($node['children'])) {
                $this->invalid('Material chunk node identifiers and children must be unique and ordered');
            }
            $ranges = $this->ranges($node['ranges'], $blocks);
            if ($node['kind'] === 'leaf') {
                $leafCount++;
                if ($node['id'] !== sprintf('leaf_%06d', $leafCount) || $node['children'] !== []) {
                    $this->invalid('The material source leaf sequence is invalid');
                }
                foreach ($ranges as $range) {
                    $expected = $expectedRanges[$sourceIndex] ?? null;
                    if ($expected === null || $range['source_block_id'] !== $expected['source_block_id']
                        || $range['start'] !== $sourceOffset) {
                        $this->invalid('Material source leaves must cover every original codepoint exactly once');
                    }
                    $sourceOffset = $range['end'];
                    if ($sourceOffset === $expected['end']) {
                        $sourceIndex++;
                        $sourceOffset = 0;
                    }
                }
            } elseif ($node['kind'] === 'merge') {
                $mergeCount++;
                if ($node['id'] !== sprintf('merge_%06d', $mergeCount) || count($node['children']) !== 2
                    || !is_string($node['children'][0]) || !is_string($node['children'][1])
                    || $node['children'][0] === $node['children'][1]
                    || !isset($byId[$node['children'][0]], $byId[$node['children'][1]])) {
                    $this->invalid('Every material merge must have two earlier, distinct children');
                }
                $left = $byId[$node['children'][0]];
                $right = $byId[$node['children'][1]];
                if ($ranges !== $this->compactRanges([...$left['ranges'], ...$right['ranges']])) {
                    $this->invalid('Material merge ranges must preserve the ordered complete child ranges');
                }
                foreach ($node['children'] as $childId) {
                    $references[$childId] = ($references[$childId] ?? 0) + 1;
                }
            } else {
                $this->invalid('The material chunk plan contains an unsupported node kind');
            }
            $node['ranges'] = $ranges;
            $byId[$node['id']] = $node;
        }
        $last = $plan['nodes'][count($plan['nodes']) - 1];
        if ($leafCount < 2 || $leafCount > 999999 || $mergeCount !== $leafCount - 1
            || $sourceIndex !== count($blocks) || $sourceOffset !== 0
            || $last['id'] !== $plan['root_node']
            || $byId[$last['id']]['ranges'] !== $expectedRanges
            || $plan['source_block_count'] !== count($blocks)
            || $plan['source_character_count'] !== $characterCount
            || $plan['leaf_count'] !== $leafCount || $plan['merge_count'] !== $mergeCount) {
            $this->invalid('The material chunk plan counts, root, or complete source coverage do not match');
        }
        foreach ($byId as $id => $node) {
            $expectedReferences = $id === $plan['root_node'] ? 0 : 1;
            if (($references[$id] ?? 0) !== $expectedReferences) {
                $this->invalid('Every material chunk must belong to one complete tree with a unique root');
            }
        }
    }

    /**
     * Neuron supplies line grouping; these offsets retain omitted delimiters
     * and bound only the frozen source ranges, without replacing the splitter.
     *
     * @return list<array{start:int,end:int}>
     */
    private function sourceBoundaries(string $content, int $capacity): array
    {
        $length = mb_strlen($content, 'UTF-8');
        $chunks = (new DelimiterTextSplitter($capacity, "\n", 0, 0))
            ->splitDocument(new Document($content));
        if ($chunks === [] || (count($chunks) === 1 && $chunks[0]->getContent() === $content)) {
            return [['start' => 0, 'end' => $length]];
        }
        $ranges = [];
        $cursor = 0;
        $previousEnd = 0;
        foreach ($chunks as $chunk) {
            foreach (explode("\n", $chunk->getContent()) as $line) {
                if ($line === '') {
                    $this->invalid('The official material splitter returned an unmappable source boundary');
                }
                $position = mb_strpos($content, $line, $cursor, 'UTF-8');
                if ($position === false
                    || str_replace("\n", '', mb_substr($content, $cursor, $position - $cursor, 'UTF-8')) !== '') {
                    $this->invalid('The official material splitter output cannot map to the frozen source');
                }
                $cursor = $position + mb_strlen($line, 'UTF-8');
            }
            $ranges[] = ['start' => $previousEnd, 'end' => $cursor];
            $previousEnd = $cursor;
        }
        if (str_replace("\n", '', mb_substr($content, $cursor, null, 'UTF-8')) !== '') {
            $this->invalid('The official material splitter left unmapped source text');
        }
        $ranges[count($ranges) - 1]['end'] = $length;

        return $ranges;
    }

    /** @return list<array<string,mixed>> */
    private function sourceBlocks(array $file): array
    {
        $blocks = $file['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
            $this->invalid('The material chunk plan requires frozen source blocks');
        }
        $seen = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_int($block['id'] ?? null) || $block['id'] < 1
                || isset($seen[$block['id']]) || !is_string($block['content'] ?? null)
                || !mb_check_encoding($block['content'], 'UTF-8')) {
                $this->invalid('The material chunk plan requires unique original blocks with valid Unicode content');
            }
            $seen[$block['id']] = true;
        }

        return $blocks;
    }

    /** @return list<array{source_block_id:int,start:int,end:int}> */
    private function ranges(mixed $ranges, array $blocks): array
    {
        if (!is_array($ranges) || !array_is_list($ranges) || $ranges === []) {
            $this->invalid('Every material chunk must contain ordered original source ranges');
        }
        $positions = [];
        $lengths = [];
        foreach ($blocks as $index => $block) {
            $positions[$block['id']] = $index;
            $lengths[$block['id']] = mb_strlen($block['content'], 'UTF-8');
        }
        $previousPosition = -1;
        $previousEnd = 0;
        $normalized = [];
        foreach ($ranges as $range) {
            if (!is_array($range)) {
                $this->invalid('The material source range is invalid');
            }
            $this->keys($range, ['source_block_id', 'start', 'end']);
            if (!is_int($range['source_block_id']) || !isset($positions[$range['source_block_id']])
                || !is_int($range['start']) || !is_int($range['end'])
                || $range['start'] < 0 || $range['end'] < $range['start']
                || $range['end'] > $lengths[$range['source_block_id']]
                || ($range['end'] === $range['start'] && $lengths[$range['source_block_id']] !== 0)
                || $positions[$range['source_block_id']] < $previousPosition
                || ($positions[$range['source_block_id']] === $previousPosition
                    && ($range['start'] !== $previousEnd || $range['end'] === $range['start']))) {
                $this->invalid('Material source ranges must be valid, ordered, and contiguous within each block');
            }
            $previousPosition = $positions[$range['source_block_id']];
            $previousEnd = $range['end'];
            $normalized[] = [
                'source_block_id' => $range['source_block_id'],
                'start' => $range['start'],
                'end' => $range['end'],
            ];
        }

        return $this->compactRanges($normalized);
    }

    /** @return list<array{source_block_id:int,start:int,end:int}> */
    private function compactRanges(array $ranges): array
    {
        $result = [];
        foreach ($ranges as $range) {
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last]['source_block_id'] === $range['source_block_id']
                && $result[$last]['end'] === $range['start']) {
                $result[$last]['end'] = $range['end'];
            } else {
                $result[] = $range;
            }
        }

        return $result;
    }

    private function fits(
        MaterialAnalysisPromptFactory $factory,
        array $profile,
        array $file,
        bool $preserveSourceContent = false,
    ): bool {
        try {
            $factory->build($profile, [$file], $preserveSourceContent);
            return true;
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE') {
                throw $problem;
            }
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function leaf(int $sequence, array $ranges): array
    {
        return ['id' => sprintf('leaf_%06d', $sequence), 'kind' => 'leaf', 'children' => [], 'ranges' => $ranges];
    }

    private function digest(array $plan): string
    {
        unset($plan['plan_sha256']);

        return hash('sha256', json_encode(
            $this->canonical($plan),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->invalid('The material chunk plan fields do not match the frozen schema');
        }
    }

    private function invalid(string $message): never
    {
        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', $message);
    }
}
