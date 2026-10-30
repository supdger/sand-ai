<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;

/** Deterministic, full-transcript leaf plan. No transcript text is dropped. */
final class MeetingSummaryPlan
{
    public const VERSION = 'meeting-summary-v2';
    public const CAPACITY_VERSION = 'meeting-summary-v3';
    public const MAX_INPUT_CHARS = 12000;
    private const MAX_PART_CHARS = 8000;

    /** @param array<string,mixed> $transcript @return array{leaves:list<array<string,mixed>>,sources:array<string,array<string,mixed>>,total_segments:int,eligible_segments:int} */
    public function build(array $transcript, bool $includeLeaves = true): array
    {
        if (($transcript['schema'] ?? null) !== ['code' => 'sand_ai.audio_transcription', 'version' => '1.0.0']
            || !is_array($transcript['segments'] ?? null) || !array_is_list($transcript['segments'])
            || $transcript['segments'] === [] || count($transcript['segments']) > 10000) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting transcript is unavailable');
        }
        $sources = [];
        $parts = [];
        $sourceFile = null;
        foreach ($transcript['segments'] as $index => $segment) {
            if (!is_array($segment) || !is_int($segment['start_ms'] ?? null)
                || !is_int($segment['end_ms'] ?? null) || $segment['start_ms'] < 0
                || $segment['end_ms'] <= $segment['start_ms'] || !is_string($segment['text'] ?? null)
                || preg_match('//u', $segment['text']) !== 1 || mb_strlen($segment['text'], 'UTF-8') > 10000
                || !is_array($segment['warnings'] ?? null) || !is_array($segment['source'] ?? null)
                || !is_string($segment['source']['file_ref'] ?? null)
                || !is_string($segment['source']['content_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $segment['source']['content_sha256']) !== 1
                || !is_array($segment['source']['locator'] ?? null)) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting transcript segment is invalid');
            }
            AudioTimeRangeLocator::normalize($segment['source']['locator'], $segment['start_ms'], $segment['end_ms']);
            $currentFile = [$segment['source']['file_ref'], $segment['source']['content_sha256']];
            if ($sourceFile !== null && $currentFile !== $sourceFile) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting transcript source changed');
            }
            $sourceFile = $currentFile;
            if (trim($segment['text']) === '') {
                if (!in_array('no_recognized_speech', $segment['warnings'], true)) {
                    throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The unrecognized segment has no review marker');
                }
                continue;
            }
            $id = 'S' . ($index + 1);
            $sources[$id] = $segment['source'];
            if (!$includeLeaves) continue;
            $speaker = is_string($segment['speaker'] ?? null) && trim($segment['speaker']) !== ''
                ? trim($segment['speaker']) : 'unknown';
            $text = trim($segment['text']);
            $timingNote = ($segment['source']['locator']['granularity'] ?? null) === 'input_range_only'
                ? ' whole-input-range; speech-timing-unavailable' : '';
            $length = mb_strlen($text, 'UTF-8');
            $partCount = (int) ceil($length / self::MAX_PART_CHARS);
            for ($partNo = 0; $partNo < $partCount; ++$partNo) {
                $slice = mb_substr($text, $partNo * self::MAX_PART_CHARS, self::MAX_PART_CHARS, 'UTF-8');
                $parts[] = [
                    'segment_index' => $index, 'segment_id' => $id,
                    'text' => sprintf('[%s %d-%d ms %s%s part %d/%d] %s',
                        $id, $segment['start_ms'], $segment['end_ms'], $speaker, $timingNote, $partNo + 1, $partCount, $slice),
                ];
            }
        }
        if (!$includeLeaves && $sources !== []) {
            return ['leaves' => [], 'sources' => $sources, 'total_segments' => count($transcript['segments']),
                'eligible_segments' => count($sources)];
        }
        if ($parts === []) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting transcript has no recognized speech to summarize');
        }
        $leaves = [];
        $batch = [];
        $chars = 0;
        foreach ($parts as $part) {
            $partChars = mb_strlen($part['text'], 'UTF-8') + 1;
            if ($partChars > self::MAX_INPUT_CHARS) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'A transcript part exceeds the model input bound');
            }
            if ($batch !== [] && $chars + $partChars > self::MAX_INPUT_CHARS) {
                $leaves[] = $this->leaf(count($leaves), $batch);
                $batch = [];
                $chars = 0;
            }
            $batch[] = $part;
            $chars += $partChars;
        }
        if ($batch !== []) {
            $leaves[] = $this->leaf(count($leaves), $batch);
        }
        return [
            'leaves' => $leaves, 'sources' => $sources,
            'total_segments' => count($transcript['segments']), 'eligible_segments' => count($sources),
        ];
    }

    /** @param list<array<string,mixed>> $parts @return array<string,mixed> */
    private function leaf(int $sequence, array $parts): array
    {
        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        $ids = [];
        foreach ($parts as $part) {
            $ids[$part['segment_id']] = true;
        }
        return [
            'node_code' => sprintf('chunk_%05d', $sequence + 1), 'node_kind' => 'chunk',
            'level_no' => 0, 'sequence_no' => $sequence + 1,
            'segment_start_index' => $first['segment_index'], 'segment_end_index' => $last['segment_index'],
            'segment_ids' => array_keys($ids),
            'messages' => [
                ['role' => 'system', 'content' => MeetingSummaryEvidence::systemPrompt()],
                ['role' => 'user', 'content' => "转写片段：\n" . implode("\n", array_column($parts, 'text'))],
            ],
        ];
    }

    /** Every byte of eligible original text is represented by ordered Unicode ranges. */
    public function withCapacity(array $transcript, MeetingSummaryCapacity $capacity): array
    {
        $baseline = $this->build($transcript, false);
        $local = MeetingLocalPlanning::enabled($capacity->snapshot);
        $localParts = []; $localLines = [];
        $ranges = [];
        $batch = [];
        $chars = 0;
        foreach ($transcript['segments'] as $index => $segment) {
            if (trim($segment['text']) === '') continue;
            if (preg_match('//u', $segment['text']) !== 1) $this->coverageInvalid();
            $length = mb_strlen($segment['text'], 'UTF-8');
            for ($offset = 0; $offset < $length; $offset += self::MAX_PART_CHARS) {
                $part = ['segment_index' => $index, 'start_offset' => $offset,
                    'end_offset' => min($length, $offset + self::MAX_PART_CHARS)];
                $partChars = mb_strlen($this->renderPart($transcript, $part), 'UTF-8') + 1;
                if ($local) {
                    $localParts[] = $part; $localLines[] = $this->renderPart($transcript, $part);
                    continue;
                }
                if ($batch !== [] && $chars + $partChars > self::MAX_INPUT_CHARS) {
                    $ranges[] = $batch;
                    $batch = [];
                    $chars = 0;
                }
                $batch[] = $part;
                $chars += $partChars;
            }
        }
        if ($local) {
            // The whole-transcript compatibility path is bounded independently.
            // Registered long tasks use the persisted 32-source-window planner.
            if (count($localParts) > 512) $this->coverageInvalid();
            $ranges = MeetingLocalPlanning::partition($localParts, $localLines);
        } elseif ($batch !== []) $ranges[] = $batch;
        $leaves = [];
        foreach ($ranges as $parts) $this->fit($transcript, $parts, $capacity, $leaves);
        $manifest = [
            'version' => self::CAPACITY_VERSION,
            'normalization' => 'none_unicode_codepoint@1',
            'transcript_sha256' => hash('sha256', json_encode($transcript,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'capacity_sha256' => $capacity->snapshot['snapshot_sha256'],
            'total_segments' => $baseline['total_segments'], 'eligible_segments' => $baseline['eligible_segments'],
            'segments' => $this->segmentProofs($transcript), 'leaves' => [],
            'tokenizer_call_count' => $capacity->callCount(), 'tokenizer_pricing_state' => $local ? 'not_applicable' : 'unpriced',
            ...($local ? ['planning' => MeetingLocalPlanning::metadata()] : []),
        ];
        foreach ($leaves as $leaf) {
            $manifest['leaves'][] = [
                'node_code' => $leaf['node_code'], 'sequence_no' => $leaf['sequence_no'],
                'parts' => $leaf['parts'], 'messages_sha256' => RequestPayloadFingerprint::fingerprint($leaf['messages'], []),
                ...($local ? ['planning_evidence' => $leaf['planning_evidence']] : ['token_count_receipt' => $leaf['token_count_receipt']]),
            ];
        }
        $manifest['manifest_sha256'] = RequestPayloadFingerprint::fingerprint($manifest, []);
        return $this->fromFrozen($transcript, $manifest, $capacity->snapshot);
    }

    /** Rebuild solely from frozen offsets and verified source; no tokenizer/Provider request. */
    public function fromFrozen(array $transcript, array $manifest, array $capacitySnapshot): array
    {
        MeetingSummaryCapacity::assert($capacitySnapshot);
        $capacity = new MeetingSummaryCapacity($capacitySnapshot);
        $local = MeetingLocalPlanning::enabled($capacitySnapshot);
        $baseline = $this->build($transcript, false);
        $expectedKeys = ['version', 'normalization', 'transcript_sha256', 'capacity_sha256',
            'total_segments', 'eligible_segments', 'segments', 'leaves', 'tokenizer_call_count',
            'tokenizer_pricing_state', 'manifest_sha256'];
        if ($local) $expectedKeys[] = 'planning';
        if (array_diff(array_keys($manifest), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($manifest)) !== []
            || ($manifest['version'] ?? null) !== self::CAPACITY_VERSION
            || ($manifest['normalization'] ?? null) !== 'none_unicode_codepoint@1'
            || ($manifest['capacity_sha256'] ?? null) !== $capacitySnapshot['snapshot_sha256']
            || ($manifest['transcript_sha256'] ?? null) !== hash('sha256', json_encode($transcript,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            || !is_array($manifest['segments'] ?? null)
            || !RequestPayloadFingerprint::same($manifest['segments'], $this->segmentProofs($transcript), [])
            || ($manifest['total_segments'] ?? null) !== $baseline['total_segments']
            || ($manifest['eligible_segments'] ?? null) !== $baseline['eligible_segments']
            || !is_array($manifest['leaves'] ?? null) || !array_is_list($manifest['leaves']) || $manifest['leaves'] === []
            || !is_int($manifest['tokenizer_call_count'] ?? null)
            || ($local ? ($manifest['tokenizer_call_count'] !== 0
                || !RequestPayloadFingerprint::same($manifest['planning'] ?? [], MeetingLocalPlanning::metadata(), [])) : $manifest['tokenizer_call_count'] < 1)
            || ($manifest['tokenizer_pricing_state'] ?? null) !== ($local ? 'not_applicable' : 'unpriced')
            || !is_string($manifest['manifest_sha256'] ?? null)) $this->coverageInvalid();
        $hashInput = $manifest;
        unset($hashInput['manifest_sha256']);
        if (!hash_equals($manifest['manifest_sha256'], RequestPayloadFingerprint::fingerprint($hashInput, []))) $this->coverageInvalid();
        $eligibleIndices = [];
        foreach ($transcript['segments'] as $index => $segment) {
            if (trim($segment['text']) !== '') $eligibleIndices[] = $index;
        }
        $cursor = 0;
        $nextOffset = 0;
        $leaves = [];
        foreach ($manifest['leaves'] as $sequence => $spec) {
            if (!is_array($spec) || array_diff(array_keys($spec),
                ['node_code', 'sequence_no', 'parts', 'messages_sha256', $local ? 'planning_evidence' : 'token_count_receipt']) !== []
                || !is_array($spec['parts'] ?? null) || !array_is_list($spec['parts']) || $spec['parts'] === []
                || ($spec['node_code'] ?? null) !== sprintf('chunk_%05d', $sequence + 1)
                || ($spec['sequence_no'] ?? null) !== $sequence + 1) $this->coverageInvalid();
            foreach ($spec['parts'] as $part) {
                if (!is_array($part) || count($part) !== 3
                    || array_diff(array_keys($part), ['segment_index', 'start_offset', 'end_offset']) !== []
                    || ($part['segment_index'] ?? null) !== ($eligibleIndices[$cursor] ?? null)
                    || ($part['start_offset'] ?? null) !== $nextOffset || !is_int($part['end_offset'] ?? null)
                    || $part['end_offset'] <= $nextOffset) $this->coverageInvalid();
                $length = mb_strlen($transcript['segments'][$part['segment_index']]['text'], 'UTF-8');
                if ($part['end_offset'] > $length) $this->coverageInvalid();
                $nextOffset = $part['end_offset'];
                if ($nextOffset === $length) {
                    ++$cursor;
                    $nextOffset = 0;
                }
            }
            $leaf = $this->capacityLeaf($transcript, $spec['parts'], $sequence);
            if (($spec['messages_sha256'] ?? null) !== RequestPayloadFingerprint::fingerprint($leaf['messages'], [])
                || !is_array($spec[$local ? 'planning_evidence' : 'token_count_receipt'] ?? null)) $this->coverageInvalid();
            if ($local) {
                MeetingLocalPlanning::verify($spec['planning_evidence'], $leaf['messages']);
                $leaf['planning_evidence'] = $spec['planning_evidence'];
            } else {
                $capacity->assertFits($spec['token_count_receipt'], $leaf['messages']);
                $leaf['token_count_receipt'] = $spec['token_count_receipt'];
            }
            $leaves[] = $leaf;
        }
        if ($cursor !== count($eligibleIndices) || $nextOffset !== 0) $this->coverageInvalid();
        return [
            'leaves' => $leaves, 'sources' => $baseline['sources'],
            'total_segments' => $baseline['total_segments'], 'eligible_segments' => $baseline['eligible_segments'],
            'planner_version' => self::CAPACITY_VERSION, 'manifest' => $manifest,
        ];
    }

    /** Each failed capacity fit divides a finite range; one Unicode codepoint never disappears. */
    private function fit(array $transcript, array $parts, MeetingSummaryCapacity $capacity, array &$leaves): void
    {
        $leaf = $this->capacityLeaf($transcript, $parts, count($leaves));
        if (MeetingLocalPlanning::enabled($capacity->snapshot)) {
            $leaf['planning_evidence'] = MeetingLocalPlanning::evidence($leaf['messages']);
            $leaves[] = $leaf;
            return;
        }
        $receipt = $capacity->measure($leaf['messages']);
        if ($receipt['prompt_tokens'] <= $capacity->snapshot['input_token_limit']) {
            $capacity->assertFits($receipt, $leaf['messages']);
            $leaf['token_count_receipt'] = $receipt;
            $leaves[] = $leaf;
            return;
        }
        if (count($parts) > 1) {
            $middle = intdiv(count($parts), 2);
            $this->fit($transcript, array_slice($parts, 0, $middle), $capacity, $leaves);
            $this->fit($transcript, array_slice($parts, $middle), $capacity, $leaves);
            return;
        }
        $part = $parts[0];
        $length = $part['end_offset'] - $part['start_offset'];
        if ($length < 2) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_INPUT_CAPACITY_EXCEEDED', '模型容量不足以容纳一个原文字符与来源提示，尚未发送摘要调用；请选用容量更大的已登记部署');
        }
        $middle = $part['start_offset'] + intdiv($length, 2);
        $this->fit($transcript, [[...$part, 'end_offset' => $middle]], $capacity, $leaves);
        $this->fit($transcript, [[...$part, 'start_offset' => $middle]], $capacity, $leaves);
    }

    private function capacityLeaf(array $transcript, array $parts, int $sequence): array
    {
        $ids = [];
        $lines = [];
        foreach ($parts as $part) {
            $ids['S' . ($part['segment_index'] + 1)] = true;
            $lines[] = $this->renderPart($transcript, $part);
        }
        return [
            'node_code' => sprintf('chunk_%05d', $sequence + 1), 'node_kind' => 'chunk',
            'level_no' => 0, 'sequence_no' => $sequence + 1,
            'segment_start_index' => $parts[0]['segment_index'],
            'segment_end_index' => $parts[count($parts) - 1]['segment_index'],
            'segment_ids' => array_keys($ids), 'parts' => $parts,
            'messages' => [
                ['role' => 'system', 'content' => MeetingSummaryEvidence::systemPrompt()],
                ['role' => 'user', 'content' => "转写片段（字符偏移为原文 Unicode 码点范围，右端不含）：\n" . implode("\n", $lines)],
            ],
        ];
    }

    private function renderPart(array $transcript, array $part): string
    {
        $segment = $transcript['segments'][$part['segment_index']];
        $speaker = is_string($segment['speaker'] ?? null) ? $segment['speaker'] : 'unknown';
        return sprintf('[S%d %d-%d ms %s%s chars %d-%d] %s', $part['segment_index'] + 1,
            $segment['start_ms'], $segment['end_ms'], $speaker,
            ($segment['source']['locator']['granularity'] ?? null) === 'input_range_only' ? ' whole-input-range; speech-timing-unavailable' : '',
            $part['start_offset'], $part['end_offset'],
            mb_substr($segment['text'], $part['start_offset'], $part['end_offset'] - $part['start_offset'], 'UTF-8'));
    }

    private function segmentProofs(array $transcript): array
    {
        $proofs = [];
        foreach ($transcript['segments'] as $index => $segment) {
            $proofs[] = [
                'segment_id' => 'S' . ($index + 1), 'text_sha256' => hash('sha256', $segment['text']),
                'unicode_characters' => mb_strlen($segment['text'], 'UTF-8'),
                'source_sha256' => RequestPayloadFingerprint::fingerprint([
                    'speaker' => $segment['speaker'] ?? null, 'start_ms' => $segment['start_ms'],
                    'end_ms' => $segment['end_ms'], 'source' => $segment['source'],
                ], []),
                'exclusion' => trim($segment['text']) === '' ? 'no_usable_text' : null,
            ];
        }
        return $proofs;
    }

    private function coverageInvalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE', '会议冻结规划未完整覆盖原转写，请保留当前任务及费用记录并重新提交摘要');
    }
}
