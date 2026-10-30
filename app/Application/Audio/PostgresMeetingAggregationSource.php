<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Audio\Chunk\AudioChunkRuntimeSchema;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\AiTaskGateway;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\FileInvocationFacts;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Private aggregation source. Body reads are direct positional extraction, never a transcript projection.
 *
 * The compact digest belongs to its own domain. It is NOT the old ordered transcript SHA.
 * Eligibility is deliberately deferred until the durable planner has covered every position.
 * A binding is generated from persisted facts, not supplied by an API caller. Checkpoint enrollment
 * must compare it to these same facts. This reader does not claim a seal or completed coverage.
 */
final class PostgresMeetingAggregationSource
{
    public const VERSION = 'meeting_aggregation_source@1';
    public const SHA_ALGORITHM = 'sha256/aggregation-review-anchor@1';
    private const ROW_BYTES = 65536;
    private const PAGE_BYTES = 4194304;
    private const META_BYTES = 4194304;

    /** Server submission entry: no complete audio result/segment array is loaded.
     * @return array<string,mixed>
     */
    public function compact(int $environmentId, int $audioTaskId, UsageAttribution $identity, int $reviewRevision = 0): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $audioTaskId, $identity, $reviewRevision): array {
            return $this->facts($environmentId, $audioTaskId, $identity, $reviewRevision);
        });
    }

    /** Compatibility only; the new durable submission path calls compact() directly. */
    public static function freezeFromVerifiedSource(TaskRun $audio, array $verifiedSource): array
    {
        $input = self::decode($audio->input);
        $identity = UsageAttribution::fromCurrentPersisted($input['usage_attribution'] ?? null,
            (int) $audio->environment_id, 'capability', 'sand_ai.audio.transcription');
        $facts = (new self())->compact((int) $audio->environment_id, (int) $audio->id, $identity,
            (int) ($verifiedSource['review_revision'] ?? 0));
        // The returned digest is explicitly the compact domain, even when the compatibility
        // caller previously validated the old ordered digest. Never label the two equal.
        return $facts['aggregation_source'];
    }

    public function current(int $environmentId, int $audioTaskId, UsageAttribution $identity,
        string $expectedHash, int $reviewRevision, array $binding): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $audioTaskId, $identity,
            $expectedHash, $reviewRevision, $binding): array {
            $facts = $this->facts($environmentId, $audioTaskId, $identity, $reviewRevision, $binding);
            if (!RequestPayloadFingerprint::same($facts['aggregation_source'], $binding)
                || !hash_equals($facts['sha256'], $expectedHash)) self::invalid();
            return $facts;
        });
    }

    /** Lightweight current authorization; valid before checkpoint/plan enrollment.
     * No private segment is returned and no IAM credential is renewed.
     */
    public function authorize(TaskRun $summaryTask, TaskExecutionLease $lease): array
    {
        return Db::connect('pgsql')->transaction(fn (): array => $this->authorized($summaryTask, $lease));
    }

    /** @param list<int> $positions strictly ascending, 1..32 positions, 0..9999
     * @return array{sources:list<array{segment_index:int,proof:array<string,mixed>,private_segment:array<string,mixed>}>}
     */
    public function readPositions(TaskRun $summaryTask, TaskExecutionLease $lease, array $positions): array
    {
        self::positions($positions);
        return Db::connect('pgsql')->transaction(function () use ($summaryTask, $lease, $positions): array {
            $facts = $this->authorized($summaryTask, $lease);
            foreach ($positions as $position) if ($position >= $facts['total_segments']) self::invalid();
            $rows = $this->segments($facts, $positions);
            if (count($rows) !== count($positions)) self::invalid();
            $sources = []; $bytes = 0;
            foreach ($rows as $index => $row) {
                if ((int) $row['position_no'] !== $positions[$index]) self::invalid();
                foreach (['aggregate_segment', 'clip_segment'] as $field) {
                    if (!is_string($row[$field]) || strlen($row[$field]) > self::ROW_BYTES) self::invalid();
                    $bytes += strlen($row[$field]);
                }
                if ($bytes > self::PAGE_BYTES) self::invalid();
                $segment = self::decode($row['aggregate_segment']);
                $clip = self::decode($row['clip_segment']);
                $this->verifySegment($segment, $clip, $row, $facts);
                if ($row['review_revision'] !== null) {
                    $review = $row['review_text'];
                    if (!is_string($review) || !mb_check_encoding($review, 'UTF-8') || mb_strlen($review, 'UTF-8') > 10000) self::invalid();
                    $segment['text'] = trim($review);
                }
                if ($row['speaker_revision'] !== null) $segment['speaker'] = $row['review_speaker'];
                $speaker = $segment['speaker'];
                if ($speaker !== null && (!is_string($speaker) || preg_match('/\Aspeaker_[1-9][0-9]{0,3}\z/D', $speaker) !== 1)) self::invalid();
                $private = ['text' => $segment['text'], 'speaker' => $speaker,
                    'start_ms' => $segment['start_ms'], 'end_ms' => $segment['end_ms'], 'source' => $segment['source']];
                $characters = mb_strlen($private['text'], 'UTF-8');
                $proof = ['segment_id' => 'S' . ($positions[$index] + 1), 'text_sha256' => hash('sha256', $private['text']),
                    'unicode_characters' => $characters, 'source_sha256' => RequestPayloadFingerprint::fingerprint([
                        'speaker' => $speaker, 'start_ms' => $private['start_ms'], 'end_ms' => $private['end_ms'],
                        'source' => $private['source']], []),
                    'exclusion' => trim($private['text']) === '' ? 'no_usable_text' : null];
                $sources[] = ['segment_index' => $positions[$index], 'proof' => $proof, 'private_segment' => $private];
            }
            // The row locks fence purge while both authorization checks use current SandIAM.
            $after = $this->authorized($summaryTask, $lease);
            if (!RequestPayloadFingerprint::same($facts['aggregation_source'], $after['aggregation_source'])) self::invalid();
            return ['sources' => $sources];
        });
    }

    private function authorized(TaskRun $summaryTask, TaskExecutionLease $lease): array
    {
        if ((int) $summaryTask->id !== $lease->taskId) self::lost();
        $task = TaskRun::where('id', $lease->taskId)->where('environment_id', (int) $summaryTask->environment_id)
            ->field('id,environment_id,task_type,resource_type,resource_id,state,status,delete_time,content_purged_time,input,lease_owner,lease_token,lease_fence,lease_expire_time,data_class')
            ->lock(true)->find();
        if ($task === null || (string) $task->state !== 'running' || (int) $task->status !== 1
            || $task->delete_time !== null || $task->content_purged_time !== null
            || $task->lease_owner !== $lease->owner || $task->lease_token !== $lease->token
            || (int) $task->lease_fence !== $lease->fence) self::lost();
        $this->live($lease);
        $input = self::decode($task->input);
        $meeting = $input['_meeting_summary'] ?? null;
        if (!is_array($meeting) || !is_array($meeting['aggregation_source'] ?? null)
            || !is_array($meeting['audio_authorization'] ?? null) || !is_array($input['iam_authorization'] ?? null)
            || !is_int($meeting['audio_task_id'] ?? null) || !is_int($meeting['review_revision'] ?? null)
            || !is_int($meeting['file_id'] ?? null) || !is_string($meeting['sha256'] ?? null)) self::invalid();
        (new TaskRunAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        $attribution = AiTaskGateway::attributionFromPersistedInput($input, (int) $task->environment_id);
        AiTaskGateway::assertCurrentSourceFacts($task, $attribution);
        $identity = UsageAttribution::fromCurrentPersisted($input['_usage_attribution'] ?? null,
            (int) $task->environment_id, 'capability', 'sand_ai.audio.transcription');
        $facts = $this->current((int) $task->environment_id, $meeting['audio_task_id'], $identity,
            $meeting['sha256'], $meeting['review_revision'], $meeting['aggregation_source']);
        if ($facts['file_id'] !== $meeting['file_id'] || $facts['total_segments'] !== ($meeting['total_segments'] ?? null)) self::invalid();
        (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $facts['task']);
        $this->live($lease);
        return $facts;
    }

    private function live(TaskExecutionLease $lease): void
    {
        $rows = Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? AND state=\'running\'
            AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL
            AND lease_owner=? AND lease_token=? AND lease_fence=?
            AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)',
            [$lease->taskId, $lease->owner, $lease->token, $lease->fence, TaskLeasePolicy::runningTimezone()]);
        if (count($rows) !== 1) self::lost();
    }

    /** Only compact metadata is selected: never audio.result or a terminal_snapshot as a whole. */
    private function facts(int $environmentId, int $audioTaskId, UsageAttribution $identity, int $reviewRevision, ?array $frozen = null): array
    {
        AudioChunkRuntimeSchema::assertReady();
        $this->assertImmutableRuntime();
        if ($environmentId < 1 || $audioTaskId < 1 || $reviewRevision < 0 || $reviewRevision > 10000) self::invalid();
        $task = TaskRun::withTrashed()->where('id', $audioTaskId)->where('environment_id', $environmentId)
            ->field('id,environment_id,task_type,resource_type,resource_id,state,status,delete_time,content_purged_time,input,version')
            ->fieldRaw('xmin::text AS source_task_xmin')->lock(true)->find();
        if ($task === null || $task->task_type !== 'audio_transcription' || $task->resource_type !== 'audio_transcription'
            || (int) $task->resource_id !== $audioTaskId || $task->state !== 'succeeded' || (int) $task->status !== 1
            || $task->delete_time !== null || $task->content_purged_time !== null) self::invalid();
        $input = self::decode($task->input); $request = $input['request'] ?? null;
        if (!is_array($request) || !is_int($input['file_id'] ?? null) || !is_string($input['file_sha256'] ?? null)
            || !is_string($request['file_ref'] ?? null) || !in_array($request['data_egress_policy'] ?? null, ['local_only', 'private_only', 'approved_cloud'], true)) self::invalid();
        $owner = UsageAttribution::fromCurrentPersisted($input['usage_attribution'] ?? null, $environmentId,
            'capability', 'sand_ai.audio.transcription');
        if ($owner->canonicalTrustedIdentity() !== $identity->canonicalTrustedIdentity()) self::invalid();
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($owner);
        $file = Db::connect('pgsql')->table('sand_ai_file')->where('id', $input['file_id'])->where('environment_id', $environmentId)
            ->where('file_ref', $request['file_ref'])->where('sha256', $input['file_sha256'])->where('owner_scope', $scope)
            ->where('state', 'uploaded')->where('status', 1)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($file)) self::invalid();
        $fileOwner = self::decode($file['owner_attribution']);
        $fileFacts = FileInvocationFacts::fromPersisted(['id' => $file['id'], 'status' => $file['status'],
            'delete_time' => $file['delete_time'], 'state' => $file['state'], 'environment_id' => $file['environment_id'],
            'owner_scope' => $file['owner_scope'], 'owner_attribution' => $fileOwner], $input['file_id']);
        if ($fileFacts['organization_id'] !== $identity->organizationId || $fileFacts['application_id'] !== $identity->applicationId
            || $fileFacts['workload_client_id'] !== $identity->workloadClientId) self::invalid();
        $anchors = Db::connect('pgsql')->query("SELECT m.id AS manifest_id,m.manifest_sha256,m.chunk_count,m.file_ref,m.file_sha256,
            a.result->'_audio_chunk_aggregation' AS marker,jsonb_array_length(a.result->'segments') AS total_segments
            FROM sand_ai_task_run a JOIN sand_ai_audio_chunk_manifest m ON m.task_run_id=a.id AND m.environment_id=a.environment_id
            WHERE a.id=? AND a.environment_id=?", [$audioTaskId, $environmentId]);
        if (count($anchors) !== 1) self::invalid();
        $anchor = $anchors[0]; $marker = self::decode($anchor['marker']);
        $total = (int) $anchor['total_segments']; $chunks = (int) $anchor['chunk_count'];
        if ($total < 1 || $total > 10000 || $chunks < 1 || $chunks > 480 || ($marker['version'] ?? null) !== 'audio_chunk_aggregation@1'
            || ($marker['chunk_count'] ?? null) !== $chunks || ($marker['manifest_sha256'] ?? null) !== $anchor['manifest_sha256']
            || !is_array($marker['dispatch_ids'] ?? null) || !is_array($marker['usage_event_ids'] ?? null)
            || $anchor['file_ref'] !== $request['file_ref'] || $anchor['file_sha256'] !== $input['file_sha256']) self::invalid();
        self::sha($marker['transcript_sha256'] ?? null); self::sha($anchor['manifest_sha256']);
        if ($frozen === null) {
            $index = $this->index($environmentId, $audioTaskId, (int) $anchor['manifest_id'], $chunks, $total);
            if (($marker['dispatch_ids'] ?? null) !== array_column($index, 'dispatch_id')
                || ($marker['usage_event_ids'] ?? null) !== array_column($index, 'usage_event_id')) self::invalid();
            $indexHash = RequestPayloadFingerprint::fingerprint($index, []);
            $reviews = $this->reviewAnchor($audioTaskId, $reviewRevision, $total, $scope);
        } else {
            // Frozen input was generated by compact() at authenticated submission and is
            // sealed in the durable source anchor. 072 forbids review DELETE and permits
            // body purge only after this locked audio parent is purged. The exact immutable
            // historical endpoint therefore suffices; no node/tick rereads the whole chain.
            self::sha($frozen['index_sha256'] ?? null);
            self::sha($frozen['review_chain_sha256'] ?? null);
            $indexHash = $frozen['index_sha256'];
            $reviews = $this->currentReviewAnchor($audioTaskId, $reviewRevision, $scope, $frozen);
        }
        $binding = ['version' => self::VERSION, 'sha_algorithm' => self::SHA_ALGORITHM,
            'audio_task_id' => $audioTaskId, 'task_version' => (int) $task->version,
            'task_xmin' => (string) $task->getAttr('source_task_xmin'), 'manifest_id' => (int) $anchor['manifest_id'],
            'manifest_sha256' => $anchor['manifest_sha256'], 'completion_sha256' => $marker['transcript_sha256'],
            'chunk_count' => $chunks, 'total_segments' => $total, 'index_sha256' => $indexHash,
            'review_revision' => $reviewRevision, 'review_chain_sha256' => $reviews['sha256'],
            'review_base_sha256' => $reviews['base_sha256'], 'review_last_id' => $reviews['last_id'],
            'review_last_xmin' => $reviews['last_xmin'], 'owner_scope' => $scope];
        return ['task' => $task, 'file_id' => $input['file_id'], 'file_ref' => $request['file_ref'],
            'sha256' => RequestPayloadFingerprint::fingerprint($binding, []), 'data_class' => $fileFacts['data_class'],
            'data_egress_policy' => $request['data_egress_policy'], 'review_revision' => $reviewRevision,
            'total_segments' => $total, 'eligible_segments' => null, 'transcript_seal' => null,
            'aggregation_source' => $binding];
    }

    /** At most 480 immutable scalar records; no segment text is selected. */
    private function index(int $environmentId, int $audioTaskId, int $manifestId, int $chunks, int $total): array
    {
        $rows = Db::connect('pgsql')->query("SELECT c.id AS chunk_id,c.sequence_no,c.start_sample,c.end_sample,c.content_sha256,
            d.id AS dispatch_id,d.xmin::text AS dispatch_xmin,u.id AS usage_event_id,u.xmin::text AS usage_xmin,
            jsonb_array_length(d.terminal_snapshot->'result'->'segments') AS segments
            FROM sand_ai_audio_chunk c JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id
                AND d.task_run_id=c.task_run_id AND d.environment_id=c.environment_id
            JOIN sand_ai_usage_event u ON u.external_audio_dispatch_id=d.id AND u.audio_task_run_id=c.task_run_id AND u.environment_id=c.environment_id
            WHERE c.audio_chunk_manifest_id=? AND c.environment_id=? AND c.task_run_id=?
                AND d.state='succeeded' AND d.content_purged_time IS NULL
            ORDER BY c.sequence_no LIMIT 481", [$manifestId, $environmentId, $audioTaskId]);
        if (count($rows) !== $chunks) self::invalid();
        $index = []; $offset = 0;
        foreach ($rows as $i => $row) {
            $count = (int) $row['segments'];
            if ((int) $row['sequence_no'] !== $i + 1 || $count < 1 || $count > 10000 || $offset + $count > 10000) self::invalid();
            $index[] = ['sequence_no' => $i + 1, 'chunk_id' => (int) $row['chunk_id'],
                'dispatch_id' => (int) $row['dispatch_id'], 'dispatch_xmin' => (string) $row['dispatch_xmin'],
                'usage_event_id' => (int) $row['usage_event_id'], 'usage_xmin' => (string) $row['usage_xmin'],
                'start_sample' => (int) $row['start_sample'], 'end_sample' => (int) $row['end_sample'],
                'content_sha256' => $row['content_sha256'], 'offset' => $offset, 'segments' => $count];
            $offset += $count;
        }
        if ($offset !== $total) self::invalid();
        return $index;
    }

    /** First capture only: 072 forbids DELETE and permits body purge only after parent purge.
     * Bounded metadata proves all revisions/owners/indices; no review text is selected.
     */
    private function reviewAnchor(int $audioTaskId, int $revision, int $total, string $owner): array
    {
        if ($revision === 0) return ['sha256' => RequestPayloadFingerprint::fingerprint(['domain' => 'audio_review_history@1', 'rows' => []], []), 'base_sha256' => null, 'last_id' => null, 'last_xmin' => null];
        $rows = Db::connect('pgsql')->query('SELECT id,xmin::text AS row_xmin,revision,segment_index,base_sha256,owner_scope
            FROM sand_ai_audio_segment_review WHERE audio_task_id=? AND revision<=? ORDER BY revision LIMIT 10001', [$audioTaskId, $revision]);
        if (count($rows) !== $revision) self::invalid();
        $history = []; $base = null;
        foreach ($rows as $i => $row) {
            if ((int) $row['revision'] !== $i + 1 || (int) $row['segment_index'] < 0 || (int) $row['segment_index'] >= $total
                || $row['owner_scope'] !== $owner) self::invalid();
            self::sha($row['base_sha256']); $base ??= $row['base_sha256'];
            if ($base !== $row['base_sha256']) self::invalid();
            $history[] = ['id' => (int) $row['id'], 'xmin' => (string) $row['row_xmin'], 'revision' => $i + 1,
                'segment_index' => (int) $row['segment_index'], 'base_sha256' => $row['base_sha256'], 'owner_scope' => $owner];
        }
        $encoded = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::META_BYTES) self::invalid();
        return ['sha256' => RequestPayloadFingerprint::fingerprint(['domain' => 'audio_review_history@1', 'rows' => $history], []), 'base_sha256' => $base,
            'last_id' => (int) $rows[$revision - 1]['id'], 'last_xmin' => (string) $rows[$revision - 1]['row_xmin']];
    }

    private function currentReviewAnchor(int $audioTaskId, int $revision, string $owner, array $frozen): array
    {
        if ($revision === 0) {
            $empty = $this->reviewAnchor($audioTaskId, 0, 0, $owner);
            if (($frozen['review_base_sha256'] ?? null) !== null || ($frozen['review_last_id'] ?? null) !== null
                || ($frozen['review_last_xmin'] ?? null) !== null
                || !hash_equals($empty['sha256'], $frozen['review_chain_sha256'])) self::invalid();
            return $empty;
        }
        if (!is_int($frozen['review_last_id'] ?? null) || $frozen['review_last_id'] < 1
            || !is_string($frozen['review_last_xmin'] ?? null)) self::invalid();
        self::sha($frozen['review_base_sha256'] ?? null);
        $rows = Db::connect('pgsql')->query('SELECT id,xmin::text AS row_xmin,base_sha256,owner_scope,content_purged_time
            FROM sand_ai_audio_segment_review WHERE audio_task_id=? AND revision=?', [$audioTaskId, $revision]);
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== $frozen['review_last_id']
            || $rows[0]['row_xmin'] !== $frozen['review_last_xmin'] || $rows[0]['base_sha256'] !== $frozen['review_base_sha256']
            || $rows[0]['owner_scope'] !== $owner || $rows[0]['content_purged_time'] !== null) self::invalid();
        return ['sha256' => $frozen['review_chain_sha256'], 'base_sha256' => $frozen['review_base_sha256'],
            'last_id' => $frozen['review_last_id'], 'last_xmin' => $frozen['review_last_xmin']];
    }

    /** Extract each requested aggregate/immutable clip segment directly with JSONB -> integer.
     * Window sums scan only bounded clip counts (<=480), never jsonb_array_elements of transcript bodies.
     */
    private function segments(array $facts, array $positions): array
    {
        $binding = $facts['aggregation_source'];
        return Db::connect('pgsql')->query("WITH clip_counts AS (
            SELECT c.id,c.sequence_no,c.start_sample,c.end_sample,c.chunk_ref,c.content_sha256,d.id AS dispatch_id,
                jsonb_array_length(d.terminal_snapshot->'result'->'segments') AS segment_count
            FROM sand_ai_audio_chunk c JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id
                AND d.task_run_id=c.task_run_id AND d.environment_id=c.environment_id
            WHERE c.audio_chunk_manifest_id=? AND c.task_run_id=? AND c.environment_id=? AND d.state='succeeded' AND d.content_purged_time IS NULL
        ), ranges AS (
            SELECT *,COALESCE(sum(segment_count) OVER (ORDER BY sequence_no ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0)::int AS start_position
            FROM clip_counts
        ), requested AS (SELECT value::int AS position_no FROM jsonb_array_elements_text(?::jsonb))
        SELECT p.position_no,
            CASE WHEN octet_length((a.result->'segments'->p.position_no)::text)<=65536
                THEN (a.result->'segments'->p.position_no)::text ELSE NULL END AS aggregate_segment,
            CASE WHEN octet_length((d.terminal_snapshot->'result'->'segments'->(p.position_no-r.start_position))::text)<=65536
                THEN (d.terminal_snapshot->'result'->'segments'->(p.position_no-r.start_position))::text ELSE NULL END AS clip_segment,
            r.start_sample,r.end_sample,r.chunk_ref,r.content_sha256,
            correction.revision AS review_revision,correction.review_text,
            speaker.revision AS speaker_revision,speaker.review_speaker
        FROM requested p JOIN ranges r ON p.position_no>=r.start_position AND p.position_no<r.start_position+r.segment_count
        JOIN sand_ai_external_audio_dispatch d ON d.id=r.dispatch_id
        JOIN sand_ai_task_run a ON a.id=? AND a.environment_id=?
        LEFT JOIN LATERAL (SELECT revision,review_text FROM sand_ai_audio_segment_review
            WHERE audio_task_id=a.id AND segment_index=p.position_no AND revision<=? ORDER BY revision DESC LIMIT 1) correction ON true
        LEFT JOIN LATERAL (SELECT revision,review_speaker FROM sand_ai_audio_segment_review
            WHERE audio_task_id=a.id AND segment_index=p.position_no AND revision<=? AND speaker_changed ORDER BY revision DESC LIMIT 1) speaker ON true
        ORDER BY p.position_no", [$binding['manifest_id'], $binding['audio_task_id'], (int) $facts['task']->environment_id,
            json_encode($positions, JSON_THROW_ON_ERROR), $binding['audio_task_id'], (int) $facts['task']->environment_id,
            $binding['review_revision'], $binding['review_revision']]);
    }

    private function verifySegment(array $segment, array $clip, array $row, array $facts): void
    {
        foreach ([$segment, $clip] as $value) {
            if (!is_string($value['text'] ?? null) || !mb_check_encoding($value['text'], 'UTF-8')
                || mb_strlen($value['text'], 'UTF-8') > 10000 || !is_int($value['start_ms'] ?? null)
                || !is_int($value['end_ms'] ?? null) || $value['start_ms'] < 0 || $value['end_ms'] <= $value['start_ms']
                || ($value['speaker'] ?? null) !== null || !is_array($value['source'] ?? null)) self::invalid();
        }
        $start = intdiv((int) $row['start_sample'] * 1000, 16000);
        $end = intdiv((int) $row['end_sample'] * 1000 + 15999, 16000);
        $source = $clip['source']; $locator = $source['locator'] ?? null;
        if (!is_array($locator) || ($source['file_ref'] ?? null) !== $facts['file_ref'] || ($source['content_sha256'] ?? null) !== $row['content_sha256']
            || ($locator['type'] ?? null) !== 'audio_time_range' || ($locator['start_ms'] ?? null) !== $clip['start_ms'] || ($locator['end_ms'] ?? null) !== $clip['end_ms']
            || $clip['end_ms'] > $end - $start || trim($clip['text']) !== $segment['text']
            || $segment['start_ms'] !== $start + $clip['start_ms'] || $segment['end_ms'] !== $start + $clip['end_ms']) self::invalid();
        $expected = ['file_ref' => $facts['file_ref'], 'content_sha256' => self::decode($facts['task']->input)['file_sha256'],
            'locator' => ['type' => 'audio_time_range', 'start_ms' => $segment['start_ms'], 'end_ms' => $segment['end_ms']]];
        $granularity = $locator['granularity'] ?? null;
        if ($granularity !== null) {
            if (!in_array($granularity, ['engine_segment','input_range_only'], true)) self::invalid();
            $expected['locator']['granularity'] = $granularity;
        } elseif ((self::decode($facts['task']->input)['execution_driver']['code'] ?? null) === 'glm_asr_audio') {
            $expected['locator']['granularity'] = 'input_range_only';
        }
        if (!RequestPayloadFingerprint::same($segment['source'], $expected)) self::invalid();
    }

    private function assertImmutableRuntime(): void
    {
        foreach (['202609260072_audio_content_purge', '202609280093_multiscope_budget_groups'] as $version) {
            $path = base_path('plugin/sand-ai/migrations/main-app/' . $version . '.pgsql');
            $checksum = is_file($path) ? hash_file('sha256', $path) : false;
            $actual = Db::connect('pgsql')->table('sand_ai_schema_migration')->where('version', $version)->value('checksum');
            if (!is_string($checksum) || !is_string($actual) || !hash_equals($checksum, $actual)) self::invalid();
        }
        $rows = Db::connect('pgsql')->query("SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgenabled IN ('O','A')
            AND ((tgrelid=to_regclass('sand_ai_external_audio_dispatch') AND tgname='sand_ai_external_audio_dispatch_immutable_trigger_v031' AND (tgtype & 16)=16)
            OR (tgrelid=to_regclass('sand_ai_audio_segment_review') AND tgname='sand_ai_audio_segment_review_immutable' AND (tgtype & 24)=24)
            OR (tgrelid=to_regclass('sand_ai_usage_event') AND tgname='trg_sand_ai_usage_event_immutable' AND (tgtype & 24)=24))");
        if (array_diff(['sand_ai_external_audio_dispatch_immutable_trigger_v031','sand_ai_audio_segment_review_immutable',
            'trg_sand_ai_usage_event_immutable'], array_column($rows, 'tgname')) !== []) self::invalid();
    }

    private static function positions(array $positions): void
    {
        if (!array_is_list($positions) || $positions === [] || count($positions) > 32) self::invalid();
        $previous = -1;
        foreach ($positions as $position) {
            if (!is_int($position) || $position <= $previous || $position < 0 || $position >= 10000) self::invalid();
            $previous = $position;
        }
    }

    private static function decode(mixed $value): array
    {
        if (is_object($value)) $value = (array) $value;
        if (is_string($value)) $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) self::invalid();
        return $value;
    }

    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) self::invalid();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The immutable aggregation source is unavailable or changed');
    }

    private static function lost(): never
    {
        throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting source read lease is unavailable');
    }
}
