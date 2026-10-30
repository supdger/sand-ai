<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\External\FrozenAudioTaskInputPolicy;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use think\facade\Db;

/** Source-only staging primitive; not wired to a Worker or public API. Requires proposed schema 106. */
final class PostgresAudioChunkManifestStore
{
    /** Caller must prove every chunk_ref is durable private storage before calling this method. */
    public function freeze(int $environmentId, TaskExecutionLease $lease, AudioChunkManifest $manifest): int
    {
        $timezone = TaskLeasePolicy::runningTimezone();
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $manifest, $timezone): int {
            $task = Db::connect('pgsql')->table('sand_ai_task_run')
                ->where('id', $lease->taskId)->where('environment_id', $environmentId)
                ->where('task_type', 'audio_transcription')->where('state', 'running')
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->whereNull('delete_time')
                ->whereNull('content_purged_time')->lock(true)->find();
            if (!is_array($task)) self::leaseLost();
            $this->assertLive($lease, $timezone);
            $input = $task['input'] ?? null;
            if (is_string($input)) $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input)) self::leaseLost();
            FrozenAudioTaskInputPolicy::assert($input, $environmentId);
            if (($input['request']['file_ref'] ?? null) !== $manifest->fileRef
                || ($input['file_media_type'] ?? null) !== $manifest->mediaType
                || !hash_equals((string) ($input['file_sha256'] ?? ''), $manifest->fileSha256)) {
                throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'The manifest differs from the original recording');
            }
            $existing = Db::connect('pgsql')->table('sand_ai_audio_chunk_manifest')
                ->where('task_run_id', $lease->taskId)->where('environment_id', $environmentId)->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['manifest_sha256'], $manifest->fingerprint())) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The frozen audio chunk manifest cannot change');
                }
                return (int) $existing['id'];
            }
            $previousDispatch = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')
                ->where('task_run_id', $lease->taskId)->where('environment_id', $environmentId)->find();
            if (is_array($previousDispatch)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'A previously dispatched recording cannot change to chunked processing');
            }
            $rows = Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_audio_chunk_manifest (environment_id,task_run_id,file_ref,file_sha256,media_type,normalized_sha256,recipe,sample_count,chunk_count,manifest_sha256) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
                [$environmentId, $lease->taskId, $manifest->fileRef, $manifest->fileSha256, $manifest->mediaType,
                    $manifest->normalizedSha256, AudioChunkPlan::RECIPE, $manifest->plan->sampleCount,
                    $manifest->plan->count(), $manifest->fingerprint()],
            );
            $id = (int) ($rows[0]['id'] ?? 0);
            if ($id < 1) throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'The private audio manifest could not be stored');
            foreach ($manifest->chunks as $chunk) {
                $inserted = Db::connect('pgsql')->table('sand_ai_audio_chunk')->insert([
                    'audio_chunk_manifest_id' => $id, 'environment_id' => $environmentId,
                    'task_run_id' => $lease->taskId, 'sequence_no' => $chunk['sequence_no'],
                    'start_sample' => $chunk['start_sample'], 'end_sample' => $chunk['end_sample'],
                    'byte_size' => $chunk['byte_size'], 'chunk_ref' => $chunk['chunk_ref'],
                    'content_sha256' => $chunk['content_sha256'],
                ]);
                if ($inserted !== 1) throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'The private audio manifest could not be stored');
            }
            $this->assertLive($lease, $timezone);
            return $id;
        });
    }

    private function assertLive(TaskExecutionLease $lease, string $timezone): void
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id FROM sand_ai_task_run WHERE id=? AND lease_expire_time > (clock_timestamp() AT TIME ZONE ?)',
            [$lease->taskId, $timezone],
        );
        if ($rows === []) self::leaseLost();
    }

    private static function leaseLost(): never
    {
        throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The audio manifest preparation lease is no longer valid');
    }
}
