<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\External\FrozenAudioTaskInputPolicy;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use think\facade\Db;

/** Durable local-object ownership. No filesystem I/O occurs while database locks are held. */
final class PostgresAudioChunkPreparationLedger
{
    /** @return array<string,mixed> */
    public function begin(int $environmentId, TaskExecutionLease $lease, VerifiedPrivateAudioReference $source,
        AudioChunkPlan $plan, string $normalizedSha256, string $storeCode, string $rootFingerprint, int $capacityBytes): array
    {
        if ($capacityBytes < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $rootFingerprint) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $normalizedSha256) !== 1) self::invalid();
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $source, $plan, $normalizedSha256, $storeCode, $rootFingerprint, $capacityBytes): array {
            // One short transaction serializes reservations per explicitly provisioned store.
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(106108, hashtext(?))', [$storeCode]);
            $this->registerStore($storeCode, $rootFingerprint);
            $input = $this->guard($environmentId, $lease);
            if ($input['request']['file_ref'] !== $source->fileRef || $input['file_sha256'] !== $source->contentSha256
                || $input['file_media_type'] !== $source->mediaType || $input['_trusted_identity_scope'] !== $source->trustedScope) self::invalid();
            $existing = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('task_run_id', $lease->taskId)->find();
            if (is_array($existing)) {
                if ((int) $existing['environment_id'] !== $environmentId || $existing['store_code'] !== $storeCode || $existing['store_root_sha256'] !== $rootFingerprint
                    || $existing['normalized_sha256'] !== $normalizedSha256 || (int) $existing['sample_count'] !== $plan->sampleCount
                    || !in_array($existing['state'], ['preparing', 'sealed'], true)) self::invalid();
                return $existing;
            }
            $usage = Db::connect('pgsql')->query("SELECT COALESCE(sum(reserved_bytes),0) AS bytes FROM sand_ai_audio_chunk_preparation WHERE store_code=? AND state<>'cleaned'", [$storeCode]);
            $reserved = 44 + $plan->sampleCount * 4 + $plan->count() * 44;
            if ($reserved > $capacityBytes || (int) $usage[0]['bytes'] > $capacityBytes - $reserved) {
                throw new ApiProblem('SAND_AI_AUDIO_CHUNK_CAPACITY_EXCEEDED', 'Private audio processing storage is full', true);
            }
            $ids = Db::connect('pgsql')->query('INSERT INTO sand_ai_audio_chunk_preparation (environment_id,task_run_id,store_code,store_root_sha256,file_ref,file_sha256,normalized_sha256,sample_count,reserved_bytes) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id',
                [$environmentId, $lease->taskId, $storeCode, $rootFingerprint, $source->fileRef, $source->contentSha256, $normalizedSha256, $plan->sampleCount, $reserved]);
            $id = (int) $ids[0]['id'];
            for ($sequence = 0; $sequence <= $plan->count(); $sequence++) {
                $bytes = $sequence === 0 ? 44 + $plan->sampleCount * 2 : $plan->chunk($sequence)['byte_size'];
                Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->insert([
                    'audio_chunk_preparation_id' => $id, 'sequence_no' => $sequence,
                    'chunk_ref' => 'saac_' . bin2hex(random_bytes(16)), 'byte_size' => $bytes,
                    'content_sha256' => $sequence === 0 ? $normalizedSha256 : null,
                ]);
            }
            $this->guard($environmentId, $lease);
            return $this->preparation($environmentId, $lease->taskId);
        });
    }

    /** @return array<string,mixed> */
    public function preparation(int $environmentId, int $taskId): array
    {
        $row = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('environment_id', $environmentId)->where('task_run_id', $taskId)->find();
        if (!is_array($row)) self::invalid();
        return $row;
    }

    /** @return array<string,mixed> */
    public function object(int $preparationId, int $sequence): array
    {
        $row = Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('audio_chunk_preparation_id', $preparationId)->where('sequence_no', $sequence)->find();
        if (!is_array($row)) self::invalid();
        return $row;
    }

    /** Must be called under the backend's object lock, before any possible filesystem write. */
    public function bind(int $environmentId, TaskExecutionLease $lease, int $sequence, string $sha256): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) self::invalid();
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $sequence, $sha256): array {
            $this->guard($environmentId, $lease);
            $prep = $this->preparation($environmentId, $lease->taskId);
            if ($prep['state'] !== 'preparing') self::invalid();
            $object = $this->object((int) $prep['id'], $sequence);
            if (!in_array($object['state'], ['allocated', 'ready'], true)
                || ($object['content_sha256'] !== null && $object['content_sha256'] !== $sha256)) self::invalid();
            if ($object['content_sha256'] === null) {
                Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('id', $object['id'])
                    ->whereNull('content_sha256')->update(['content_sha256' => $sha256]);
            }
            return $this->object((int) $prep['id'], $sequence);
        });
    }

    public function ready(int $environmentId, TaskExecutionLease $lease, int $objectId): void
    {
        Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $objectId): void {
            $this->guard($environmentId, $lease);
            $prep = $this->preparation($environmentId, $lease->taskId);
            if ($prep['state'] !== 'preparing') self::invalid();
            $object = Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('id', $objectId)->where('audio_chunk_preparation_id', $prep['id'])->find();
            if (!is_array($object) || $object['content_sha256'] === null || !in_array($object['state'], ['allocated', 'ready'], true)) self::invalid();
            if ($object['state'] === 'allocated') Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('id', $objectId)->where('state', 'allocated')->update(['state' => 'ready']);
        });
    }

    public function seal(int $environmentId, TaskExecutionLease $lease, AudioChunkManifest $manifest): int
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $manifest): int {
            $this->guard($environmentId, $lease);
            $prep = $this->preparation($environmentId, $lease->taskId);
            if (!in_array($prep['state'], ['preparing', 'sealed'], true) || $prep['normalized_sha256'] !== $manifest->normalizedSha256
                || (int) $prep['sample_count'] !== $manifest->plan->sampleCount) self::invalid();
            for ($sequence = 0; $sequence <= $manifest->plan->count(); $sequence++) {
                $object = $this->object((int) $prep['id'], $sequence);
                if ($object['state'] !== 'ready') self::invalid();
                if ($sequence > 0 && ($object['chunk_ref'] !== $manifest->chunks[$sequence - 1]['chunk_ref']
                    || $object['content_sha256'] !== $manifest->chunks[$sequence - 1]['content_sha256']
                    || (int) $object['byte_size'] !== $manifest->chunks[$sequence - 1]['byte_size'])) self::invalid();
            }
            $manifestId = (new PostgresAudioChunkManifestStore())->freeze($environmentId, $lease, $manifest);
            if ($prep['state'] === 'preparing') Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('id', $prep['id'])->where('state', 'preparing')->update(['state' => 'sealed']);
            return $manifestId;
        });
    }

    /** The same task lock serializes cleanup tombstones with bind/ready/freeze. No object I/O here. */
    public function requestCleanup(int $environmentId, int $taskId): bool
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $taskId): bool {
            $task = Db::connect('pgsql')->table('sand_ai_task_run')->where('id', $taskId)->where('environment_id', $environmentId)->lock(true)->find();
            if (!is_array($task) || ($task['content_purged_time'] === null && $task['delete_time'] === null
                && $task['state'] !== 'canceled')) return false;
            $prep = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('environment_id', $environmentId)->where('task_run_id', $taskId)->find();
            if (!is_array($prep) || $prep['state'] === 'cleaned') return false;
            Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('id', $prep['id'])->whereIn('state', ['preparing', 'sealed'])->update(['state' => 'cleaning']);
            return true;
        });
    }

    /** Called under the same local object lock as writes, after the physical file has been removed. */
    public function deleted(int $preparationId, int $objectId): void
    {
        Db::connect('pgsql')->transaction(function () use ($preparationId, $objectId): void {
            $probe = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('id', $preparationId)->find();
            if (!is_array($probe)) self::invalid();
            Db::connect('pgsql')->table('sand_ai_task_run')->where('id', $probe['task_run_id'])->where('environment_id', $probe['environment_id'])->lock(true)->find();
            $prep = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('id', $preparationId)->lock(true)->find();
            if (!is_array($prep) || $prep['state'] !== 'cleaning') self::invalid();
            Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('id', $objectId)->where('audio_chunk_preparation_id', $preparationId)
                ->whereIn('state', ['allocated', 'ready'])->update(['state' => 'deleted']);
            $remaining = Db::connect('pgsql')->table('sand_ai_audio_chunk_object')->where('audio_chunk_preparation_id', $preparationId)->where('state', '<>', 'deleted')->count();
            if ((int) $remaining === 0) Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('id', $preparationId)->where('state', 'cleaning')->update(['state' => 'cleaned']);
        });
    }

    public function assertStore(string $storeCode, string $rootFingerprint): void
    {
        $store = Db::connect('pgsql')->table('sand_ai_audio_chunk_store')->where('code', $storeCode)->find();
        if (!is_array($store) || !hash_equals((string) $store['root_sha256'], $rootFingerprint)) {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_STORE_MISMATCH', 'The local audio storage instance differs from its durable registration');
        }
    }

    /** Caller already holds the short store reservation transaction lock. */
    private function registerStore(string $storeCode, string $rootFingerprint): void
    {
        Db::connect('pgsql')->execute(
            'INSERT INTO sand_ai_audio_chunk_store (code,root_sha256) VALUES (?,?) ON CONFLICT (code) DO NOTHING',
            [$storeCode, $rootFingerprint],
        );
        $this->assertStore($storeCode, $rootFingerprint);
    }

    /** Persist scheduling before attempting deletion, so failed/contended objects rotate fairly. */
    public function claimCleanup(string $storeCode, string $rootFingerprint, int $limit): array
    {
        $this->assertStore($storeCode, $rootFingerprint);
        $limit = max(1, min(20, $limit));
        return Db::connect('pgsql')->transaction(function () use ($storeCode, $rootFingerprint, $limit): array {
            return Db::connect('pgsql')->query(
                "WITH candidates AS (
                    SELECT o.id FROM sand_ai_audio_chunk_object o
                    JOIN sand_ai_audio_chunk_preparation p ON p.id=o.audio_chunk_preparation_id
                    WHERE p.store_code=? AND p.store_root_sha256=? AND p.state='cleaning' AND o.state<>'deleted'
                      AND o.cleanup_next_attempt_time <= (clock_timestamp() AT TIME ZONE 'UTC')
                    ORDER BY o.cleanup_last_attempt_time NULLS FIRST,o.id
                    LIMIT " . $limit . " FOR UPDATE OF o SKIP LOCKED
                ) UPDATE sand_ai_audio_chunk_object o SET
                    cleanup_attempt_count=LEAST(o.cleanup_attempt_count+1,1000000),
                    cleanup_last_attempt_time=(clock_timestamp() AT TIME ZONE 'UTC'),
                    cleanup_next_attempt_time=(clock_timestamp() AT TIME ZONE 'UTC') + make_interval(secs => LEAST(300,5*(1 << LEAST(o.cleanup_attempt_count,6))))
                  FROM candidates c WHERE o.id=c.id
                  RETURNING o.id,o.audio_chunk_preparation_id,o.sequence_no,o.chunk_ref",
                [$storeCode, $rootFingerprint],
            );
        });
    }

    /** @return array<string,mixed> Must run inside a short transaction; caller follows task -> preparation -> object order. */
    public function guard(int $environmentId, TaskExecutionLease $lease): array
    {
        $task = Db::connect('pgsql')->table('sand_ai_task_run')->where('id', $lease->taskId)->where('environment_id', $environmentId)
            ->where('state', 'running')->where('task_type', 'audio_transcription')->where('lease_owner', $lease->owner)
            ->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
        if (!is_array($task)) self::invalid();
        $live = Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)', [$lease->taskId, TaskLeasePolicy::runningTimezone()]);
        if ($live === []) self::invalid();
        $input = $task['input'];
        if (is_string($input)) $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) self::invalid();
        return FrozenAudioTaskInputPolicy::assert($input, $environmentId);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_PREPARATION_INVALID', 'Private audio preparation is unavailable or its lease has expired');
    }
}
