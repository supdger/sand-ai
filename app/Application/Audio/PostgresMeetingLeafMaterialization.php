<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/**
 * Private materialization stages. Each call prepares at most 32 original
 * intervals or seals one bounded leaf. It never sends a tokenizer/model call.
 */
final class PostgresMeetingLeafMaterialization
{
    public const VERSION = '202610030112_meeting_leaf_materialization';
    private const PARTS_PER_PAGE = 32;
    private const MAX_PARTS = 512;

    /** @return array<string,mixed> */
    public function prepareLeafPartPage(TaskRun $task, TaskExecutionLease $lease, int $leafSequence, int $partPage): array
    {
        if ($partPage < 1 || $partPage > 16) self::invalid();
        $ledger = new PostgresMeetingWorkerLedger();
        return $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease, $leafSequence, $partPage): array {
            $this->assertSchema();
            $scope = $this->scope($current, $leafSequence);
            $start = ($partPage - 1) * self::PARTS_PER_PAGE;
            $parts = array_slice($scope['leaf']['parts'], $start, self::PARTS_PER_PAGE);
            if ($parts === []) self::invalid();
            $indices = array_values(array_unique(array_column($parts, 'segment_index')));
            sort($indices, SORT_NUMERIC);
            $sources = (new PostgresMeetingPreparationStage())->readMaterializationSources($current, $lease, $indices)['sources'];
            $byIndex = [];
            foreach ($sources as $source) {
                $index = $source['segment_index'];
                // A source page is a prerequisite, not an unverified cursor.
                $proofRows = Db::connect('pgsql')->query(
                    "SELECT p.private_payload->CAST(? AS integer) AS entry
                     FROM sand_ai_meeting_worker_page p WHERE p.task_id=? AND p.page_kind='source'
                     AND p.round_no=0 AND p.sequence_no=?", [$index % 32, (int) $current->id, intdiv($index, 32) + 1]);
                if (count($proofRows) !== 1) self::invalid();
                $stored = self::array($proofRows[0]['entry']);
                if ($stored === null || !RequestPayloadFingerprint::same($stored,
                    ['segment_index' => $index, 'proof' => $source['proof']], [])) self::invalid();
                $byIndex[$index] = $source;
            }
            $entries = [];
            foreach ($parts as $offset => $part) {
                $source = $byIndex[$part['segment_index']] ?? null;
                if (!is_array($source) || $part['end_offset'] > $source['proof']['unicode_characters']
                    || $source['proof']['exclusion'] !== null) self::invalid();
                $entries[] = ['leaf_sequence_no' => $leafSequence, 'part_index' => $start + $offset,
                    'part' => $part, 'source_proof' => $source['proof'],
                    'line' => self::render($source['private_segment'], $part)];
            }
            $mapping = Db::connect('pgsql')->query(
                'SELECT page_sequence_no FROM sand_ai_meeting_leaf_part_page WHERE task_id=? AND leaf_sequence_no=? AND part_page_no=?',
                [(int) $current->id, $leafSequence, $partPage]);
            if (count($mapping) > 1) self::invalid();
            if ($mapping === []) {
                $last = Db::connect('pgsql')->query(
                    "SELECT sequence_no FROM sand_ai_meeting_worker_page WHERE task_id=? AND page_kind='leaf' AND round_no=0 ORDER BY sequence_no DESC LIMIT 1",
                    [(int) $current->id]);
                $sequence = $last === [] ? 1 : (int) $last[0]['sequence_no'] + 1;
            } else {
                $sequence = (int) $mapping[0]['page_sequence_no'];
            }
            $saved = $ledger->appendPage($current, $lease, 'leaf', 0, $sequence, $entries);
            if ($mapping === []) {
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_meeting_leaf_part_page (task_id,leaf_sequence_no,part_page_no,page_id,page_sequence_no,part_start,part_count,anchor_sha256) VALUES (?,?,?,?,?,?,?,?)',
                    [(int) $current->id, $leafSequence, $partPage, $saved['page_id'], $sequence, $start, count($parts), $scope['anchor_sha256']]);
            }
            return ['phase' => 'leaf_parts_prepared', 'leaf_sequence_no' => $leafSequence, 'part_page_no' => $partPage,
                'part_count' => count($parts), 'part_page_count' => (int) ceil(count($scope['leaf']['parts']) / 32),
                'idempotent_replay' => $saved['idempotent_replay'], 'coverage_verified' => false, 'external_requests_sent' => 0];
        });
    }

    /** Creates one queued node only after all of its immutable pages match.
     * @return array<string,mixed>
     */
    public function materializeLeaf(TaskRun $task, TaskExecutionLease $lease, int $leafSequence): array
    {
        $ledger = new PostgresMeetingWorkerLedger();
        return $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease, $leafSequence): array {
            $this->assertSchema();
            $scope = $this->scope($current, $leafSequence);
            $spec = $scope['leaf'];
            $pages = Db::connect('pgsql')->query(
                'SELECT part_page_no,page_sequence_no,part_start,part_count,anchor_sha256
                 FROM sand_ai_meeting_leaf_part_page WHERE task_id=? AND leaf_sequence_no=? ORDER BY part_page_no LIMIT 17',
                [(int) $current->id, $leafSequence]);
            if (count($pages) !== (int) ceil(count($spec['parts']) / 32)) self::invalid();
            $lines = []; $ids = []; $chars = 0;
            foreach ($pages as $pageOffset => $page) {
                $start = $pageOffset * 32;
                if ((int) $page['part_page_no'] !== $pageOffset + 1 || (int) $page['part_start'] !== $start
                    || $page['anchor_sha256'] !== $scope['anchor_sha256']) self::invalid();
                $entries = $ledger->readPage($current, $lease, 'leaf', 0, (int) $page['page_sequence_no']);
                if (count($entries) !== min(32, count($spec['parts']) - $start)
                    || count($entries) !== (int) $page['part_count']) self::invalid();
                foreach ($entries as $offset => $entry) {
                    $part = $spec['parts'][$start + $offset];
                    $proof = $entry['source_proof'] ?? null;
                    if (($entry['leaf_sequence_no'] ?? null) !== $leafSequence || ($entry['part_index'] ?? null) !== $start + $offset
                        || !is_array($entry['part'] ?? null) || !RequestPayloadFingerprint::same($entry['part'], $part, [])
                        || !is_array($proof) || ($proof['segment_id'] ?? null) !== 'S' . ($part['segment_index'] + 1)
                        || !is_string($entry['line'] ?? null) || preg_match('//u', $entry['line']) !== 1) self::invalid();
                    $chars += mb_strlen($entry['line'], 'UTF-8') + 1;
                    if ($chars > MeetingSummaryPlan::MAX_INPUT_CHARS) self::invalid();
                    $lines[] = $entry['line'];
                    $ids['S' . ($part['segment_index'] + 1)] = true;
                }
            }
            $taskInput = self::array($current->input);
            $revision = $taskInput['_meeting_summary']['output_contract_revision'] ?? 1;
            if (!is_int($revision)) self::invalid();
            $messages = [['role' => 'system', 'content' => MeetingSummaryEvidence::systemPrompt($revision)],
                ['role' => 'user', 'content' => "转写片段（字符偏移为原文 Unicode 码点范围，右端不含）：\n" . implode("\n", $lines)]];
            if (!hash_equals($spec['messages_sha256'], RequestPayloadFingerprint::fingerprint($messages, []))) self::invalid();
            if (MeetingLocalPlanning::enabled($scope['capacity'])) {
                MeetingLocalPlanning::assertNoCounterRequests($current);
                MeetingLocalPlanning::verify($spec['planning_evidence'], $messages);
            } else {
                PostgresMeetingTokenCountLedger::verifyReceipt($current, $scope['capacity'], $spec['token_count_receipt']);
                (new MeetingSummaryCapacity($scope['capacity']))->assertFits($spec['token_count_receipt'], $messages);
            }
            $input = ['messages' => $messages, 'segment_ids' => array_keys($ids)];
            $text = self::encode($input);
            if (strlen($text) > 262144) self::invalid();
            $hash = RequestPayloadFingerprint::fingerprint($input, []);
            $nodes = Db::connect('pgsql')->query(
                'SELECT n.id,n.task_step_id,n.input_sha256,n.input_snapshot,n.node_kind,n.level_no,n.sequence_no,n.transcript_sha256,n.review_revision,n.planner_version,
                    n.segment_start_index,n.segment_end_index,s.step_code,s.sequence_no AS step_sequence
                 FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step s ON s.id=n.task_step_id AND s.task_id=n.task_id
                 WHERE n.task_id=? AND n.node_code=?', [(int) $current->id, $spec['node_code']]);
            $replay = $nodes !== [];
            $first = $spec['parts'][0]['segment_index'];
            $last = $spec['parts'][count($spec['parts']) - 1]['segment_index'];
            if ($replay) {
                if (count($nodes) !== 1) self::invalid();
                $node = $nodes[0];
                if ($node['input_sha256'] !== $hash || $node['node_kind'] !== 'chunk' || (int) $node['level_no'] !== 0
                    || (int) $node['sequence_no'] !== $leafSequence || (int) $node['step_sequence'] !== $leafSequence + 1
                    || $node['step_code'] !== $spec['node_code'] || $node['transcript_sha256'] !== $scope['source']['sha256']
                    || (int) $node['review_revision'] !== $scope['source']['review_revision']
                    || $node['planner_version'] !== MeetingSummaryPlan::CAPACITY_VERSION
                    || (int) $node['segment_start_index'] !== $first || (int) $node['segment_end_index'] !== $last
                    || !RequestPayloadFingerprint::same(self::array($node['input_snapshot']) ?? [], $input, [])) self::invalid();
            } else {
                $step = TaskStep::create(['task_id' => (int) $current->id, 'step_code' => $spec['node_code'],
                    'sequence_no' => $leafSequence + 1, 'state' => 'queued', 'attempt_count' => 0,
                    'context' => ['node_kind' => 'chunk', 'level_no' => 0], 'status' => 1]);
                $created = Db::connect('pgsql')->query(
                    "INSERT INTO sand_ai_meeting_summary_node (task_id,task_step_id,node_code,node_kind,level_no,sequence_no,
                        segment_start_index,segment_end_index,transcript_sha256,review_revision,planner_version,input_sha256,input_snapshot,worker_input_text,worker_messages_text)
                     VALUES (?,?,?,'chunk',0,?,?,?,?,?,?,?,?,?,?) RETURNING id,task_step_id",
                    [(int) $current->id, (int) $step->id, $spec['node_code'], $leafSequence, $first, $last,
                        $scope['source']['sha256'], $scope['source']['review_revision'], MeetingSummaryPlan::CAPACITY_VERSION, $hash, $text, $text, self::encode($messages)]);
                if (count($created) !== 1) self::invalid();
                $node = $created[0];
            }
            $ledger->appendPage($current, $lease, 'frontier', 0, $leafSequence,
                [['node_code' => $spec['node_code'], 'node_id' => (int) $node['id'], 'input_sha256' => $hash, 'state' => 'pending']]);
            return ['phase' => 'leaf_materialized', 'node_id' => (int) $node['id'], 'task_step_id' => (int) $node['task_step_id'],
                'node_code' => $spec['node_code'], 'input_sha256' => $hash, 'idempotent_replay' => $replay,
                'coverage_verified' => false, 'external_requests_sent' => 0];
        });
    }

    /** @return array<string,mixed> */
    private function scope(TaskRun $task, int $leafSequence): array
    {
        if ($leafSequence < 1 || $leafSequence > 100000) self::invalid();
        $rows = Db::connect('pgsql')->query(
            "SELECT anchor_sha256,(anchor_snapshot->'source')::text AS source,(anchor_snapshot->'capacity')::text AS capacity,
                CASE WHEN octet_length((lp.private_payload->0)::text)<=131072
                    THEN (lp.private_payload->0)::text ELSE NULL END AS leaf,
                anchor_snapshot #>> '{plan,version}' AS version,
                anchor_snapshot #>> '{plan,capacity_sha256}' AS capacity_sha256,
                anchor_snapshot #>> '{plan,transcript_sha256}' AS transcript_sha256,
                anchor_snapshot #>> '{route,snapshot_sha256}' AS route_sha256
             FROM sand_ai_meeting_worker_checkpoint c JOIN sand_ai_meeting_plan_page lp ON lp.task_id=c.task_id
                AND lp.page_kind='leaf' AND lp.sequence_no=? WHERE c.task_id=? AND c.state='staging'",
            [$leafSequence, (int) $task->id]);
        if (count($rows) !== 1 || !is_string($rows[0]['source']) || strlen($rows[0]['source']) > 8192
            || !is_string($rows[0]['capacity']) || strlen($rows[0]['capacity']) > 16384
            || !is_string($rows[0]['leaf']) || strlen($rows[0]['leaf']) > 131072) self::invalid();
        $scope = $rows[0];
        foreach (['source', 'capacity', 'leaf'] as $key) $scope[$key] = json_decode($scope[$key], true, 32, JSON_THROW_ON_ERROR);
        $leaf = $scope['leaf'];
        if (!is_array($scope['source']) || !is_array($scope['capacity']) || !is_array($leaf)
            || $scope['version'] !== MeetingSummaryPlan::CAPACITY_VERSION
            || $scope['capacity_sha256'] !== ($scope['capacity']['snapshot_sha256'] ?? null)
            || $scope['route_sha256'] !== ($scope['capacity']['route']['snapshot_sha256'] ?? null)
            || $scope['transcript_sha256'] !== ($scope['source']['sha256'] ?? null)
            || ($leaf['sequence_no'] ?? null) !== $leafSequence || ($leaf['node_code'] ?? null) !== sprintf('chunk_%05d', $leafSequence)
            || !is_string($leaf['messages_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $leaf['messages_sha256']) !== 1
            || (MeetingLocalPlanning::enabled($scope['capacity'])
                ? (!is_array($leaf['planning_evidence'] ?? null) || array_key_exists('token_count_receipt', $leaf))
                : !is_array($leaf['token_count_receipt'] ?? null)) || !is_array($leaf['parts'] ?? null) || !array_is_list($leaf['parts'])
            || count($leaf['parts']) < 1 || count($leaf['parts']) > self::MAX_PARTS) self::invalid();
        $previous = null;
        foreach ($leaf['parts'] as $part) {
            if (!is_array($part) || count($part) !== 3 || !is_int($part['segment_index'] ?? null)
                || !is_int($part['start_offset'] ?? null) || !is_int($part['end_offset'] ?? null)
                || $part['segment_index'] < 0 || $part['segment_index'] > 9999 || $part['start_offset'] < 0
                || $part['end_offset'] <= $part['start_offset'] || $part['end_offset'] > 10000) self::invalid();
            if ($previous !== null && ($part['segment_index'] < $previous['segment_index']
                || ($part['segment_index'] === $previous['segment_index'] && $part['start_offset'] !== $previous['end_offset']))) self::invalid();
            $previous = $part;
        }
        MeetingSummaryCapacity::assert($scope['capacity']);
        return $scope;
    }

    private function assertSchema(): void
    {
        $path = base_path('plugin/sand-ai/migrations/main-app/' . self::VERSION . '.pgsql');
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        $rows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [self::VERSION]);
        $triggers = Db::connect('pgsql')->query("SELECT count(*) AS total FROM pg_trigger WHERE NOT tgisinternal
            AND tgenabled IN ('O','A') AND ((tgrelid=to_regclass('sand_ai_meeting_leaf_part_page') AND tgname='sand_ai_meeting_leaf_part_guard')
            OR (tgrelid=to_regclass('sand_ai_meeting_worker_page') AND tgname='sand_ai_meeting_leaf_part_commit'))");
        if (!is_string($hash) || count($rows) !== 1 || !hash_equals($hash, (string) $rows[0]['checksum'])
            || count($triggers) !== 1 || (int) $triggers[0]['total'] !== 2) self::invalid();
    }

    /** @param array<string,mixed> $segment @param array<string,int> $part */
    private static function render(array $segment, array $part): string
    {
        return sprintf('[S%d %d-%d ms %s%s chars %d-%d] %s', $part['segment_index'] + 1,
            $segment['start_ms'], $segment['end_ms'], $segment['speaker'] ?? 'unknown',
            ($segment['source']['locator']['granularity'] ?? null) === 'input_range_only' ? ' whole-input-range; speech-timing-unavailable' : '',
            $part['start_offset'], $part['end_offset'],
            mb_substr($segment['text'], $part['start_offset'], $part['end_offset'] - $part['start_offset'], 'UTF-8'));
    }

    private static function array(mixed $value): ?array
    {
        if (is_string($value)) $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : null;
    }

    private static function encode(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_object($value) || is_resource($value) || is_float($value)) self::invalid();
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议叶节点持久规划或来源已失效，请保留原任务与费用记录');
    }
}
