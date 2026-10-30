<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\External\ExternalAudioDispatchClaim;
use app\Application\Audio\External\ExternalAudioDispatchRequest;
use app\Application\Audio\External\ExternalAudioReceiptCipher;
use app\Application\Audio\External\ExternalAudioResultRecoveryPort;
use app\Application\Task\AudioTaskResultPresenter;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\AudioUsageMeter;
use app\Application\Usage\AudioUsageRequest;
use app\Application\Usage\PostgresAudioUsageEventStore;
use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/** Recovers one clip and its immutable fee. It never terminalizes the parent task or sends a Provider request. */
final readonly class PostgresAudioChunkResultRecoveryPort implements ExternalAudioResultRecoveryPort
{
    public function __construct(private ExternalAudioReceiptCipher $cipher, private int $environmentId,
        private TaskExecutionLease $lease, private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger()) {}

    public function recover(int $dispatchId, ExternalAudioDispatchClaim $claim): array
    {
        if ($claim->taskRunId !== $this->lease->taskId || $claim->owner !== $this->lease->owner
            || $claim->token !== $this->lease->token || $claim->fence !== $this->lease->fence) PostgresAudioChunkExecutionLedger::lost();
        return Db::connect('pgsql')->transaction(function () use ($dispatchId, $claim): array {
            (new AudioBudgetGuard())->lock($dispatchId, $claim->taskRunId, null);
            $input = $this->ledger->guard($this->environmentId, $this->lease);
            $row = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('environment_id', $this->environmentId)->where('task_run_id', $claim->taskRunId)
                ->whereNull('content_purged_time')->lock(true)->find();
            if (!is_array($row) || $row['audio_chunk_id'] === null) PostgresAudioChunkExecutionLedger::lost();
            [$manifest, $chunk] = $this->ledger->binding($this->environmentId, $claim->taskRunId, (int) $row['audio_chunk_id']);
            $request = PostgresAudioChunkExecutionLedger::request($row);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
            if ($row['state'] === 'succeeded') {
                $terminal = $row['terminal_snapshot'];
                if (is_string($terminal)) $terminal = json_decode($terminal, true, 32, JSON_THROW_ON_ERROR);
                $existing = Db::connect('pgsql')->table('sand_ai_usage_event')->where('external_audio_dispatch_id', $dispatchId)->find();
                if (!is_array($terminal) || !is_array($terminal['usage'] ?? null) || !is_array($terminal['result'] ?? null)
                    || !is_array($existing)) self::invalid();
                $this->assertResult($terminal['result'], $request, $chunk);
                return $terminal['usage'];
            }
            if ($row['state'] !== 'recovery_pending' || $row['lease_owner'] !== $claim->owner
                || $row['lease_token'] !== $claim->token || (int) $row['lease_fence'] !== $claim->fence) self::invalid();
            $receipt = Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('external_audio_dispatch_id', $dispatchId)
                ->where('receipt_kind', 'result')->whereNull('destroy_time')->where('expire_time', '>', gmdate('Y-m-d H:i:s'))->lock(true)->find();
            if (!is_array($receipt)) self::invalid();
            $plain = $this->cipher->decrypt($receipt, self::context($row, $request));
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) self::invalid();
            $result = $this->assertResult($decoded, $request, $chunk);
            $seconds = new AudioSeconds($result['usage']['audio_seconds']);
            $attribution = UsageAttribution::fromCurrentPersisted($request->usageAttribution, $this->environmentId,
                'capability', 'sand_ai.audio.transcription');
            $target = AudioExecutionTarget::fromPersistence($request->executionTarget);
            $summary = (new AudioUsageMeter(new PostgresAudioUsageEventStore()))->meter(
                new AudioUsageRequest($claim->taskRunId, $attribution, $request->providerId, $request->modelId,
                    $request->deploymentId, $target, $seconds, null, $dispatchId),
                AudioCallStartPricingSnapshot::fromPersistence($request->priceSnapshot),
            );
            $now = gmdate('Y-m-d H:i:s');
            $updated = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('state', 'recovery_pending')->where('lease_owner', $claim->owner)->where('lease_token', $claim->token)
                ->where('lease_fence', $claim->fence)->update(['state' => 'succeeded',
                    'terminal_snapshot' => ['usage' => $summary, 'result' => $result], 'update_time' => $now]);
            $acked = Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('id', $receipt['id'])
                ->whereNull('acknowledged_time')->where('retain_until', '>=', $now)->update(['acknowledged_time' => $now]);
            if ($updated !== 1 || $acked !== 1) PostgresAudioChunkExecutionLedger::lost();
            $this->ledger->guard($this->environmentId, $this->lease);
            return $summary;
        });
    }

    /** Ensure an authenticated result is still bound to this exact clip and fits its known source range. */
    private function assertResult(array $result, ExternalAudioDispatchRequest $request, array $chunk): array
    {
        $result = (new AudioTaskResultPresenter())->present($result, $request->driverCode, $request->driverVersion);
        $duration = AudioChunkDispatchBinding::duration($chunk);
        $limitMs = intdiv((int) $chunk['end_sample'] - (int) $chunk['start_sample'] + 15, 16);
        $seconds = new AudioSeconds($result['usage']['audio_seconds']);
        $limit = AudioChunkDispatchBinding::seconds($chunk);
        if (\app\Application\Budget\BudgetDecimal::compare($seconds->value, $limit->value) > 0
            || (($request->driverCode === 'glm_asr_audio'
                || $request->durationPolicy === ExternalAudioDispatchRequest::VERIFIED_DURATION_POLICY)
                && $result['usage']['audio_seconds'] !== $duration)) self::invalid();
        foreach ($result['segments'] as $segment) {
            if ($segment['source']['file_ref'] !== $request->fileRef
                || !hash_equals($request->fileSha256, $segment['source']['content_sha256'])
                || $segment['end_ms'] > $limitMs || $segment['speaker'] !== null) self::invalid();
        }
        return $result;
    }

    public static function context(array $record, ExternalAudioDispatchRequest $request): array
    {
        return ['environment_id' => (int) $record['environment_id'], 'task_run_id' => (int) $record['task_run_id'],
            'dispatch_id' => (int) $record['id'], 'request_fingerprint' => $request->fingerprint(),
            'logical_execution_id' => $request->logicalExecutionId, 'provider_id' => $request->providerId,
            'model_id' => $request->modelId, 'deployment_id' => $request->deploymentId, 'profile_id' => $request->profileId,
            'profile_revision' => $request->profileRevision, 'route_id' => $request->routeId,
            'credential_revision_id' => $request->credentialRevisionId, 'credential_digest' => $request->credentialDigest,
            'price_snapshot_hash' => $request->priceSnapshotHash()];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'The audio chunk result cannot be recovered');
    }
}
