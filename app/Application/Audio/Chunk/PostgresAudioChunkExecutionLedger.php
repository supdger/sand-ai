<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\External\ExternalAudioDispatchClaim;
use app\Application\Audio\External\ExternalAudioDispatchRequest;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Task lock serializes clip selection; a completed clip is never selected for another Provider call. */
final class PostgresAudioChunkExecutionLedger
{
    /** @return array<string,mixed> Must be called inside a short transaction after budget locks. */
    public function guard(int $environmentId, TaskExecutionLease $lease): array
    {
        $row = Db::connect('pgsql')->table('sand_ai_task_run')->where('id', $lease->taskId)
            ->where('environment_id', $environmentId)->where('task_type', 'audio_transcription')->where('state', 'running')
            ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
            ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
        if (!is_array($row)) self::lost();
        $timezone = TaskLeasePolicy::runningTimezone();
        // Proposed SQL107 consumes this transaction-local trusted timezone; no connection-global clock state.
        Db::connect('pgsql')->query("SELECT set_config('sand_ai.task_timezone',?,true)", [$timezone]);
        $live = Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)',
            [$lease->taskId, $timezone]);
        if ($live === []) self::lost();
        $input = AudioChunkDispatchBinding::input($row['input'], $environmentId);
        $step = Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id', $lease->taskId)
            ->where('sequence_no', 1)->where('state', 'running')->whereNull('delete_time')->lock(true)->find();
        $task = TaskRun::where('id', $lease->taskId)->where('environment_id', $environmentId)->find();
        if (!is_array($step) || !$task instanceof TaskRun) self::lost();
        (new AudioTaskAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        return $input;
    }

    /** @return array{manifest:array<string,mixed>,chunk:?array<string,mixed>,dispatch:?array<string,mixed>} */
    public function next(int $environmentId, TaskExecutionLease $lease): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease): array {
            $this->guard($environmentId, $lease);
            $manifest = Db::connect('pgsql')->table('sand_ai_audio_chunk_manifest')
                ->where('environment_id', $environmentId)->where('task_run_id', $lease->taskId)->find();
            $prep = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')
                ->where('environment_id', $environmentId)->where('task_run_id', $lease->taskId)->where('state', 'sealed')->find();
            if (!is_array($manifest) || !is_array($prep)) AudioChunkDispatchBinding::invalid();
            $chunks = Db::connect('pgsql')->query(
                'SELECT c.*,d.id AS selected_dispatch_id,d.state AS selected_dispatch_state FROM sand_ai_audio_chunk c '
                . 'LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id '
                . "WHERE c.audio_chunk_manifest_id=? AND d.state IS DISTINCT FROM 'succeeded' ORDER BY c.sequence_no LIMIT 1",
                [(int) $manifest['id']],
            );
            if ($chunks !== []) {
                $chunk = $chunks[0];
                AudioChunkDispatchBinding::executionId($manifest, $chunk);
                $dispatch = $chunk['selected_dispatch_id'] === null ? null
                    : Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $chunk['selected_dispatch_id'])->find();
                return ['manifest' => $manifest, 'chunk' => $chunk, 'dispatch' => $dispatch];
            }
            return ['manifest' => $manifest, 'chunk' => null, 'dispatch' => null];
        });
    }

    /** @return array<string,mixed> */
    public function prepare(int $environmentId, TaskExecutionLease $lease, int $chunkId, ExternalAudioDispatchRequest $request): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $chunkId, $request): array {
            $input = $this->guard($environmentId, $lease);
            [$manifest, $chunk] = $this->binding($environmentId, $lease->taskId, $chunkId);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
            $existing = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('audio_chunk_id', $chunkId)->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['request_fingerprint'], $request->fingerprint())
                    || $existing['logical_execution_id'] !== $request->logicalExecutionId) AudioChunkDispatchBinding::invalid();
                return $existing;
            }
            $ids = Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_external_audio_dispatch (environment_id,task_run_id,audio_chunk_id,logical_execution_id,request_fingerprint,frozen_request,state,budget_audio_seconds) '
                . "VALUES (?,?,?,?,?,CAST(? AS jsonb),'prepared',CAST(? AS numeric(20,6))) RETURNING id",
                [$environmentId, $lease->taskId, $chunkId, $request->logicalExecutionId, $request->fingerprint(),
                    json_encode($request->frozen(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), AudioChunkDispatchBinding::seconds($chunk)->value],
            );
            $this->guard($environmentId, $lease);
            return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $ids[0]['id'])->find()
                ?? throw new \RuntimeException('Audio chunk dispatch could not be read');
        });
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    public function binding(int $environmentId, int $taskId, int $chunkId): array
    {
        $chunk = Db::connect('pgsql')->table('sand_ai_audio_chunk')->where('id', $chunkId)
            ->where('environment_id', $environmentId)->where('task_run_id', $taskId)->find();
        if (!is_array($chunk)) AudioChunkDispatchBinding::invalid();
        $manifest = Db::connect('pgsql')->table('sand_ai_audio_chunk_manifest')->where('id', $chunk['audio_chunk_manifest_id'])->find();
        if (!is_array($manifest)) AudioChunkDispatchBinding::invalid();
        AudioChunkDispatchBinding::executionId($manifest, $chunk);
        return [$manifest, $chunk];
    }

    /** Acquire a new parent fence only for read/recovery. Dispatching is sealed ambiguous and never sent again. */
    public function recoverClaim(int $environmentId, TaskExecutionLease $lease, int $dispatchId): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease, $dispatchId): array {
            $budget = new AudioBudgetGuard();
            $anchor = $budget->lock($dispatchId, $lease->taskId, null);
            $input = $this->guard($environmentId, $lease);
            $row = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('environment_id', $environmentId)->where('task_run_id', $lease->taskId)->lock(true)->find();
            if (!is_array($row) || $row['audio_chunk_id'] === null) self::lost();
            [$manifest, $chunk] = $this->binding($environmentId, $lease->taskId, (int) $row['audio_chunk_id']);
            $request = self::request($row);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
            if ($row['state'] === 'dispatching') {
                if ($row['lease_owner'] === $lease->owner && $row['lease_token'] === $lease->token
                    && (int) $row['lease_fence'] === $lease->fence) {
                    // A concurrent or repeated caller must not make an in-flight request ambiguous.
                    self::lost();
                }
                $updated = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                    ->where('state', 'dispatching')->update(['state' => 'ambiguous', 'terminal_snapshot' =>
                        ['usage_state' => 'unknown', 'pricing_state' => 'unknown', 'error_code' => 'SAND_AI_DISPATCH_AMBIGUOUS'],
                        'update_time' => gmdate('Y-m-d H:i:s')]);
                if ($updated !== 1) self::lost();
                $budget->finish($anchor, null, null);
            } elseif (in_array($row['state'], ['accepted', 'recovery_pending'], true)) {
                if (Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                    ->where('state', $row['state'])->update(['lease_owner' => $lease->owner, 'lease_token' => $lease->token,
                        'lease_fence' => $lease->fence, 'update_time' => gmdate('Y-m-d H:i:s')]) !== 1) self::lost();
            }
            return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)->find()
                ?? throw new \RuntimeException('Audio dispatch disappeared');
        });
    }

    /** Progress reveals counts and states, never private refs, paths, raw receipts, or transcript content. */
    public function progress(int $environmentId, int $taskId): array
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT count(*) AS total,count(*) FILTER (WHERE d.state='succeeded') AS completed,"
            . "count(*) FILTER (WHERE d.state IN ('dispatching','accepted','recovery_pending')) AS recovery,"
            . "count(*) FILTER (WHERE d.state IN ('ambiguous','failed','not_dispatched')) AS stopped "
            . 'FROM sand_ai_audio_chunk c LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=c.id '
            . 'WHERE c.environment_id=? AND c.task_run_id=?', [$environmentId, $taskId],
        );
        return ['total_chunks' => (int) $rows[0]['total'], 'completed_chunks' => (int) $rows[0]['completed'],
            'recovery_chunks' => (int) $rows[0]['recovery'], 'stopped_chunks' => (int) $rows[0]['stopped']];
    }

    public static function claim(TaskExecutionLease $lease): ExternalAudioDispatchClaim
    {
        return new ExternalAudioDispatchClaim($lease->taskId, $lease->owner, $lease->token, $lease->fence);
    }

    public static function request(array $row): ExternalAudioDispatchRequest
    {
        $frozen = $row['frozen_request'];
        if (is_string($frozen)) $frozen = json_decode($frozen, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($frozen)) AudioChunkDispatchBinding::invalid();
        // PostgreSQL JSONB does not preserve insertion order; reconstruct the established request order.
        $keys = ['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code',
            'driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy',
            'credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target'];
        if (count($frozen) !== count($keys) || array_diff($keys, array_keys($frozen)) !== []) AudioChunkDispatchBinding::invalid();
        $ordered = [];
        foreach ($keys as $key) $ordered[$key] = $frozen[$key];
        foreach (['parameters' => ['language','speaker_diarization','glossary'],
            'usage_attribution' => ['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code']] as $field => $order) {
            $value = $ordered[$field];
            if (!is_array($value) || count($value) !== count($order) || array_diff($order, array_keys($value)) !== []) AudioChunkDispatchBinding::invalid();
            $ordered[$field] = [];
            foreach ($order as $key) $ordered[$field][$key] = $value[$key];
        }
        $identity = $ordered['usage_attribution']['identity_snapshot'];
        if (!is_array($identity) || count($identity) !== 3
            || array_diff(['organization_ref','application_ref','workload_client_ref'], array_keys($identity)) !== []) AudioChunkDispatchBinding::invalid();
        $ordered['usage_attribution']['identity_snapshot'] = ['organization_ref' => $identity['organization_ref'],
            'application_ref' => $identity['application_ref'], 'workload_client_ref' => $identity['workload_client_ref']];
        $ordered['price_snapshot'] = AudioChunkDispatchBinding::pricing($ordered['price_snapshot']);
        $ordered['execution_target'] = AudioChunkDispatchBinding::target($ordered['execution_target']);
        $request = ExternalAudioDispatchRequest::fromFrozen($ordered);
        if (!hash_equals((string) $row['request_fingerprint'], $request->fingerprint())) AudioChunkDispatchBinding::invalid();
        return $request;
    }

    public static function lost(): never
    {
        throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The audio chunk execution lease is no longer valid');
    }
}
