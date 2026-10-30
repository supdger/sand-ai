<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Application\Audio\AudioSnapshot;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\AiFile;
use think\facade\Db;

/** Provenance/accounting read only. It cannot accept transcript bytes or totals from the caller. */
final readonly class PostgresAudioChunkAggregation
{
    public function __construct(private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger(),
        private AudioChunkTranscriptAssembler $assembler = new AudioChunkTranscriptAssembler()) {}

    public function assemble(int $environmentId, TaskExecutionLease $lease): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease): array {
            $input = $this->ledger->guard($environmentId, $lease);
            $assembly = $this->collect($environmentId, $lease->taskId, $input);
            // Recheck live lease and current IAM after normalization; read success is not a task terminal write.
            $this->ledger->guard($environmentId, $lease);
            return $assembly;
        });
    }

    /** Caller performs current IAM read authorization; never reuse the expired queued credential for reads. */
    public function read(TaskRun $task): array
    {
        return Db::connect('pgsql')->transaction(function () use ($task): array {
            $current = TaskRun::where('id', (int) $task->id)->where('environment_id', (int) $task->environment_id)
                ->where('task_type', 'audio_transcription')->where('resource_type', 'audio_transcription')
                ->where('resource_id', (int) $task->id)->where('state', TaskState::SUCCEEDED)->where('status', 1)
                ->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            if ($current === null) AudioChunkTranscriptAssembler::invalid();
            $input = AudioChunkDispatchBinding::input($current->input, (int) $current->environment_id);
            $file = AiFile::where('id', $input['file_id'])->where('environment_id', (int) $current->environment_id)
                ->where('file_ref', $input['request']['file_ref'])->where('sha256', $input['file_sha256'])
                ->where('media_type', $input['file_media_type'])->where('owner_scope', $input['_trusted_identity_scope'])
                ->where('state', 'uploaded')->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null) AudioChunkTranscriptAssembler::invalid();
            $assembly = $this->collect((int) $current->environment_id, (int) $current->id, $input);
            $stored = is_object($current->result) ? (array) $current->result : $current->result;
            if (!is_array($stored) || !AudioSnapshot::same($stored, self::stored($assembly))) {
                AudioChunkTranscriptAssembler::invalid();
            }
            return $assembly;
        });
    }

    public static function stored(array $assembly): array
    {
        return $assembly['transcript'] + ['_audio_chunk_aggregation' =>
            ['version' => 'audio_chunk_aggregation@1'] + $assembly['evidence']];
    }

    public static function marked(mixed $result): bool
    {
        if (is_object($result)) $result = (array) $result;
        return is_array($result) && array_key_exists('_audio_chunk_aggregation', $result);
    }

    /** Parent row must be locked; immutable clip facts are read under that purge/cancel fence. */
    private function collect(int $environmentId, int $taskId, array $input): array
    {
        $manifest = Db::connect('pgsql')->table('sand_ai_audio_chunk_manifest')->where('environment_id', $environmentId)
            ->where('task_run_id', $taskId)->find();
        if (!is_array($manifest)) AudioChunkTranscriptAssembler::invalid();
        $check = Db::connect('pgsql')->query(
            "SELECT count(c.id) AS chunks,count(d.id) FILTER (WHERE d.state='succeeded' AND d.content_purged_time IS NULL) AS completed,"
            . "COALESCE(sum(octet_length(d.terminal_snapshot::text)),0) AS bytes "
            . 'FROM sand_ai_audio_chunk c LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id '
            . 'WHERE c.audio_chunk_manifest_id=? AND c.environment_id=? AND c.task_run_id=?',
            [(int) $manifest['id'], $environmentId, $taskId],
        );
        if (count($check) !== 1 || (int) $check[0]['chunks'] !== (int) $manifest['chunk_count']
            || (int) $check[0]['completed'] !== (int) $manifest['chunk_count']) AudioChunkTranscriptAssembler::pending();
        if ((int) $check[0]['bytes'] > AudioChunkTranscriptAssembler::MAX_BYTES) AudioChunkTranscriptAssembler::invalid();
        $chunks = Db::connect('pgsql')->table('sand_ai_audio_chunk')->where('audio_chunk_manifest_id', $manifest['id'])
            ->where('environment_id', $environmentId)->where('task_run_id', $taskId)->order('sequence_no')->select();
        $rows = [];
        // All participating records are immutable succeeded dispatch/Usage facts.
        // Parent task lock prevents a concurrent purge; no reverse budget locks are acquired.
        foreach ($chunks as $chunk) {
            $dispatch = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('audio_chunk_id', $chunk['id'])
                ->where('environment_id', $environmentId)->where('task_run_id', $taskId)
                ->where('state', 'succeeded')->whereNull('content_purged_time')->find();
            if (!is_array($dispatch)) AudioChunkTranscriptAssembler::pending();
            $usage = Db::connect('pgsql')->table('sand_ai_usage_event')->where('external_audio_dispatch_id', $dispatch['id'])
                ->where('audio_task_run_id', $taskId)->where('environment_id', $environmentId)->find();
            if (!is_array($usage)) AudioChunkTranscriptAssembler::invalid();
            $rows[] = ['chunk' => $chunk, 'dispatch' => $dispatch, 'usage' => $usage];
        }
        return $this->assembler->assemble($input, $manifest, $rows);
    }
}
