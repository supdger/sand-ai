<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioSnapshot;

/** Immutable, provider-neutral request that an external audio adapter may receive only after a durable claim. */
final readonly class ExternalAudioDispatchRequest
{
    public const VERIFIED_DURATION_POLICY = 'verified_private_audio@1';
    /** @param array<string,mixed> $parameters @param array<string,mixed> $priceSnapshot */
    public function __construct(
        public string $logicalExecutionId,
        public string $trustedScope,
        public string $fileRef,
        public string $fileSha256,
        public string $mediaType,
        public array $parameters,
        public string $driverCode,
        public string $driverVersion,
        public int $providerId,
        public int $modelId,
        public int $deploymentId,
        public int $profileId,
        public int $profileRevision,
        public int $routeId,
        public string $egressPolicy,
        public int $credentialRevisionId,
        public string $credentialDigest,
        public array $priceSnapshot,
        public array $usageAttribution,
        public array $executionTarget,
        public ?string $durationPolicy = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9:_-]{7,191}\z/', $logicalExecutionId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $trustedScope) !== 1
            || preg_match('/\Asaf_[A-Za-z0-9_-]{16,120}\z/D', $fileRef) !== 1 || str_contains($fileRef, '/') || str_contains($fileRef, ':')
            || preg_match('/\A[a-f0-9]{64}\z/', $fileSha256) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9.+-]{0,126}\/[a-z0-9][a-z0-9.+-]{0,126}\z/', $mediaType) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $driverCode) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $driverVersion) !== 1
            || $providerId < 1 || $modelId < 1 || $deploymentId < 1 || $profileId < 1 || $profileRevision < 1 || $routeId < 1 || $credentialRevisionId < 1
            || ($durationPolicy !== null && $durationPolicy !== self::VERIFIED_DURATION_POLICY)
            || $egressPolicy !== 'approved_cloud' || preg_match('/\A[a-f0-9]{64}\z/', $credentialDigest) !== 1) {
            self::invalid();
        }
        self::assertParameters($parameters);
        self::assertPriceSnapshot($priceSnapshot);
        self::assertUsageAttribution($usageAttribution);
        self::assertExecutionTarget($executionTarget, $providerId, $modelId, $deploymentId, $profileId, $profileRevision, $routeId);
        try {
            $attribution = \app\Application\Usage\UsageAttribution::fromCurrentPersisted($usageAttribution, $usageAttribution['environment_id'], 'capability', 'sand_ai.audio.transcription');
            if (!hash_equals($trustedScope, \app\Application\Gateway\RequestIdempotencyKey::trustedScopeForAttribution($attribution))) self::invalid();
        } catch (\Throwable) { self::invalid(); }
    }

    /** @return array<string,mixed> */
    public function frozen(): array
    {
        return [
            'logical_execution_id' => $this->logicalExecutionId, 'trusted_scope' => $this->trustedScope, 'file_ref' => $this->fileRef,
            'file_sha256' => $this->fileSha256, 'media_type' => $this->mediaType, 'parameters' => $this->parameters,
            'driver_code' => $this->driverCode, 'driver_version' => $this->driverVersion,
            'provider_id' => $this->providerId, 'model_id' => $this->modelId, 'deployment_id' => $this->deploymentId,
            'profile_id' => $this->profileId, 'profile_revision' => $this->profileRevision, 'route_id' => $this->routeId,
            'egress_policy' => $this->egressPolicy, 'credential_revision_id' => $this->credentialRevisionId,
            'credential_digest' => $this->credentialDigest, 'price_snapshot' => $this->priceSnapshot,
            'usage_attribution' => $this->usageAttribution, 'execution_target' => $this->executionTarget,
            ...($this->durationPolicy === null ? [] : ['duration_policy' => $this->durationPolicy]),
        ];
    }

    /** Preserve the original valid writer's pricing order, independently of JSONB key order. */
    public function priceSnapshotHash(): string
    {
        $snapshot = \app\Application\Usage\AudioCallStartPricingSnapshot::fromPersistence($this->priceSnapshot)->persistence();
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function fingerprint(): string { return hash('sha256', self::canonical($this->frozen())); }

    /** @param array<string,mixed> $value */
    public static function fromFrozen(array $value): self
    {
        if (array_key_exists('duration_policy', $value)
            && $value['duration_policy'] !== self::VERIFIED_DURATION_POLICY) self::invalid();
        if (!AudioSnapshot::hasKeys(array_diff_key($value, ['duration_policy' => true]), ['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target'])) self::invalid();
        return new self($value['logical_execution_id'],$value['trusted_scope'],$value['file_ref'],$value['file_sha256'],$value['media_type'],$value['parameters'],$value['driver_code'],$value['driver_version'],$value['provider_id'],$value['model_id'],$value['deployment_id'],$value['profile_id'],$value['profile_revision'],$value['route_id'],$value['egress_policy'],$value['credential_revision_id'],$value['credential_digest'],$value['price_snapshot'],$value['usage_attribution'],$value['execution_target'],$value['duration_policy'] ?? null);
    }

    /** This is intentionally independent of attempts, fences, timestamps, and adapter transport. */
    public function providerIdempotencyKey(): string { return 'sandae_' . substr(hash('sha256', 'sand-ai/external-audio/logical-execution/v1|' . hash('sha256', $this->trustedScope) . '|' . $this->logicalExecutionId), 0, 48); }

    /** @param array<string,mixed> $value */
    private static function assertPriceSnapshot(array $value): void
    {
        try { \app\Application\Usage\AudioCallStartPricingSnapshot::fromPersistence($value); } catch (\Throwable) { self::invalid(); }
    }
    /** @param array<string,mixed> $value */
    private static function assertUsageAttribution(array $value): void
    {
        if (!AudioSnapshot::hasKeys($value, ['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code']) || $value['format_version'] !== 1 || !is_int($value['environment_id']) || $value['environment_id'] < 1 || $value['capability_code'] !== 'audio_transcription' || !is_array($value['identity_snapshot']) || !AudioSnapshot::hasKeys($value['identity_snapshot'], ['organization_ref','application_ref','workload_client_ref'])) self::invalid();
        foreach (['organization_id','application_id','workload_client_id'] as $key) if ($value[$key] !== null && (!is_int($value[$key]) || $value[$key] < 1)) self::invalid();
        foreach ($value['identity_snapshot'] as $reference) if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 191) self::invalid();
    }
    /** @param array<string,mixed> $value */
    private static function assertExecutionTarget(array $value, int $providerId, int $modelId, int $deploymentId, int $profileId, int $profileRevision, int $routeId): void
    {
        try { $target = \app\Application\Usage\AudioExecutionTarget::fromPersistence($value); } catch (\Throwable) { self::invalid(); }
        if ($target->provider['id'] !== $providerId || $target->model['id'] !== $modelId || $target->deployment['id'] !== $deploymentId || !AudioSnapshot::same($target->deployment['route'], ['selection_kind'=>'capability_profile','profile_id'=>$profileId,'profile_revision'=>$profileRevision,'route_id'=>$routeId])) self::invalid();
    }
    /** @param array<string,mixed> $value */
    private static function assertParameters(array $value): void
    {
        if(!AudioSnapshot::hasKeys($value, ['language','speaker_diarization','glossary'])||!is_string($value['language'])||!in_array($value['language'],['auto','zh','en'],true)||!is_bool($value['speaker_diarization'])||!is_array($value['glossary'])||!array_is_list($value['glossary'])||count($value['glossary'])>100)self::invalid();
        foreach($value['glossary'] as $term)if(!is_string($term)||trim($term)===''||$term!==trim($term)||mb_strlen($term,'UTF-8')>128)self::invalid();
    }

    private static function assertSafeValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 8 || is_object($value) || is_resource($value)) self::invalid();
        if (is_string($value)) {
            if (strlen($value) > 4096 || preg_match('/(?:\A|_)(?:secret|token|password|api_key|credential|endpoint|url|raw|object_key|bucket|storage|location|path)(?:\z|_)/i', $value) === 1
                || preg_match('/(?:[a-z][a-z0-9+.-]*:\/\/|\A(?:\/|\.\/|\.\.\/|~\/))/', $value) === 1) self::invalid();
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_int($key) && (!is_string($key) || preg_match('/(?:secret|token|password|api_key|credential|endpoint|url|raw|object_key|bucket|storage|location|path)/i', $key) === 1)) self::invalid();
                self::assertSafeValue($item, $depth + 1);
            }
            return;
        }
        if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value)) self::invalid();
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value) && !array_is_list($value)) ksort($value);
        if (is_array($value)) foreach ($value as &$entry) $entry = json_decode(self::canonical($entry), true, 512, JSON_THROW_ON_ERROR); unset($entry);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function invalid(): never { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'External audio dispatch request is invalid'); }
}
