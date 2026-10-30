<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioSnapshot;
use app\Application\Audio\AudioTranscriptionResultPolicy;
use app\Application\Audio\AudioTranscriptionSourcedResultBuilder;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Budget\BudgetDecimal;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\AudioTaskResultPresenter;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\AudioTaskCostProjection;
use app\Application\Usage\UsageAttribution;

/** Combines independently proven clips; billable events remain distinct from source duration. */
final class AudioChunkTranscriptAssembler
{
    public const MAX_BYTES = 8388608;

    /** @param list<array{chunk:array<string,mixed>,dispatch:array<string,mixed>,usage:array<string,mixed>}> $rows */
    public function assemble(array $input, array $manifest, array $rows): array
    {
        $environmentId = (int) $manifest['environment_id'];
        $taskId = (int) $manifest['task_run_id'];
        $input = AudioChunkDispatchBinding::input($input, $environmentId);
        $plan = new AudioChunkPlan((int) $manifest['sample_count']);
        if ($rows === [] || !array_is_list($rows) || count($rows) !== $plan->count()
            || (int) $manifest['chunk_count'] !== $plan->count()) self::pending();
        $driver = $input['execution_driver'];
        if (!in_array($driver['code'], ['glm_asr_audio', 'openai_compatible_audio'], true)
            || $driver['version'] !== '1.0.0' || $input['request']['speaker_diarization']) self::invalid();
        $segments = [];
        $granularities = [];
        $warnings = [];
        $dispatchIds = [];
        $usageIds = [];
        $billable = '0.000000';
        $amount = '0.000000';
        $price = null;
        $jsonBytes = 0;
        foreach ($rows as $index => $row) {
            $chunk = $row['chunk'];
            $dispatch = $row['dispatch'];
            $event = $row['usage'];
            if ((int) $chunk['sequence_no'] !== $index + 1) self::invalid();
            $range = $plan->chunk($index + 1);
            if ($dispatch['state'] !== 'succeeded') self::pending();
            if ((int) $dispatch['audio_chunk_id'] !== (int) $chunk['id']
                || (int) $dispatch['environment_id'] !== $environmentId || (int) $dispatch['task_run_id'] !== $taskId
                || ($dispatch['content_purged_time'] ?? null) !== null) self::invalid();
            $request = PostgresAudioChunkExecutionLedger::request($dispatch);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
            $terminal = $dispatch['terminal_snapshot'];
            $json = is_string($terminal) ? $terminal : json_encode($terminal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $jsonBytes += strlen($json);
            if ($jsonBytes > self::MAX_BYTES) self::invalid();
            $terminal = is_string($terminal) ? json_decode($terminal, true, 32, JSON_THROW_ON_ERROR) : $terminal;
            if (!is_array($terminal) || !AudioSnapshot::hasKeys($terminal, ['usage', 'result'])
                || !is_array($terminal['result']) || !is_array($terminal['usage'])) self::invalid();
            $clip = (new AudioTaskResultPresenter())->present($terminal['result'], $driver['code'], $driver['version']);
            $seconds = new AudioSeconds($clip['usage']['audio_seconds']);
            if (BudgetDecimal::compare($seconds->value, AudioChunkDispatchBinding::seconds($chunk)->value) > 0) self::invalid();
            if (($driver['code'] === 'glm_asr_audio'
                || $request->durationPolicy === \app\Application\Audio\External\ExternalAudioDispatchRequest::VERIFIED_DURATION_POLICY)
                && $clip['usage']['audio_seconds'] !== AudioChunkDispatchBinding::duration($chunk)) self::invalid();
            foreach ($clip['segments'] as $segment) {
                if ($segment['source']['file_ref'] !== $request->fileRef
                    || !hash_equals($request->fileSha256, $segment['source']['content_sha256'])
                    || $segment['end_ms'] > $range['end_ms'] - $range['start_ms']
                    || $segment['speaker'] !== null) self::invalid();
                $segments[] = ['start_ms' => $range['start_ms'] + $segment['start_ms'],
                    'end_ms' => $range['start_ms'] + $segment['end_ms'], 'speaker' => null, 'text' => $segment['text'],
                    'confidence' => $segment['confidence'], 'warnings' => $segment['warnings']];
                $granularities[] = $segment['source']['locator']['granularity'] ?? null;
                if (count($segments) > 10000) self::invalid();
            }
            foreach ($clip['warnings'] as $warning) {
                if (!in_array($warning, $warnings, true)) $warnings[] = $warning;
                if (count($warnings) > 50) self::invalid();
            }
            $cost = $this->cost($taskId, $environmentId, $input, $clip, $dispatch, $event, $terminal['usage']);
            if ($price !== null && ($cost['state'] !== $price['state'] || $cost['currency'] !== $price['currency']
                || $cost['price_revision_id'] !== $price['price_revision_id'])) self::invalid();
            $price ??= $cost;
            $billable = BudgetDecimal::add($billable, $cost['audio_seconds']);
            if ($cost['metered_amount'] !== null) $amount = BudgetDecimal::add($amount, $cost['metered_amount']);
            $dispatchId = (int) $dispatch['id'];
            $usageId = (int) $event['id'];
            if ($dispatchId < 1 || $usageId < 1 || in_array($dispatchId, $dispatchIds, true) || in_array($usageId, $usageIds, true)) self::invalid();
            $dispatchIds[] = $dispatchId;
            $usageIds[] = $usageId;
        }
        $duration = $plan->chunk($plan->count())['end_ms'];
        $sourceSeconds = intdiv($duration, 1000) . '.' . str_pad((string) ($duration % 1000), 3, '0', STR_PAD_LEFT);
        $normalized = (new AudioTranscriptionResultPolicy())->normalize(
            ['segments' => $segments, 'audio_seconds' => $sourceSeconds, 'warnings' => $warnings], false,
        );
        $reference = new VerifiedPrivateAudioReference($manifest['file_ref'], $manifest['media_type'],
            $manifest['file_sha256'], $input['_trusted_identity_scope']);
        $transcript = (new AudioTranscriptionSourcedResultBuilder())->build($reference, $normalized, $driver['code'],
            $driver['version'], $driver['code'] === 'glm_asr_audio' ? 'input_range_only' : null);
        // Keep every clip's existing optional precision; aggregation cannot improve or erase it.
        foreach ($transcript['segments'] as $index => &$segment) {
            if ($granularities[$index] !== null) {
                $segment['source']['locator']['granularity'] = $granularities[$index];
            }
        }
        unset($segment);
        foreach ($transcript['field_sources'] as &$fieldSource) {
            $index = (int) explode('/', $fieldSource['field_path'])[2];
            $fieldSource['source'] = $transcript['segments'][$index]['source'];
        }
        unset($fieldSource);
        if (strlen(json_encode($transcript, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) self::invalid();
        return ['transcript' => $transcript, 'audio_usage_cost' => ['state' => $price['state'],
            'audio_seconds' => $billable, 'metered_amount' => $price['state'] === 'priced' ? $amount : null,
            'currency' => $price['currency'], 'price_revision_id' => $price['price_revision_id']],
            'evidence' => ['manifest_sha256' => $manifest['manifest_sha256'],
                'transcript_sha256' => RequestPayloadFingerprint::fingerprint($transcript, []),
                'chunk_count' => $plan->count(), 'dispatch_ids' => $dispatchIds, 'usage_event_ids' => $usageIds]];
    }

    /** Validate the complete immutable event rather than trusting a caller-supplied total or first event. */
    private function cost(int $taskId, int $environmentId, array $input, array $clip, array $dispatch, array $event, array $summary): array
    {
        if ((string) ($event['external_audio_dispatch_id'] ?? '') !== (string) $dispatch['id']
            || ($event['audio_execution_id'] ?? null) !== null
            || ($event['idempotency_key'] ?? null) !== 'audio-dispatch:' . $dispatch['id']) self::invalid();
        $attribution = UsageAttribution::fromCurrentPersisted($input['usage_attribution'], $environmentId,
            'capability', 'sand_ai.audio.transcription');
        $target = AudioExecutionTarget::fromPersistence($input['execution_target']);
        $pricing = AudioCallStartPricingSnapshot::fromPersistence($input['pricing_snapshot'])
            ->usagePricing(new AudioSeconds($clip['usage']['audio_seconds']));
        foreach (['identity_snapshot' => $attribution->identitySnapshot, 'provider_snapshot' => $target->provider,
            'model_snapshot' => $target->model, 'deployment_snapshot' => $target->deployment, 'price_snapshot' => $pricing['price_snapshot']] as $field => $expected) {
            $actual = $event[$field] ?? null;
            if (is_string($actual)) $actual = json_decode($actual, true, 32, JSON_THROW_ON_ERROR);
            if (!AudioSnapshot::same($actual, $expected)) self::invalid();
        }
        $cost = (new AudioTaskCostProjection())->present($taskId, $environmentId, $input, $clip, $event);
        $expected = ['usage' => ['audio_seconds' => $cost['audio_seconds'],
            'tokens' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0]],
            'cost' => ['pricing_state' => $cost['state'], 'price_revision_id' => $cost['price_revision_id'],
                'metered_amount' => $cost['metered_amount'], 'currency' => $cost['currency']]];
        if (!AudioSnapshot::same($summary, $expected)) self::invalid();
        return $cost;
    }

    public static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_AGGREGATION_INVALID', 'The recording cannot be assembled from its frozen clip evidence');
    }

    public static function pending(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_AGGREGATION_PENDING', 'Every recording clip must finish before its transcript can be assembled', true);
    }
}
