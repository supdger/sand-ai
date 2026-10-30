<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Fixed source windows for a frozen v3 plan. No tokenizer/generation sender. */
final class PostgresMeetingPreparationStage
{
    private const POSITIONS = 32;
    private const ROW_BYTES = 65536;
    private const READ_BYTES = 2097152;

    /**
     * Reads at most 32 live source positions, checks each frozen proof, then
     * stores proof identities plus the existing page event/checkpoint atomically.
     * Transcript bodies are not copied into pages or returned to the caller.
     * @return array<string,mixed>
     */
    public function prepareSourcePage(TaskRun $task, TaskExecutionLease $lease, int $pageNo): array
    {
        self::pageNumber($pageNo);
        $ledger = new PostgresMeetingWorkerLedger();
        return $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease, $pageNo): array {
            $scope = $this->scope($current);
            [$start, $end] = self::window($pageNo, $scope['total_segments']);
            $entries = $this->verifiedSourceWindow($current, $scope, $start, $end, $lease);
            $saved = $ledger->appendPage($current, $lease, 'source', 0, $pageNo, $entries);
            return ['phase' => 'source_prepared', 'page_no' => $pageNo, 'page_id' => $saved['page_id'],
                'position_start' => $start, 'position_end' => $end, 'entry_count' => count($entries),
                'total_segments' => $scope['total_segments'], 'source_page_count' => (int) ceil($scope['total_segments'] / self::POSITIONS),
                'anchor_sha256' => $scope['anchor_sha256'], 'payload_sha256' => RequestPayloadFingerprint::fingerprint($entries, []),
                'idempotent_replay' => $saved['idempotent_replay'], 'coverage_verified' => false];
        });
    }

    /**
     * Reauthorize and compare the stored page to its current live source proofs.
     * It returns proof identities only, never old private transcription text.
     * @return array<string,mixed>
     */
    public function readPreparedSourcePage(TaskRun $task, TaskExecutionLease $lease, int $pageNo): array
    {
        self::pageNumber($pageNo);
        $ledger = new PostgresMeetingWorkerLedger();
        return $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease, $pageNo): array {
            $scope = $this->scope($current);
            [$start, $end] = self::window($pageNo, $scope['total_segments']);
            $expected = $this->verifiedSourceWindow($current, $scope, $start, $end, $lease);
            $saved = $ledger->readPage($current, $lease, 'source', 0, $pageNo);
            if (!RequestPayloadFingerprint::same($saved, $expected, [])) self::invalid();
            return ['page_no' => $pageNo, 'anchor_sha256' => $scope['anchor_sha256'], 'entries' => $saved,
                'coverage_verified' => false];
        });
    }

    /**
     * One exact window of EXISTING official success receipts. Missing, rejected
     * or unknown outcomes fail through the original ledger; no new key or send.
     * This does not rebuild leaf messages or certify leaf/source coverage.
     * @return array<string,mixed>
     */
    public function verifyExistingReceiptPage(TaskRun $task, TaskExecutionLease $lease, int $pageNo): array
    {
        self::pageNumber($pageNo);
        return (new PostgresMeetingWorkerLedger())->runStage($task, $lease, function (TaskRun $current) use ($pageNo, $lease): array {
            $scope = $this->scope($current);
            [$start, $end] = self::window($pageNo, $scope['leaf_count']);
            if (MeetingLocalPlanning::enabled($scope['capacity'])) {
                if ($scope['prepared_plan_ref'] === null) self::invalid();
                MeetingLocalPlanning::assertNoCounterRequests($current);
                $planner = new PostgresMeetingDurablePlanner();
                for ($position = $start; $position <= $end; ++$position) $planner->readLeaf($current, $lease, $position + 1);
                return ['phase' => 'local_planning_verified', 'page_no' => $pageNo,
                    'receipt_count' => 0, 'planning_evidence_count' => $end - $start + 1,
                    'anchor_sha256' => $scope['anchor_sha256'], 'counter_request_count' => 0,
                    'external_requests_sent' => 0, 'coverage_verified' => false];
            }
            if ($scope['prepared_plan_ref'] !== null) {
                $receipts = [];
                $planner = new PostgresMeetingDurablePlanner();
                for ($position = $start; $position <= $end; ++$position) {
                    $leaf = $planner->readLeaf($current, $lease, $position + 1);
                    $receipt = $leaf['token_count_receipt'] ?? null;
                    if (!is_array($receipt) || ($receipt['input_sha256'] ?? null) !== ($leaf['messages_sha256'] ?? null)) self::invalid();
                    $receipts[] = $receipt;
                }
                PostgresMeetingTokenCountLedger::verifyReceipts($current, $scope['capacity'], $receipts);
                return ['phase' => 'existing_receipts_verified', 'page_no' => $pageNo,
                    'receipt_count' => count($receipts), 'anchor_sha256' => $scope['anchor_sha256'],
                    'external_requests_sent' => 0, 'coverage_verified' => false];
            }
            $rows = Db::connect('pgsql')->query(
                "SELECT position_no,c.anchor_snapshot #>> ARRAY['plan','leaves',position_no::text,'messages_sha256'] AS messages_sha256,
                    (c.anchor_snapshot #> ARRAY['plan','leaves',position_no::text,'token_count_receipt'])::text AS receipt
                 FROM sand_ai_meeting_worker_checkpoint c CROSS JOIN generate_series(CAST(? AS integer), CAST(? AS integer)) position_no
                 WHERE c.task_id=? AND c.state='staging' ORDER BY position_no", [$start, $end, (int) $current->id]);
            if (count($rows) !== $end - $start + 1) self::invalid();
            $receipts = [];
            foreach ($rows as $offset => $row) {
                if ((int) $row['position_no'] !== $start + $offset || !is_string($row['receipt']) || strlen($row['receipt']) > 8192) self::invalid();
                $receipt = json_decode($row['receipt'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($receipt) || !is_string($row['messages_sha256'])
                    || preg_match('/\A[a-f0-9]{64}\z/D', $row['messages_sha256']) !== 1
                    || ($receipt['input_sha256'] ?? null) !== $row['messages_sha256']) self::invalid();
                $receipts[] = $receipt;
            }
            PostgresMeetingTokenCountLedger::verifyReceipts($current, $scope['capacity'], $receipts);
            return ['phase' => 'existing_receipts_verified', 'page_no' => $pageNo,
                'receipt_count' => count($receipts), 'anchor_sha256' => $scope['anchor_sha256'],
                'external_requests_sent' => 0, 'coverage_verified' => false];
        });
    }

    /** Internal materializer only: raw private segments, reauthorized before/after.
     * @param list<int> $positions @return array<string,mixed>
     */
    public function readMaterializationSources(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        if (!array_is_list($positions) || $positions === [] || count($positions) > self::POSITIONS) self::invalid();
        $last = -1;
        foreach ($positions as $position) {
            if (!is_int($position) || $position <= $last || $position > 9999) self::invalid();
            $last = $position;
        }
        return (new PostgresMeetingWorkerLedger())->runStage($task, $lease, function (TaskRun $current) use ($positions, $lease): array {
            $scope = $this->scope($current);
            if ($positions[count($positions)-1] >= $scope['total_segments']) self::invalid();
            return ['sources' => $this->verifiedSourcePositions($current, $scope, $positions, true, $lease)];
        });
    }

    /** @return array<string,mixed> */
    private function scope(TaskRun $task): array
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT source_kind,source_seal_id,anchor_sha256,(anchor_snapshot->'source')::text AS source,
                (anchor_snapshot->'capacity')::text AS capacity,
                anchor_snapshot #>> '{route,snapshot_sha256}' AS route_sha256,
                anchor_snapshot #>> '{plan,version}' AS planner_version,
                anchor_snapshot #>> '{plan,transcript_sha256}' AS transcript_sha256,
                anchor_snapshot #>> '{plan,capacity_sha256}' AS capacity_sha256,
                anchor_snapshot #>> '{plan,total_segments}' AS total_segments,
                CASE WHEN anchor_snapshot #> '{plan,prepared_plan_ref}' IS NOT NULL
                    THEN CAST(anchor_snapshot #>> '{plan,total_segments}' AS integer)
                    ELSE jsonb_array_length(anchor_snapshot #> '{plan,segments}') END AS proof_count,
                CASE WHEN anchor_snapshot #> '{plan,prepared_plan_ref}' IS NOT NULL
                    THEN CAST(anchor_snapshot #>> '{plan,leaf_count}' AS integer)
                    ELSE jsonb_array_length(anchor_snapshot #> '{plan,leaves}') END AS leaf_count,
                anchor_snapshot #> '{plan,prepared_plan_ref}' AS prepared_plan_ref
             FROM sand_ai_meeting_worker_checkpoint WHERE task_id=? AND state='staging'", [(int) $task->id]);
        if (count($rows) !== 1 || !is_string($rows[0]['source']) || !is_string($rows[0]['capacity'])
            || strlen($rows[0]['source']) > 8192 || strlen($rows[0]['capacity']) > 16384) self::invalid();
        $scope = $rows[0];
        $scope['source'] = json_decode($scope['source'], true, 16, JSON_THROW_ON_ERROR);
        $scope['capacity'] = json_decode($scope['capacity'], true, 32, JSON_THROW_ON_ERROR);
        $scope['total_segments'] = (int) $scope['total_segments'];
        $scope['leaf_count'] = (int) $scope['leaf_count'];
        if (!is_array($scope['source']) || !is_array($scope['capacity'])
            || $scope['planner_version'] !== MeetingSummaryPlan::CAPACITY_VERSION
            || $scope['transcript_sha256'] !== ($scope['source']['sha256'] ?? null)
            || $scope['capacity_sha256'] !== ($scope['capacity']['snapshot_sha256'] ?? null)
            || $scope['route_sha256'] !== ($scope['capacity']['route']['snapshot_sha256'] ?? null)
            || $scope['total_segments'] < 1 || $scope['total_segments'] > 10000
            || (int) $scope['proof_count'] !== $scope['total_segments']
            || $scope['leaf_count'] < 1 || $scope['leaf_count'] > 100000) self::invalid();
        MeetingSummaryCapacity::assert($scope['capacity']);
        // A typed aggregation source is reserved by the foundation. Its audited
        // manifest/complete-aggregate page reader must be wired before use.
        if (($scope['source_kind'] !== 'seal' || $scope['source_seal_id'] === null) && $scope['prepared_plan_ref'] === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '长录音聚合来源页读取尚未接入，保留原任务和来源');
        }
        return $scope;
    }

    /** @param array<string,mixed> $scope @return list<array<string,mixed>> */
    private function verifiedSourceWindow(TaskRun $task, array $scope, int $start, int $end, TaskExecutionLease $lease): array
    {
        return $this->verifiedSourcePositions($task, $scope, range($start, $end), false, $lease);
    }

    /** @param array<string,mixed> $scope @param list<int> $positions @return list<array<string,mixed>> */
    private function verifiedSourcePositions(TaskRun $task, array $scope, array $positions, bool $includeSegments = false, ?TaskExecutionLease $lease = null): array
    {
        if ($scope['prepared_plan_ref'] !== null) {
            $entries = (new PostgresMeetingDurablePlanner())->readSourcePositions($task, $lease, $positions);
            if (count($entries) !== count($positions) || count($entries) > self::POSITIONS) self::invalid();
            foreach ($entries as $offset => &$entry) {
                if (($entry['segment_index'] ?? null) !== $positions[$offset] || !is_array($entry['proof'] ?? null)
                    || !is_array($entry['private_segment'] ?? null)) self::invalid();
                if (!$includeSegments) unset($entry['private_segment']);
            }
            unset($entry);
            return $entries;
        }
        // Direct array positions; never enumerate all transcript segments or
        // rebuild a complete plan. Latest text and explicit speaker reproduce
        // the seal's existing immutable historical-review projection.
        $rows = Db::connect('pgsql')->query(
            "WITH source_window AS (
                SELECT position_no,
                    jsonb_build_object(
                        'text',CASE WHEN correction.id IS NULL THEN original.segment->>'text'
                            ELSE btrim(correction.review_text,E' \t\n\r\013') END,
                        'speaker',CASE WHEN speaker.id IS NULL THEN original.segment->'speaker'
                            ELSE to_jsonb(speaker.review_speaker) END,
                        'start_ms',original.segment->'start_ms','end_ms',original.segment->'end_ms',
                        'source',original.segment->'source') AS segment,
                    c.anchor_snapshot #> ARRAY['plan','segments',position_no::text] AS proof
                FROM sand_ai_meeting_worker_checkpoint c
                JOIN sand_ai_audio_transcript_seal seal ON seal.id=c.source_seal_id
                JOIN sand_ai_task_run audio ON audio.id=seal.audio_task_id
                CROSS JOIN jsonb_array_elements_text(CAST(? AS jsonb)) selected(position_text)
                CROSS JOIN LATERAL (SELECT CAST(selected.position_text AS integer) AS position_no) selected_position
                CROSS JOIN LATERAL (SELECT audio.result->'segments'->position_no AS segment) original
                LEFT JOIN LATERAL (
                    SELECT id,review_text FROM sand_ai_audio_segment_review
                    WHERE audio_task_id=audio.id AND segment_index=position_no AND revision<=seal.review_revision
                    ORDER BY revision DESC LIMIT 1) correction ON true
                LEFT JOIN LATERAL (
                    SELECT id,review_speaker FROM sand_ai_audio_segment_review
                    WHERE audio_task_id=audio.id AND segment_index=position_no AND revision<=seal.review_revision AND speaker_changed
                    ORDER BY revision DESC LIMIT 1) speaker ON true
                WHERE c.task_id=? AND c.source_kind='seal' AND c.state='staging'
                    AND seal.content_purged_time IS NULL AND audio.content_purged_time IS NULL
                    AND seal.environment_id=? AND audio.environment_id=seal.environment_id
                    AND audio.state='succeeded' AND audio.status=1 AND audio.delete_time IS NULL
                    AND seal.total_segments=? AND jsonb_array_length(audio.result->'segments')=seal.total_segments
            ) SELECT position_no,
                CASE WHEN octet_length(segment::text)<=65536 THEN segment::text ELSE NULL END AS segment,
                CASE WHEN octet_length(proof::text)<=2048 THEN proof::text ELSE NULL END AS proof
              FROM source_window ORDER BY position_no", [json_encode($positions, JSON_THROW_ON_ERROR), (int) $task->id, (int) $task->environment_id, $scope['total_segments']]);
        if (count($rows) !== count($positions) || count($rows) > self::POSITIONS) self::invalid();
        $entries = []; $bytes = 0;
        foreach ($rows as $offset => $row) {
            if ((int) $row['position_no'] !== $positions[$offset] || !is_string($row['segment']) || !is_string($row['proof'])
                || strlen($row['segment']) > self::ROW_BYTES || strlen($row['proof']) > 2048) self::invalid();
            $bytes += strlen($row['segment']) + strlen($row['proof']);
            if ($bytes > self::READ_BYTES + self::POSITIONS * 2048) self::invalid();
            $segment = json_decode($row['segment'], true, 32, JSON_THROW_ON_ERROR);
            $proof = json_decode($row['proof'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($segment) || !is_array($proof) || count($proof) !== 5
                || !is_string($segment['text'] ?? null) || preg_match('//u', $segment['text']) !== 1
                || !is_int($segment['start_ms'] ?? null) || !is_int($segment['end_ms'] ?? null)
                || $segment['start_ms'] < 0 || $segment['end_ms'] <= $segment['start_ms']
                || !is_array($segment['source'] ?? null)
                || (!is_string($segment['speaker'] ?? null) && ($segment['speaker'] ?? null) !== null)) self::invalid();
            $index = $positions[$offset];
            $actual = ['segment_id' => 'S' . ($index + 1), 'text_sha256' => hash('sha256', $segment['text']),
                'unicode_characters' => mb_strlen($segment['text'], 'UTF-8'),
                'source_sha256' => RequestPayloadFingerprint::fingerprint([
                    'speaker' => $segment['speaker'] ?? null, 'start_ms' => $segment['start_ms'],
                    'end_ms' => $segment['end_ms'], 'source' => $segment['source']], []),
                'exclusion' => trim($segment['text']) === '' ? 'no_usable_text' : null];
            if ($actual['unicode_characters'] > 10000 || !RequestPayloadFingerprint::same($proof, $actual, [])) self::invalid();
            // Fixed-size proof facts only. Raw text was checked, then discarded.
            $entry = ['segment_index' => $index, 'proof' => $actual];
            if ($includeSegments) $entry['private_segment'] = $segment;
            $entries[] = $entry;
        }
        return $entries;
    }

    private static function pageNumber(int $pageNo): void
    {
        if ($pageNo < 1 || $pageNo > 3125) self::invalid();
    }

    /** @return array{int,int} */
    private static function window(int $pageNo, int $total): array
    {
        $start = ($pageNo - 1) * self::POSITIONS;
        if ($start >= $total) self::invalid();
        return [$start, min($total - 1, $start + self::POSITIONS - 1)];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议来源页或固定规划凭据不可用，请保留原任务与费用记录');
    }
}
