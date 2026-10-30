<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\External\ExternalAudioDispatchRequest;
use app\Application\Audio\External\FrozenAudioTaskInputPolicy;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\AudioSeconds;

/** A dispatch carries the original opaque file reference and the exact private clip digest. */
final class AudioChunkDispatchBinding
{
    /** JSONB object order is not authority. Reorder only established objects while retaining every field. */
    public static function ordered(array $value, array $keys): array
    {
        if (count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) self::invalid();
        $ordered = [];
        foreach ($keys as $key) $ordered[$key] = $value[$key];
        return $ordered;
    }

    public static function attribution(array $value): array
    {
        $value = self::ordered($value, ['format_version','environment_id','organization_id','application_id',
            'workload_client_id','identity_snapshot','capability_code']);
        $value['identity_snapshot'] = self::ordered($value['identity_snapshot'],
            ['organization_ref','application_ref','workload_client_ref']);
        return $value;
    }

    public static function pricing(array $value): array
    {
        $value = self::ordered($value, ['called_at','pricing_state','price_revision_id','price_snapshot']);
        $value['price_snapshot'] = self::ordered($value['price_snapshot'], $value['pricing_state'] === 'unpriced'
            ? ['snapshot_version','reason'] : ['snapshot_version','price_revision_id','currency','effective_from','effective_until','rates']);
        if ($value['pricing_state'] === 'priced') {
            $value['price_snapshot']['rates'] = self::ordered($value['price_snapshot']['rates'], ['audio_seconds']);
            $value['price_snapshot']['rates']['audio_seconds'] = self::ordered($value['price_snapshot']['rates']['audio_seconds'],
                ['unit_price','per_quantity']);
        }
        return $value;
    }

    public static function target(array $value): array
    {
        $route = $value['deployment']['route'] ?? null;
        if (is_array($route) && $route !== []) {
            $value['deployment']['route'] = self::ordered($route, ['selection_kind','profile_id','profile_revision','route_id']);
        }
        return $value;
    }

    public static function input(mixed $value, int $environmentId): array
    {
        if (is_string($value)) $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) self::invalid();
        $value = self::ordered($value, ['request','file_sha256','file_media_type','usage_attribution_version',
            'execution_target_version','usage_attribution','_trusted_identity_scope','execution_target','pricing_snapshot',
            'execution_driver','file_id','iam_authorization']);
        $value['request'] = self::ordered($value['request'], ['file_ref','language','speaker_diarization','glossary','data_egress_policy']);
        $value['execution_driver'] = self::ordered($value['execution_driver'], ['code','version']);
        $value['iam_authorization'] = self::ordered($value['iam_authorization'],
            ['version','file_id','authorization_id','source_ip','request_id','expires_at','context']);
        $value['usage_attribution'] = self::attribution($value['usage_attribution']);
        $value['execution_target'] = self::target($value['execution_target']);
        $value['pricing_snapshot'] = self::pricing($value['pricing_snapshot']);
        return FrozenAudioTaskInputPolicy::assert($value, $environmentId);
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $chunk */
    public static function request(ExternalAudioDispatchRequest $original, array $manifest, array $chunk): ExternalAudioDispatchRequest
    {
        if ($original->fileRef !== $manifest['file_ref'] || $original->fileSha256 !== $manifest['file_sha256']
            || $original->mediaType !== $manifest['media_type'] || $original->parameters['speaker_diarization']) self::invalid();
        $frozen = $original->frozen();
        $frozen['logical_execution_id'] = self::executionId($manifest, $chunk);
        $frozen['file_sha256'] = (string) $chunk['content_sha256'];
        $frozen['media_type'] = 'audio/wav';
        return ExternalAudioDispatchRequest::fromFrozen($frozen);
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $chunk */
    public static function executionId(array $manifest, array $chunk): string
    {
        foreach (['id', 'environment_id', 'task_run_id', 'sample_count', 'chunk_count'] as $key) {
            if (!isset($manifest[$key]) || !ctype_digit((string) $manifest[$key]) || (int) $manifest[$key] < 1) self::invalid();
        }
        if (!isset($chunk['id'], $chunk['audio_chunk_manifest_id'], $chunk['sequence_no'])
            || (int) $chunk['audio_chunk_manifest_id'] !== (int) $manifest['id']
            || (int) $chunk['environment_id'] !== (int) $manifest['environment_id']
            || (int) $chunk['task_run_id'] !== (int) $manifest['task_run_id']
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $manifest['manifest_sha256']) !== 1) self::invalid();
        $plan = new AudioChunkPlan((int) $manifest['sample_count']);
        $range = $plan->chunk((int) $chunk['sequence_no']);
        if ($plan->count() !== (int) $manifest['chunk_count']) self::invalid();
        foreach (['start_sample', 'end_sample', 'byte_size'] as $key) {
            if ((string) $range[$key] !== (string) $chunk[$key]) self::invalid();
        }
        return 'audiochunk_' . hash('sha256', $manifest['environment_id'] . '|' . $manifest['task_run_id']
            . '|' . $manifest['manifest_sha256'] . '|' . $chunk['sequence_no']);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $manifest @param array<string,mixed> $chunk */
    public static function assert(array $input, array $manifest, array $chunk, ExternalAudioDispatchRequest $request): void
    {
        $input = self::input($input, (int) $manifest['environment_id']);
        $parameters = ['language' => $input['request']['language'], 'speaker_diarization' => $input['request']['speaker_diarization'],
            'glossary' => $input['request']['glossary']];
        if ($request->logicalExecutionId !== self::executionId($manifest, $chunk)
            || $manifest['file_ref'] !== $input['request']['file_ref']
            || $manifest['file_sha256'] !== $input['file_sha256'] || $manifest['media_type'] !== $input['file_media_type']
            || $request->fileRef !== $manifest['file_ref'] || $request->fileSha256 !== $chunk['content_sha256']
            || $request->mediaType !== 'audio/wav' || $request->trustedScope !== $input['_trusted_identity_scope']
            || $request->driverCode !== $input['execution_driver']['code']
            || $request->driverVersion !== $input['execution_driver']['version']
            || $request->egressPolicy !== $input['request']['data_egress_policy'] || $parameters['speaker_diarization']
            || !RequestPayloadFingerprint::same($request->parameters, $parameters, [])
            || !RequestPayloadFingerprint::same($request->usageAttribution, $input['usage_attribution'], [])
            || !RequestPayloadFingerprint::same($request->executionTarget, $input['execution_target'], [])
            || !RequestPayloadFingerprint::same($request->priceSnapshot, $input['pricing_snapshot'], [])) self::invalid();
    }

    /** Canonical three-place duration rounds a final incomplete millisecond upward, as the manifest locator does. */
    public static function duration(array $chunk): string
    {
        $samples = (int) $chunk['end_sample'] - (int) $chunk['start_sample'];
        if ($samples < 1 || $samples > AudioChunkPlan::SAMPLES_PER_CHUNK) self::invalid();
        $milliseconds = intdiv($samples + 15, 16);
        return intdiv($milliseconds, 1000) . '.' . str_pad((string) ($milliseconds % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function seconds(array $chunk): AudioSeconds
    {
        return new AudioSeconds(self::duration($chunk));
    }

    public static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_BINDING_INVALID', 'The audio chunk differs from its frozen recording');
    }
}
