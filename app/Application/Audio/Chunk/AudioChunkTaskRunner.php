<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\External\ExternalAudioReceiptCipher;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Task\TaskLeaseTerminalGuard;
use app\Application\Task\TaskState;
use app\Application\Task\TaskWorkerIdentity;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** Durable preparation/manifest is the mode identity; continuation keeps the parent attempt and lease. */
final class AudioChunkTaskRunner
{
    public static function enabled(): bool
    {
        return config('sand_ai_audio_execution.long_recording.enabled', false) === true;
    }

    public function owns(TaskRun $task): bool
    {
        return (string) $task->task_type === 'audio_transcription' && AudioChunkRuntimeSchema::present()
            && Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')
                ->where('environment_id', (int) $task->environment_id)->where('task_run_id', (int) $task->id)->count() > 0;
    }

    /** Opt-in applies only to >30s canonical WAV candidates, never an existing short dispatch. */
    public function selected(TaskRun $task, array $input): bool
    {
        if ($this->owns($task)) return true;
        if (!self::enabled() || ($input['file_media_type'] ?? null) !== 'audio/wav'
            || ($input['request']['speaker_diarization'] ?? null) !== false
            || !in_array($input['execution_driver']['code'] ?? null, ['glm_asr_audio', 'openai_compatible_audio'], true)) return false;
        if (Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('task_run_id', (int) $task->id)->count() > 0) return false;
        $bytes = AiFile::where('id', $input['file_id'])->where('environment_id', (int) $task->environment_id)->value('size_bytes');
        if (!is_numeric($bytes) || (int) $bytes <= 44 + AudioChunkPlan::SAMPLES_PER_CHUNK * 2) return false;
        // Byte size alone does not prove duration: high-rate or stereo short WAVs keep their old executor.
        $reference = new \app\Application\Audio\VerifiedPrivateAudioReference($input['request']['file_ref'],
            $input['file_media_type'], $input['file_sha256'], $input['_trusted_identity_scope']);
        return (new \app\Application\Audio\PostgresPrivateAudioFileLoader())->withInput((int) $task->environment_id, $reference,
            static function (\app\Application\Audio\ControlledAudioInput $audio): bool {
                clearstatcache(true, $audio->temporaryPath);
                $actual = filesize($audio->temporaryPath);
                if (!is_int($actual) || $actual <= 44 + AudioChunkPlan::SAMPLES_PER_CHUNK * 2 || ($actual - 44) % 2 !== 0) return false;
                $samples = intdiv($actual - 44, 2);
                if ($samples > AudioChunkPlan::MAX_SAMPLES) {
                    throw new ApiProblem('SAND_AI_AUDIO_CHUNK_CAPACITY_EXCEEDED', 'The recording exceeds the four-hour processing limit');
                }
                return file_get_contents($audio->temporaryPath, false, null, 0, 44) === (new PrivatePcmAudioChunker())->header($samples);
            });
    }

    /** One due continuation only. A lost lease consumes one genuine failed attempt, successful slices consume none. */
    public function claimContinuation(string $workerId): ?TaskRun
    {
        if (!AudioChunkRuntimeSchema::present()) return null;
        TaskWorkerIdentity::assertValid($workerId);
        $zone = TaskLeasePolicy::runningTimezone();
        return Db::connect('pgsql')->transaction(function () use ($workerId, $zone): ?TaskRun {
            $rows = Db::connect('pgsql')->query("SELECT t.id FROM sand_ai_task_run t
                JOIN sand_ai_audio_chunk_preparation p ON p.task_run_id=t.id AND p.environment_id=t.environment_id
                WHERE t.task_type='audio_transcription' AND t.state='running' AND t.status=1
                AND t.delete_time IS NULL AND t.content_purged_time IS NULL AND p.state IN ('preparing','sealed')
                AND t.available_at <= (clock_timestamp() AT TIME ZONE ?)
                AND (t.lease_owner=? OR t.lease_expire_time <= (clock_timestamp() AT TIME ZONE ?))
                AND NOT EXISTS (SELECT 1 FROM sand_ai_task_run q WHERE q.state IN ('queued','retrying')
                    AND q.status=1 AND q.attempt_count<q.max_attempts AND q.delete_time IS NULL
                    AND (q.available_at<t.available_at OR (q.available_at=t.available_at AND q.id<t.id)))
                ORDER BY t.available_at,t.id LIMIT 1 FOR UPDATE OF t SKIP LOCKED", [$zone, $workerId, $zone]);
            if ($rows === []) return $this->claimResultRecovery($workerId, $zone);
            $task = TaskRun::where('id', (int) $rows[0]['id'])->lock(true)->find();
            if (!$task instanceof TaskRun) return null;
            $expired = Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? AND lease_expire_time <= (clock_timestamp() AT TIME ZONE ?)', [(int) $task->id, $zone]) !== [];
            if ($expired) {
                // Reacquire only to inspect/recover the old clip. Dispatching will be sealed unknown, never resent.
                $now = date('Y-m-d H:i:s');
                $task->save(['lease_owner' => $workerId, 'lease_token' => bin2hex(random_bytes(32)),
                    'lease_fence' => (int) $task->lease_fence + 1, 'locked_by' => $workerId, 'locked_at' => $now,
                    'lease_expire_time' => date('Y-m-d H:i:s', time() + TaskLeasePolicy::seconds()),
                    'heartbeat_time' => $now, 'version' => (int) $task->version + 1,
                    'attempt_count' => (int) $task->attempt_count + 1]);
            }
            return $task;
        });
    }

    /** Only the first unfinished clip's retained result can reopen a paused parent.
     * This claim is not permission to query or submit an unknown Provider outcome.
     */
    private function claimResultRecovery(string $workerId, string $zone): ?TaskRun
    {
        $rows = Db::connect('pgsql')->query("SELECT t.id FROM sand_ai_task_run t
            JOIN sand_ai_audio_chunk_preparation p ON p.task_run_id=t.id AND p.environment_id=t.environment_id
            JOIN LATERAL (
                SELECT d.id,d.state,d.content_purged_time
                FROM sand_ai_audio_chunk_manifest m
                JOIN sand_ai_audio_chunk c ON c.audio_chunk_manifest_id=m.id
                LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id
                WHERE m.task_run_id=t.id AND m.environment_id=t.environment_id
                AND d.state IS DISTINCT FROM 'succeeded'
                ORDER BY c.sequence_no LIMIT 1
            ) unfinished ON true
            WHERE t.task_type='audio_transcription' AND t.state='recovery_pending' AND t.status=1
            AND t.delete_time IS NULL AND t.content_purged_time IS NULL AND p.state='sealed'
            AND " . \app\Application\Task\TaskAuthorizationContinuation::claimSql('t') . "
            AND t.available_at <= (clock_timestamp() AT TIME ZONE ?)
            AND t.local_recovery_count<t.local_recovery_max
            AND t.attempt_count::bigint<=t.max_attempts::bigint+t.local_recovery_count::bigint+1
            AND unfinished.state='recovery_pending' AND unfinished.content_purged_time IS NULL
            AND EXISTS (SELECT 1 FROM sand_ai_external_audio_receipt r
                WHERE r.external_audio_dispatch_id=unfinished.id AND r.receipt_kind='result'
                AND r.receipt_version='external_audio_receipt/v1'
                AND r.destroy_time IS NULL AND r.acknowledged_time IS NULL
                AND r.expire_time>(clock_timestamp() AT TIME ZONE 'UTC')
                AND r.retain_until>=(clock_timestamp() AT TIME ZONE 'UTC'))
            AND EXISTS (SELECT 1 FROM sand_ai_task_step s WHERE s.task_id=t.id
                AND s.sequence_no=1 AND s.state='failed' AND s.status=1 AND s.delete_time IS NULL)
            AND NOT EXISTS (SELECT 1 FROM sand_ai_task_run q WHERE q.state IN ('queued','retrying')
                AND q.status=1 AND q.attempt_count<q.max_attempts AND q.delete_time IS NULL
                AND (q.available_at<t.available_at OR (q.available_at=t.available_at AND q.id<t.id)))
            ORDER BY t.available_at,t.id LIMIT 1 FOR UPDATE OF t SKIP LOCKED", [$zone]);
        if ($rows === []) return null;
        $task = TaskRun::where('id', (int) $rows[0]['id'])->lock(true)->find();
        if (!$task instanceof TaskRun || (string) $task->state !== TaskState::RECOVERY_PENDING) return null;
        $now = date('Y-m-d H:i:s');
        $task->save([
            'state' => TaskState::RUNNING, 'lease_owner' => $workerId,
            'lease_token' => bin2hex(random_bytes(32)), 'lease_fence' => (int) $task->lease_fence + 1,
            'locked_by' => $workerId, 'locked_at' => $now,
            'lease_expire_time' => date('Y-m-d H:i:s', time() + TaskLeasePolicy::seconds()),
            'heartbeat_time' => $now, 'version' => (int) $task->version + 1,
            'local_recovery_count' => (int) $task->local_recovery_count + 1,
            'completed_at' => null, 'error_code' => null, 'error_summary' => null,
        ]);
        $stepUpdated = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)
            ->where('state', TaskState::FAILED)->where('status', 1)->whereNull('delete_time')
            ->update(['state' => TaskState::RUNNING, 'completed_at' => null,
                'error_code' => null, 'error_summary' => null]);
        if ($stepUpdated !== 1) PostgresAudioChunkExecutionLedger::lost();
        // Install the same transaction-local lease clock used by the existing clip guard.
        Db::connect('pgsql')->query("SELECT set_config('sand_ai.task_timezone',?,true)", [$zone]);
        return $task;
    }

    public function advance(TaskRun $task, TaskExecutionLease $lease): array
    {
        if (!self::enabled() || config('sand_ai_audio_execution.external_enabled', false) !== true) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'Long recording processing is disabled');
        AudioChunkRuntimeSchema::assertReady();
        if (TaskLeasePolicy::seconds() < 180) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'Long recording processing requires at least a 180 second task lease');
        $env = (int) $task->environment_id;
        $input = AudioChunkDispatchBinding::input($task->input, $env);
        (new AudioTaskAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        $localRecovery = (int) $task->local_recovery_count;
        $recoveredContinuation = $localRecovery > 0 && $localRecovery <= (int) $task->local_recovery_max
            && (int) $task->attempt_count <= (int) $task->max_attempts + $localRecovery;
        if ((int) $task->attempt_count > (int) $task->max_attempts && !$recoveredContinuation) {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_ATTEMPTS_EXHAUSTED', 'Long recording recovery attempts are exhausted');
        }
        $objects = $this->objects();
        $prep = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('task_run_id', $lease->taskId)->find();
        if (!is_array($prep) || $prep['state'] === 'preparing') {
            (new AudioChunkPreparation($objects, (int) config('sand_ai_audio_execution.long_recording.capacity_bytes', 0)))
                ->prepareCanonicalWav($env, $lease);
            return $this->continueLater($env, $lease);
        }
        if ($prep['state'] !== 'sealed') AudioChunkDispatchBinding::invalid();
        $cipher = new ExternalAudioReceiptCipher((array) config('sand_ai_external_audio_receipt', []));
        $progress = (new AudioChunkExecution($objects, $cipher))->executeOne($env, $lease);
        if ($progress['state'] === 'aggregation_pending') return (new PostgresAudioChunkCompletion())->complete($env, $lease);
        if ($progress['state'] === 'receipt_poll_required' || $progress['state'] === 'manual_review_required') {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_RECOVERY_REQUIRED', 'The original audio dispatch requires receipt-only recovery or review');
        }
        return $this->continueLater($env, $lease);
    }

    private function continueLater(int $env, TaskExecutionLease $lease): array
    {
        (new TaskGateway())->heartbeat($lease);
        Db::connect('pgsql')->transaction(function () use ($env, $lease): void {
            (new PostgresAudioChunkExecutionLedger())->guard($env, $lease);
            $zone = TaskLeasePolicy::runningTimezone();
            $updated = Db::connect('pgsql')->execute('UPDATE sand_ai_task_run SET available_at=(clock_timestamp() AT TIME ZONE ?)+interval \'1 second\',version=version+1
                WHERE id=? AND environment_id=? AND state=\'running\' AND lease_owner=? AND lease_token=? AND lease_fence=?
                AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)', [$zone, $lease->taskId, $env, $lease->owner, $lease->token, $lease->fence, $zone]);
            if ($updated !== 1) PostgresAudioChunkExecutionLedger::lost();
        });
        return ['_continuation_pending' => true];
    }

    /** Fail closed while retaining all clip dispatch/receipt/usage facts. Never dispatch from this boundary. */
    public function stop(TaskRun $task, TaskExecutionLease $lease, string $code): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $code): void {
            $row = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            if (!$row instanceof TaskRun) PostgresAudioChunkExecutionLedger::lost();
            (new TaskGateway())->heartbeat($lease);
            $unresolved = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('task_run_id', $lease->taskId)
                ->whereIn('state', ['dispatching','accepted','recovery_pending','ambiguous'])->count() > 0;
            $state = $unresolved ? TaskState::RECOVERY_PENDING : TaskState::FAILED;
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('state', TaskState::RUNNING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])
                ->update(['state' => $state, 'error_code' => $code,
                    'error_summary' => 'Long recording processing paused; retain the original dispatch for recovery',
                    'completed_at' => $unresolved ? null : $now, 'locked_at' => null, 'locked_by' => null,
                    'lease_owner' => null, 'lease_token' => null, 'lease_expire_time' => null, 'heartbeat_time' => null,
                    'version' => Db::raw('version+1')]);
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->where('state', TaskState::RUNNING)
                ->update(['state' => TaskState::FAILED, 'error_code' => $code, 'error_summary' => 'Long recording processing requires recovery', 'completed_at' => $now]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $step, 'audio chunk recovery handoff');
        });
    }

    /** The installed deletion-recovery process owns this bounded cleanup callback. */
    public function cleanup(): array
    {
        // Disabling submissions does not revoke the ownership ledger's cleanup duty.
        if (!AudioChunkRuntimeSchema::present()) return ['scanned' => 0, 'deleted' => 0, 'retry_pending' => 0];
        AudioChunkRuntimeSchema::assertReady();
        $registered = Db::connect('pgsql')->table('sand_ai_audio_chunk_store')
            ->where('code', (string) config('sand_ai_audio_execution.long_recording.store_code', ''))->find();
        if (!is_array($registered)) return ['scanned' => 0, 'deleted' => 0, 'retry_pending' => 0];
        $objects = $this->objects();
        (new PostgresAudioChunkPreparationLedger())->assertStore($objects->storeCode, $objects->rootFingerprint());
        return (new AudioChunkObjectCleanup($objects))->runBatch(1);
    }

    private function objects(): LocalPrivateAudioChunkObjects
    {
        return new LocalPrivateAudioChunkObjects((string) config('sand_ai_audio_execution.long_recording.private_root', ''),
            (string) config('sand_ai_audio_execution.long_recording.store_code', ''),
            (int) config('sand_ai_audio_execution.long_recording.storage_timeout_seconds', 120));
    }
}
