<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioSnapshot;
use app\Application\File\OpaqueFileReference;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\UsageAttribution;

/** Single frozen TaskRun input contract shared by submit persistence and external dispatch claim. */
final class FrozenAudioTaskInputPolicy
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function assert(array $input, int $environmentId): array
    {
        $keys=['request','file_sha256','file_media_type','usage_attribution_version','execution_target_version','usage_attribution','_trusted_identity_scope','execution_target','pricing_snapshot','execution_driver','file_id','iam_authorization'];
        if ($environmentId < 1 || !AudioSnapshot::hasKeys($input, $keys) || !is_array($input['request'] ?? null) || !is_string($input['file_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D',$input['file_sha256']) !== 1 || !is_string($input['file_media_type'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9.+-]{0,126}\/[a-z0-9][a-z0-9.+-]{0,126}\z/D',$input['file_media_type']) !== 1 || $input['usage_attribution_version'] !== 'audio_usage_attribution@1' || $input['execution_target_version'] !== 'audio_execution_target@1' || !is_array($input['usage_attribution'] ?? null) || !is_array($input['execution_target'] ?? null) || !is_array($input['pricing_snapshot'] ?? null) || !is_array($input['execution_driver'] ?? null)) self::invalid();
        $request=$input['request'];
        if (!AudioSnapshot::hasKeys($request, ['file_ref','language','speaker_diarization','glossary','data_egress_policy']) || !is_string($request['file_ref']) || !is_string($request['language']) || !is_bool($request['speaker_diarization']) || !is_array($request['glossary']) || !array_is_list($request['glossary']) || !is_string($request['data_egress_policy'])) self::invalid();
        if (!AudioSnapshot::hasKeys($input['usage_attribution'], ['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code'])
            || !AudioSnapshot::hasKeys($input['usage_attribution']['identity_snapshot'] ?? null, ['organization_ref','application_ref','workload_client_ref'])) self::invalid();
        try {
            OpaqueFileReference::assert($request['file_ref']);
            $attribution=UsageAttribution::fromCurrentPersisted($input['usage_attribution'],$environmentId,'capability','sand_ai.audio.transcription');
            if (!is_string($input['_trusted_identity_scope']) || !hash_equals($input['_trusted_identity_scope'],RequestIdempotencyKey::trustedScopeForAttribution($attribution))) self::invalid();
            AudioExecutionTarget::fromPersistence($input['execution_target']);
            AudioCallStartPricingSnapshot::fromPersistence($input['pricing_snapshot']);
        } catch (\Throwable) { self::invalid(); }
        if (!AudioSnapshot::hasKeys($input['execution_driver'], ['code','version']) || !is_string($input['execution_driver']['code'] ?? null) || preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$input['execution_driver']['code']) !== 1 || !is_string($input['execution_driver']['version'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$input['execution_driver']['version']) !== 1) self::invalid();
        $authorization=$input['iam_authorization'] ?? null;
        if (!is_int($input['file_id'] ?? null) || $input['file_id'] < 1
            || !is_array($authorization)
            || !AudioSnapshot::hasKeys($authorization, ['version','file_id','authorization_id','source_ip','request_id','expires_at','context'])
            || ($authorization['version'] ?? null) !== 1
            || ($authorization['file_id'] ?? null) !== $input['file_id']
            || !is_int($authorization['authorization_id'] ?? null) || $authorization['authorization_id'] < 1
            || !is_string($authorization['source_ip'] ?? null)
            || !is_string($authorization['request_id'] ?? null)
            || !is_int($authorization['expires_at'] ?? null)
            || !is_array($authorization['context'] ?? null)) self::invalid();
        return $input;
    }

    /** @param array<string,mixed> $input */
    public static function assertMatchesExternal(array $input, int $environmentId, ExternalAudioDispatchRequest $dispatch): void
    {
        $input=self::assert($input,$environmentId);
        $target=AudioExecutionTarget::fromPersistence($input['execution_target']);
        if (!AudioSnapshot::same($dispatch->usageAttribution, $input['usage_attribution']) || !AudioSnapshot::same($dispatch->executionTarget, $input['execution_target']) || !AudioSnapshot::same($dispatch->priceSnapshot, $input['pricing_snapshot']) || !hash_equals($dispatch->trustedScope,(string)$input['_trusted_identity_scope']) || $dispatch->fileRef !== $input['request']['file_ref'] || !hash_equals($dispatch->fileSha256,$input['file_sha256']) || $dispatch->mediaType !== $input['file_media_type'] || $dispatch->egressPolicy !== $input['request']['data_egress_policy'] || $dispatch->driverCode !== $input['execution_driver']['code'] || $dispatch->driverVersion !== $input['execution_driver']['version'] || !AudioSnapshot::same($target->deployment['route'], ['selection_kind'=>'capability_profile','profile_id'=>$dispatch->profileId,'profile_revision'=>$dispatch->profileRevision,'route_id'=>$dispatch->routeId])) self::invalid();
    }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID','Frozen audio task input differs from the dispatch request'); }
}
