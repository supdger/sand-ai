<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\AiTaskGateway;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Fenced page/event storage used by the durable worker's execution stages. */
final class PostgresMeetingWorkerLedger
{
    public const VERSION = '202610030109_meeting_worker_foundation';
    public const DRAFT_PATH = 'plugin/sand-ai/migrations/main-app/' . self::VERSION . '.pgsql';
    private const PAGE_BYTES = 262144;

    /** One authenticated storage stage. Does not enroll, dispatch, or certify coverage.
     * @param \Closure(TaskRun):array<string,mixed> $stage
     * @return array<string,mixed>
     */
    public function runStage(TaskRun $task, TaskExecutionLease $lease, \Closure $stage): array
    {
        if (!MeetingExecutionRuntime::forTask($task)) self::invalid();
        $this->assertReady();
        return Db::connect('pgsql')->transaction(function () use ($task, $lease, $stage): array {
            $this->bindLease($lease);
            $current = $this->current($task, $lease);
            $this->authorize($current);
            $result = $stage($current);
            $this->authorize($current);
            $this->assertLeaseNow($lease);
            return $result;
        });
    }

    /** New, already planned tasks only. No migration/backfill of existing nodes. */
    public function enroll(TaskRun $task, TaskExecutionLease $lease): void
    {
        if (!MeetingExecutionRuntime::forTask($task)) self::invalid();
        $this->assertReady();
        Db::connect('pgsql')->transaction(function () use ($task, $lease): void {
            $this->bindLease($lease);
            $current = $this->current($task, $lease);
            $this->authorize($current);
            $input = self::input($current);
            $meeting = $input['_meeting_summary'];
            $seal = AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal'] ?? null);
            $aggregation = $meeting['aggregation_source'] ?? null;
            if (($seal === null) === !is_array($aggregation)) self::invalid();
            unset($meeting['audio_authorization']);
            $anchor = ['source' => $meeting, 'plan' => $input['_meeting_plan'] ?? null,
                'capacity' => $input['_meeting_capacity'] ?? null, 'route' => $input['_meeting_route'] ?? null];
            if (!is_array($anchor['plan']) || !is_array($anchor['capacity']) || !is_array($anchor['route'])) self::invalid();
            $text = self::encode($anchor);
            if (strlen($text) > 8388608) self::invalid();
            $existing = Db::connect('pgsql')->query(
                'SELECT anchor_sha256,state FROM sand_ai_meeting_worker_checkpoint WHERE task_id=? FOR UPDATE', [(int) $current->id]);
            if ($existing !== []) {
                if (count($existing) !== 1 || $existing[0]['state'] !== 'staging'
                    || !hash_equals(hash('sha256', $text), (string) $existing[0]['anchor_sha256'])) self::invalid();
            } else {
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_meeting_worker_checkpoint (task_id,source_kind,source_seal_id,anchor_snapshot,anchor_sha256,anchor_text) VALUES (?,?,?,?,?,?)',
                    [(int) $current->id, $seal === null ? 'aggregation' : 'seal', $seal['id'] ?? null, $text, hash('sha256', $text), $text]);
            }
            $this->authorize($current);
            $this->assertLeaseNow($lease);
        });
    }

    /**
     * One immutable page and its chain event, in one parent-task transaction.
     * Entries are pending stage data. Storing them does not validate the plan.
     * @param list<array<string,mixed>> $entries
     * @return array{page_id:int,idempotent_replay:bool}
     */
    public function appendPage(TaskRun $task, TaskExecutionLease $lease, string $kind, int $round, int $sequence, array $entries): array
    {
        if (!MeetingExecutionRuntime::forTask($task)) self::invalid();
        $this->assertReady();
        if (!in_array($kind, ['source', 'leaf', 'frontier'], true) || $round < 0 || $round > 32
            || $sequence < 1 || $sequence > 200000 || !array_is_list($entries) || count($entries) < 1 || count($entries) > 32) self::invalid();
        foreach ($entries as $entry) if (!is_array($entry) || array_is_list($entry)) self::invalid();
        $text = self::encode($entries);
        if (strlen($text) > self::PAGE_BYTES) self::invalid(); // Reject, never truncate.
        return Db::connect('pgsql')->transaction(function () use ($task, $lease, $kind, $round, $sequence, $entries, $text): array {
            $this->bindLease($lease);
            $current = $this->current($task, $lease);
            $this->authorize($current);
            $checkpoint = Db::connect('pgsql')->query(
                'SELECT anchor_sha256,event_sequence,last_event_sha256,state FROM sand_ai_meeting_worker_checkpoint WHERE task_id=? FOR UPDATE', [$lease->taskId]);
            if (count($checkpoint) !== 1 || $checkpoint[0]['state'] !== 'staging') self::invalid();
            $head = $checkpoint[0];
            $existing = Db::connect('pgsql')->query(
                'SELECT id,payload_sha256,private_payload FROM sand_ai_meeting_worker_page WHERE task_id=? AND page_kind=? AND round_no=? AND sequence_no=?',
                [$lease->taskId, $kind, $round, $sequence]);
            if ($existing !== []) {
                if (count($existing) !== 1 || $existing[0]['private_payload'] === null
                    || !hash_equals(hash('sha256', $text), (string) $existing[0]['payload_sha256'])) self::invalid();
                $this->authorize($current);
                $this->assertLeaseNow($lease);
                return ['page_id' => (int) $existing[0]['id'], 'idempotent_replay' => true];
            }
            $page = Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_meeting_worker_page (task_id,page_kind,round_no,sequence_no,entry_count,payload_sha256,private_payload,payload_text) VALUES (?,?,?,?,?,?,?,?) RETURNING id,payload_sha256',
                [$lease->taskId, $kind, $round, $sequence, count($entries), hash('sha256', $text), $text, $text]);
            if (count($page) !== 1) self::invalid();
            $event = ['task_id' => $lease->taskId, 'sequence_no' => (int) $head['event_sequence'] + 1,
                'previous_sha256' => $head['last_event_sha256'], 'anchor_sha256' => $head['anchor_sha256'],
                'page_id' => (int) $page[0]['id'], 'payload_sha256' => $page[0]['payload_sha256'],
                'page_kind' => $kind, 'round_no' => $round, 'page_sequence_no' => $sequence,
                'entry_count' => count($entries), 'lease_fence' => $lease->fence];
            $eventText = self::encode($event);
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_meeting_worker_event (task_id,sequence_no,previous_sha256,event_sha256,page_id,anchor_sha256,lease_fence,event_text) VALUES (?,?,?,?,?,?,?,?)',
                [$lease->taskId, $event['sequence_no'], $head['last_event_sha256'], hash('sha256', $eventText),
                    $event['page_id'], $head['anchor_sha256'], $lease->fence, $eventText]);
            $this->authorize($current);
            $this->assertLeaseNow($lease); // After all page/event locks, next to commit.
            return ['page_id' => $event['page_id'], 'idempotent_replay' => false];
        });
    }

    /** Exact lookup only; authorization is revalidated, no full history scan.
     * @return list<array<string,mixed>>
     */
    public function readPage(TaskRun $task, TaskExecutionLease $lease, string $kind, int $round, int $sequence): array
    {
        if (!MeetingExecutionRuntime::forTask($task)) self::invalid();
        $this->assertReady();
        return Db::connect('pgsql')->transaction(function () use ($task, $lease, $kind, $round, $sequence): array {
            $this->bindLease($lease);
            $current = $this->current($task, $lease);
            $this->authorize($current);
            $rows = Db::connect('pgsql')->query(
                'SELECT p.private_payload,p.payload_sha256,p.entry_count FROM sand_ai_meeting_worker_page p JOIN sand_ai_meeting_worker_checkpoint c ON c.task_id=p.task_id WHERE p.task_id=? AND p.page_kind=? AND p.round_no=? AND p.sequence_no=? AND c.state=\'staging\'',
                [$lease->taskId, $kind, $round, $sequence]);
            if (count($rows) !== 1 || !is_string($rows[0]['private_payload'])) self::invalid();
            $entries = json_decode($rows[0]['private_payload'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($entries) || !array_is_list($entries) || count($entries) < 1 || count($entries) > 32
                || count($entries) !== (int) $rows[0]['entry_count']
                || strlen(self::encode($entries)) > self::PAGE_BYTES
                || !hash_equals((string) $rows[0]['payload_sha256'], RequestPayloadFingerprint::fingerprint($entries, []))) self::invalid();
            $this->authorize($current);
            $this->assertLeaseNow($lease);
            return $entries;
        });
    }

    public function assertReady(bool $requireActivation = true): void
    {
        if ($requireActivation && !MeetingExecutionRuntime::available()) self::invalid();
        $path = base_path(self::DRAFT_PATH);
        $checksum = is_file($path) ? hash_file('sha256', $path) : false;
        // Deliberately no deployment auto-detection, table creation or migration.
        // The complete registered lifecycle must precede runtime enabling.
        $rows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [self::VERSION]);
        if (!is_string($checksum) || count($rows) !== 1 || !hash_equals($checksum, (string) $rows[0]['checksum'])) self::invalid();
        $triggers = Db::connect('pgsql')->query("SELECT count(*) AS total FROM (VALUES
            ('sand_ai_meeting_worker_checkpoint','sand_ai_meeting_worker_checkpoint_guard'),
            ('sand_ai_meeting_worker_page','sand_ai_meeting_worker_page_guard'),
            ('sand_ai_meeting_worker_event','sand_ai_meeting_worker_event_guard'),
            ('sand_ai_meeting_worker_event','sand_ai_meeting_worker_event_head'),
            ('sand_ai_meeting_worker_page','sand_ai_meeting_worker_page_commit'),
            ('sand_ai_task_run','sand_ai_meeting_worker_task_anchor'),
            ('sand_ai_task_run','sand_ai_meeting_worker_purge'),
            ('sand_ai_meeting_summary_node','sand_ai_meeting_worker_node_guard'),
            ('sand_ai_meeting_summary_node','sand_ai_meeting_worker_node_seal'),
            ('sand_ai_task_step','sand_ai_meeting_worker_step_guard'),
            ('sand_ai_task_step','sand_ai_meeting_worker_step_seal'),
            ('sand_ai_meeting_leaf_part_page','sand_ai_meeting_leaf_part_guard'),
            ('sand_ai_meeting_worker_page','sand_ai_meeting_leaf_part_commit'),
            ('sand_ai_meeting_summary_node','sand_ai_meeting_node_message_guard'),
            ('sand_ai_meeting_worker_page','sand_ai_meeting_verification_guard'),
            ('sand_ai_meeting_summary_node','sand_ai_meeting_verified_graph_guard'),
            ('sand_ai_task_run','sand_ai_meeting_worker_completion_guard')) expected(relation_name,trigger_name)
            JOIN pg_trigger t ON t.tgrelid=to_regclass(expected.relation_name) AND t.tgname=expected.trigger_name
            WHERE NOT t.tgisinternal AND t.tgenabled IN ('O','A')");
        if (count($triggers) !== 1 || (int) $triggers[0]['total'] !== 17) self::invalid();
        $terminalVersion = '202610030120_meeting_terminal_transition';
        $terminalPath = base_path('plugin/sand-ai/migrations/main-app/' . $terminalVersion . '.pgsql');
        $terminalChecksum = is_file($terminalPath) ? hash_file('sha256', $terminalPath) : false;
        $terminalRows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [$terminalVersion]);
        if (!is_string($terminalChecksum) || count($terminalRows) !== 1
            || !hash_equals($terminalChecksum, (string) $terminalRows[0]['checksum'])) self::invalid();
        $leafPath = base_path('plugin/sand-ai/migrations/main-app/' . PostgresMeetingLeafMaterialization::VERSION . '.pgsql');
        $leafChecksum = is_file($leafPath) ? hash_file('sha256', $leafPath) : false;
        $leafRows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [PostgresMeetingLeafMaterialization::VERSION]);
        if (!is_string($leafChecksum) || count($leafRows) !== 1 || !hash_equals($leafChecksum, (string) $leafRows[0]['checksum'])) self::invalid();
    }

    private function bindLease(TaskExecutionLease $lease): void
    {
        Db::connect('pgsql')->query("SELECT set_config('sand_ai.running_timezone',?,true),set_config('sand_ai.worker_owner',?,true),set_config('sand_ai.worker_token',?,true),set_config('sand_ai.worker_fence',?,true)",
            [TaskLeasePolicy::runningTimezone(), $lease->owner, $lease->token, (string) $lease->fence]);
    }

    /** Bind trigger context inside an existing short node-write transaction.
     * A legacy task never enters the new constraints implicitly.
     */
    public function bindEnrolledLease(TaskExecutionLease $lease): bool
    {
        if (!MeetingExecutionRuntime::forTaskId($lease->taskId)) return false;
        $rows = Db::connect('pgsql')->query(
            'SELECT task_id FROM sand_ai_meeting_worker_checkpoint WHERE task_id=? AND state=\'staging\'', [$lease->taskId]);
        if ($rows === []) return false;
        if (count($rows) !== 1) self::invalid();
        $this->assertReady();
        $this->bindLease($lease);
        $this->assertLeaseNow($lease);
        return true;
    }

    private function current(TaskRun $task, TaskExecutionLease $lease): TaskRun
    {
        if ((int) $task->id !== $lease->taskId) self::invalid();
        $current = TaskRun::where('id', $lease->taskId)->lock(true)->find();
        if ($current === null || (int) $current->environment_id !== (int) $task->environment_id) self::invalid();
        $this->assertLeaseNow($lease);
        return $current;
    }

    private function assertLeaseNow(TaskExecutionLease $lease): void
    {
        Db::connect('pgsql')->query('SELECT (sand_ai_meeting_worker_task(?)).id', [$lease->taskId]);
    }

    private function authorize(TaskRun $task): void
    {
        $input = self::input($task);
        $meeting = $input['_meeting_summary'];
        $seal = AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal'] ?? null);
        $aggregation = is_array($meeting['aggregation_source'] ?? null) ? $meeting['aggregation_source'] : null;
        if (($seal === null) === ($aggregation === null)) self::invalid();
        (new TaskRunAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        $attribution = AiTaskGateway::attributionFromPersistedInput($input, (int) $task->environment_id);
        AiTaskGateway::assertCurrentSourceFacts($task, $attribution);
        $identity = UsageAttribution::fromCurrentPersisted($input['_usage_attribution'], (int) $task->environment_id,
            'capability', 'sand_ai.audio.transcription');
        $source = (new MeetingSummarySource())->current((int) $task->environment_id,
            (int) $meeting['audio_task_id'], $identity, (string) $meeting['sha256'], (int) $meeting['review_revision'], $seal, $aggregation);
        if ((int) $source['file_id'] !== (int) $meeting['file_id']) self::invalid();
        (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $source['task']);
    }

    /** @return array<string,mixed> */
    private static function input(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || !is_array($input['_meeting_summary'] ?? null)
            || !is_array($input['iam_authorization'] ?? null) || !is_array($input['_usage_attribution'] ?? null)
            || !is_array($input['_meeting_summary']['audio_authorization'] ?? null)) self::invalid();
        return $input;
    }

    /** Same exact byte contract as RequestPayloadFingerprint; JSONB text is not hashed. */
    private static function encode(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_float($value) || is_object($value) || is_resource($value)) self::invalid();
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $key => $item) $result[$key] = self::canonical($item);
        if (!array_is_list($value)) ksort($result, SORT_STRING);
        return $result;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '摘要持久阶段账本尚未就绪或证据发生变化');
    }
}
