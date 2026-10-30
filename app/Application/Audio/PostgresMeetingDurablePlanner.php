<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Ordered Unicode ranges, durable binary fitting, and no complete transcript/plan hydration. */
final class PostgresMeetingDurablePlanner
{
    public const VERSION = 'meeting_prepared_plan@1';
    private const PART_CHARS = 8000;
    private const WINDOW = 32;

    /** One bounded source window OR one official token count per tick. */
    public function prepareTick(TaskRun $task, TaskExecutionLease $lease): array
    {
        $store = new PostgresMeetingPlanStore();
        $head = $store->loadOrEnroll($task, $lease);
        $state = $head['state'];
        if ($head['phase'] === 'complete') return $this->status($head);
        $input = self::input($task);
        $capacity = $input['_meeting_capacity'];
        if ($state['pending'] !== []) {
            // Reconstruct only this candidate from current private source. The saved
            // state precedes HTTP; an interrupted send resolves through the same
            // immutable token ledger identity, never a replacement request key.
            $parts = $state['pending'][0];
            $sources = $this->sources($task, $lease, array_column($parts, 'segment_index'));
            $messages = $this->messages($sources, $parts, $input['_meeting_summary']['output_contract_revision'] ?? 1);
            $digest = RequestPayloadFingerprint::fingerprint($messages, []);
            $meter = new MeetingSummaryCapacity($capacity);
            $local = MeetingLocalPlanning::enabled($capacity);
            if (!$local) $meter->bindLedger(new PostgresMeetingTokenCountLedger($task, $lease, $capacity));
            // The trusted existing sender persists intent before sending and rejects
            // an old unknown/rejected intent. This code never sends on its own.
            $receipt = $local ? null : $meter->measure($messages);
            array_shift($state['pending']);
            $page = null;
            if ($local || $receipt['prompt_tokens'] <= $capacity['input_token_limit']) {
                if ($local) MeetingLocalPlanning::assertNoCounterRequests($task);
                else $meter->assertFits($receipt, $messages);
                ++$state['leaf_count'];
                $spec = ['node_code' => sprintf('chunk_%05d', $state['leaf_count']),
                    'sequence_no' => $state['leaf_count'], 'parts' => $parts,
                    'messages_sha256' => $digest,
                    ...($local ? ['planning_evidence' => MeetingLocalPlanning::evidence($messages)] : ['token_count_receipt' => $receipt])];
                $page = ['kind' => 'leaf', 'sequence' => $state['leaf_count'], 'entries' => [$spec]];
                $state['leaf_chain_sha256'] = self::chain($state['leaf_chain_sha256'], [$spec]);
            } else {
                [$left, $right] = $this->divide($parts);
                array_unshift($state['pending'], $right);
                array_unshift($state['pending'], $left);
            }
            // Bounded successor; caller must refresh task before enrollment/execute.
            $head = $store->advance($task, $lease, $head, $state, $page, false);
            return $this->status($head);
        }
        if ($state['source_cursor'] < $head['anchor']['total_segments']) {
            $start = $state['source_cursor'];
            $positions = range($start, min($head['anchor']['total_segments'] - 1, $start + self::WINDOW - 1));
            $rows = (new MeetingSummarySourceReader())->readPositions($task, $lease, $positions);
            $sources = [];
            if (count($rows) !== count($positions)) self::invalid();
            foreach ($rows as $offset => $row) {
                if (($row['segment_index'] ?? null) !== $positions[$offset]) self::invalid();
                $sources[$row['segment_index']] = $row;
            }
            $proofs = [];
            foreach ($positions as $index) {
                $source = $sources[$index];
                $proofs[] = $source;
                $segment = $source['private_segment'];
                if ($source['proof']['exclusion'] !== null) continue;
                ++$state['eligible_segments'];
                $length = $source['proof']['unicode_characters'];
                for ($offset = 0; $offset < $length; $offset += self::PART_CHARS) {
                    $part = ['segment_index' => $index, 'start_offset' => $offset,
                        'end_offset' => min($length, $offset + self::PART_CHARS)];
                    $chars = mb_strlen($this->render($segment, $part), 'UTF-8') + 1;
                    if (!MeetingLocalPlanning::enabled($capacity) && $state['batch'] !== [] && $state['batch_chars'] + $chars > MeetingSummaryPlan::MAX_INPUT_CHARS) {
                        $state['pending'][] = $state['batch'];
                        $state['batch'] = []; $state['batch_chars'] = 0;
                    }
                    $state['batch'][] = $part;
                    $state['batch_chars'] += $chars;
                }
            }
            if (MeetingLocalPlanning::enabled($capacity) && $state['batch'] !== []) {
                $oldPositions = array_values(array_unique(array_filter(array_column($state['batch'], 'segment_index'), static fn(int $position): bool => $position < $start)));
                $allSources = $sources + ($oldPositions === [] ? [] : $this->sources($task, $lease, $oldPositions));
                $lines = array_map(fn(array $part): string => $this->render($allSources[$part['segment_index']]['private_segment'], $part), $state['batch']);
                $groups = MeetingLocalPlanning::partition($state['batch'], $lines);
                $state['batch'] = array_pop($groups);
                foreach ($groups as $group) $state['pending'][] = $group;
                $state['batch_chars'] = array_sum(array_map(fn(array $part): int => mb_strlen($this->render($allSources[$part['segment_index']]['private_segment'], $part), 'UTF-8') + 1, $state['batch']));
            }
            $state['source_cursor'] += count($positions);
            ++$state['source_page_count'];
            $state['source_chain_sha256'] = self::chain($state['source_chain_sha256'], $proofs);
            if ($state['source_cursor'] === $head['anchor']['total_segments'] && $state['batch'] !== []) {
                $state['pending'][] = $state['batch'];
                $state['batch'] = []; $state['batch_chars'] = 0;
            }
            $head = $store->advance($task, $lease, $head, $state,
                ['kind' => 'source', 'sequence' => $state['source_page_count'], 'entries' => $proofs], false);
            return $this->status($head);
        }
        if ($state['batch'] !== [] || $state['pending'] !== [] || $state['eligible_segments'] < 1
            || ($head['anchor']['eligible_segments'] !== null && $state['eligible_segments'] !== $head['anchor']['eligible_segments']) || $state['leaf_count'] < 1) self::invalid();
        // Completeness is constructed inductively: every source position was checked
        // exactly once; every eligible Unicode range enters ordered pending work;
        // binary splits partition the exact parent; only fitting children leave it.
        // No final O(N) replay is required and the page/event chain preserves this proof.
        if (MeetingLocalPlanning::enabled($capacity)) {
            MeetingLocalPlanning::assertNoCounterRequests($task);
            $state['tokenizer_call_count'] = 0;
        } else $state['tokenizer_call_count'] = (new PostgresMeetingTokenCountLedger($task, $lease, $capacity))->countRequests();
        $head = $store->advance($task, $lease, $head, $state, null, true);
        return $this->status($head);
    }

    /** Exact page lookup; even completed plans revalidate source access and lease. */
    public function readLeaf(TaskRun $task, TaskExecutionLease $lease, int $leafSeq): array
    {
        $store = new PostgresMeetingPlanStore();
        $head = $store->completed($task, $lease);
        if ($leafSeq < 1 || $leafSeq > $head['state']['leaf_count']) self::invalid();
        $entries = $store->readPage($task, $lease, 'leaf', $leafSeq);
        if (count($entries) !== 1 || ($entries[0]['sequence_no'] ?? null) !== $leafSeq) self::invalid();
        $spec = $entries[0];
        $sources = $this->sources($task, $lease, array_column($spec['parts'], 'segment_index'));
        $messages = $this->messages($sources, $spec['parts'], self::input($task)['_meeting_summary']['output_contract_revision'] ?? 1);
        if (!hash_equals($spec['messages_sha256'], RequestPayloadFingerprint::fingerprint($messages, []))) self::invalid();
        $capacity = self::input($task)['_meeting_capacity'];
        if (MeetingLocalPlanning::enabled($capacity)) {
            if (isset($spec['token_count_receipt']) || !is_array($spec['planning_evidence'] ?? null)) self::invalid();
            MeetingLocalPlanning::assertNoCounterRequests($task);
            MeetingLocalPlanning::verify($spec['planning_evidence'], $messages);
        } else {
            PostgresMeetingTokenCountLedger::verifyReceipt($task, $capacity, $spec['token_count_receipt']);
            (new MeetingSummaryCapacity($capacity))->assertFits($spec['token_count_receipt'], $messages);
        }
        return $spec;
    }

    /** @param list<int> $positions @return list<array<string,mixed>> */
    public function readProofs(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        self::positions($positions);
        $store = new PostgresMeetingPlanStore();
        $store->completed($task, $lease);
        $live = $this->sources($task, $lease, $positions);
        $result = [];
        foreach ($positions as $position) $result[] = ['segment_index' => $position, 'proof' => $live[$position]['proof']];
        return $result;
    }

    public function planSnapshot(TaskRun $task, TaskExecutionLease $lease): array
    {
        return (new PostgresMeetingPlanStore())->snapshot((new PostgresMeetingPlanStore())->completed($task, $lease));
    }

    private function status(array $head): array
    {
        $result = ['phase' => $head['phase'], 'done' => $head['phase'] === 'complete',
            'source_cursor' => $head['state']['source_cursor'], 'leaf_count' => $head['state']['leaf_count']];
        if ($result['done']) $result['plan_proof'] = (new PostgresMeetingPlanStore())->snapshot($head);
        return $result;
    }

    public function readSourcePositions(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        self::positions($positions);
        (new PostgresMeetingPlanStore())->completed($task, $lease);
        return array_values($this->sources($task, $lease, $positions));
    }

    public function readSourceReferences(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        return array_map(static fn(array $row): array => ['segment_index' => $row['segment_index'],
            'proof' => $row['proof'], 'source_reference' => $row['private_segment']['source']],
            $this->readSourcePositions($task, $lease, $positions));
    }

    private function sources(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        $positions = array_values(array_unique($positions)); sort($positions, SORT_NUMERIC);
        if ($positions === [] || count($positions) > 512) self::invalid();
        $result = []; $store = new PostgresMeetingPlanStore();
        foreach (array_chunk($positions, self::WINDOW) as $window) {
            foreach ($store->readSourceWindow($task, $lease, $window) as $row) $result[$row['segment_index']] = $row;
        }
        return $result;
    }

    private function messages(array $sources, array $parts, int $revision): array
    {
        $lines = [];
        foreach ($parts as $part) {
            $row = $sources[$part['segment_index']] ?? null;
            if (!is_array($row) || $row['proof']['exclusion'] !== null
                || !is_int($part['start_offset'] ?? null) || !is_int($part['end_offset'] ?? null)
                || $part['start_offset'] < 0 || $part['end_offset'] <= $part['start_offset']
                || $part['end_offset'] > $row['proof']['unicode_characters']) self::invalid();
            $lines[] = $this->render($row['private_segment'], $part);
        }
        return [['role' => 'system', 'content' => MeetingSummaryEvidence::systemPrompt($revision)],
            ['role' => 'user', 'content' => "转写片段（字符偏移为原文 Unicode 码点范围，右端不含）：\n" . implode("\n", $lines)]];
    }

    private function render(array $segment, array $part): string
    {
        return sprintf('[S%d %d-%d ms %s%s chars %d-%d] %s', $part['segment_index'] + 1,
            $segment['start_ms'], $segment['end_ms'], is_string($segment['speaker'] ?? null) ? $segment['speaker'] : 'unknown',
            ($segment['source']['locator']['granularity'] ?? null) === 'input_range_only' ? ' whole-input-range; speech-timing-unavailable' : '',
            $part['start_offset'], $part['end_offset'],
            mb_substr($segment['text'], $part['start_offset'], $part['end_offset'] - $part['start_offset'], 'UTF-8'));
    }

    private function divide(array $parts): array
    {
        if (count($parts) > 1) {
            $middle = intdiv(count($parts), 2);
            return [array_slice($parts, 0, $middle), array_slice($parts, $middle)];
        }
        $part = $parts[0]; $length = $part['end_offset'] - $part['start_offset'];
        if ($length < 2) throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_INPUT_CAPACITY_EXCEEDED', '模型容量不足以容纳一个原文字符与来源提示，尚未发送摘要调用');
        $middle = $part['start_offset'] + intdiv($length, 2);
        return [[[...$part, 'end_offset' => $middle]], [[...$part, 'start_offset' => $middle]]];
    }

    private static function chain(string $previous, array $facts): string
    {
        return hash('sha256', $previous . ':' . RequestPayloadFingerprint::fingerprint($facts, []));
    }

    private static function input(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || !is_array($input['_meeting_capacity'] ?? null)) self::invalid();
        MeetingSummaryCapacity::assert($input['_meeting_capacity']);
        return $input;
    }

    private static function positions(array $positions): void
    {
        if (!array_is_list($positions) || $positions === [] || count($positions) > self::WINDOW) self::invalid();
        $previous = -1;
        foreach ($positions as $position) {
            if (!is_int($position) || $position <= $previous || $position > 9999) self::invalid();
            $previous = $position;
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE', '会议持久计划或来源覆盖证据不可用，请保留任务和调用费用记录');
    }
}
