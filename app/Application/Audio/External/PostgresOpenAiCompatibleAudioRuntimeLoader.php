<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\HttpsProviderEndpointResolver;
use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use app\Infrastructure\Persistence\Model\ConfigRevision;

/** Loads the live route target only after the durable claim; plaintext remains request-scoped. */
final class PostgresOpenAiCompatibleAudioRuntimeLoader implements OpenAiCompatibleAudioRuntimeLoader
{
    public function __construct(
        private readonly ProviderSecretDecryptor $decryptor = new ProviderSecretDecryptor(),
        private readonly OpenAiCompatibleAudioTranscriptionConfigPolicy $configPolicy = new OpenAiCompatibleAudioTranscriptionConfigPolicy(),
        private readonly HttpsProviderEndpointResolver $endpoints = new HttpsProviderEndpointResolver(),
        private readonly ProviderExecutionConfigFingerprint $fingerprints = new ProviderExecutionConfigFingerprint(),
    ) {}

    public function load(ExternalAudioDispatchRequest $request): OpenAiCompatibleAudioRuntime
    {
        if (!in_array($request->driverCode, ['openai_compatible_audio', 'glm_asr_audio'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The external ASR driver is unavailable');
        }
        $runtime = (array) config('sand_ai_audio_execution.' . $request->driverCode, []);
        if (config('sand_ai_audio_execution.external_enabled', false) !== true || ($runtime['enabled'] ?? false) !== true || ($runtime['provider_idempotency_mode'] ?? null) !== 'none' || ($runtime['synchronous_only'] ?? null) !== true) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'OpenAI-compatible audio is not configured');
        }
        $route = (new CapabilityProfileResolver())->primary((int) ($request->usageAttribution['environment_id'] ?? 0), 'audio_transcription');
        if (!is_array($route) || ($route['route_kind'] ?? null) !== 'audio_driver' || ($route['driver_code'] ?? null) !== $request->driverCode
            || ($route['profile_id'] ?? null) !== $request->profileId || ($route['profile_revision'] ?? null) !== $request->profileRevision
            || ($route['route_id'] ?? null) !== $request->routeId || ($route['deployment_id'] ?? null) !== $request->deploymentId
            || ($route['provider_id'] ?? null) !== $request->providerId || ($route['model_id'] ?? null) !== $request->modelId) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The published OpenAI-compatible audio route is unavailable');
        }
        $deployment = ModelDeployment::where('id', $request->deploymentId)->where('provider_id', $request->providerId)->where('model_id', $request->modelId)->where('status', 1)->find();
        $provider = Provider::where('id', $request->providerId)->where('status', 1)->find();
        $model = AiModel::where('id', $request->modelId)->where('status', 1)->find();
        if ($deployment === null || $provider === null || $model === null || (string) $provider->adapter !== 'openai_compatible'
            || !in_array('audio_transcription', is_array($model->capabilities) ? $model->capabilities : [], true)
            || !is_string($deployment->remote_model) || !in_array(trim($deployment->remote_model), $request->driverCode === 'glm_asr_audio' ? ['glm-asr-2512'] : ['whisper-1', 'gpt-4o-transcribe-diarize'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The published OpenAI-compatible audio target is unavailable');
        }
        $encrypted = is_array($provider->encrypted_config) ? $provider->encrypted_config : [];
        $credentialRevision = ConfigRevision::where('id', $request->credentialRevisionId)->where('resource_type', 'model_deployment')->where('resource_id', $request->deploymentId)->where('revision', (int) $deployment->revision)->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find();
        $fingerprint = $this->fingerprints->fromEncrypted('openai_compatible', $encrypted);
        if ($credentialRevision === null || !hash_equals($request->credentialDigest, $fingerprint['hmac_sha256'])) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'OpenAI-compatible audio credentials changed');
        }
        $config = $this->configPolicy->normalize($this->decryptor->decrypt($encrypted));
        return new OpenAiCompatibleAudioRuntime(
            $this->endpoints->audioTranscriptions($config), $config['api_key'], $config['extra_headers'], trim($deployment->remote_model),
            (int) $provider->timeout_ms, $config['response_max_bytes'], $config['provider_idempotency_mode'],
        );
    }
}
