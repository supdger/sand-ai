<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** Resolves only an administrator-published speech route; request input never selects a provider. */
final class SpeechRuntimeResolver
{
    public function __construct(
        private readonly CapabilityProfileResolver $profiles = new CapabilityProfileResolver(),
        private readonly ProviderSecretDecryptor $decryptor = new ProviderSecretDecryptor(),
        private readonly ProviderExecutionConfigFingerprint $fingerprints = new ProviderExecutionConfigFingerprint(),
    ) {
    }

    public function resolve(int $environmentId, string $text, string $voice): SpeechResolvedRuntime
    {
        $route = $this->publishedRoute($environmentId);
        $deployment = ModelDeployment::where('id', (int) $route['deployment_id'])
            ->where('provider_id', (int) $route['provider_id'])
            ->where('model_id', (int) $route['model_id'])
            ->where('status', 1)->whereNull('delete_time')->find();
        $provider = Provider::where('id', (int) $route['provider_id'])
            ->where('status', 1)->whereNull('delete_time')->find();
        $model = AiModel::where('id', (int) $route['model_id'])
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($deployment === null || $provider === null || $model === null
            || (string) $provider->adapter !== 'openai_compatible'
            || (string) $model->type !== 'audio_speech'
            || !in_array('audio_speech', is_array($model->capabilities) ? $model->capabilities : [], true)
            || !is_string($deployment->remote_model) || trim($deployment->remote_model) === '') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_NOT_CONFIGURED', 'Published speech target is unavailable');
        }
        $revision = ConfigRevision::where('resource_type', 'model_deployment')
            ->where('resource_id', (int) $deployment->id)
            ->where('revision', (int) $deployment->revision)
            ->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find();
        if ($revision === null) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_NOT_CONFIGURED', 'Published speech deployment is unavailable');
        }
        $encrypted = is_array($provider->encrypted_config) ? $provider->encrypted_config : [];
        $fingerprint = $this->fingerprints->fromEncrypted('openai_compatible', $encrypted);
        $config = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($this->decryptor->decrypt($encrypted));
        if ($config['idempotency_mode'] !== 'none' || $config['idempotency_header'] !== null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Speech provider idempotency is not configured');
        }
        $target = new SpeechExecutionTarget(
            (int) $provider->id, (int) $model->id, (int) $deployment->id,
            (int) $route['profile_id'], (int) $route['profile_revision'], (int) $route['route_id'],
            $fingerprint['hmac_sha256'],
        );
        $settings = is_array($deployment->config) ? $deployment->config : [];
        $format = array_key_exists('speech_response_format', $settings) ? $settings['speech_response_format'] : 'mp3';
        if (!is_string($format)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Published speech format is invalid');
        }
        return new SpeechResolvedRuntime(
            $target,
            new SpeechSynthesisRequest($text, trim($deployment->remote_model), $voice, $format),
            new OpenAiCompatibleSpeechSynthesisDriver($config, timeoutMs: (int) $provider->timeout_ms),
        );
    }

    public function assertCurrent(int $environmentId, SpeechResolvedRuntime $frozen): void
    {
        $current = $this->resolve($environmentId, $frozen->request->text, $frozen->request->voice);
        if ($current->target->snapshot() !== $frozen->target->snapshot()
            || $current->request->model !== $frozen->request->model
            || $current->request->format !== $frozen->request->format
            || $current->driver->code() !== $frozen->driver->code()) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Speech route changed before dispatch');
        }
    }

    /** @return array<string,mixed> */
    private function publishedRoute(int $environmentId): array
    {
        $route = $this->profiles->primary($environmentId, 'audio_speech');
        if (!is_array($route) || ($route['route_kind'] ?? null) !== 'audio_driver'
            || ($route['driver_code'] ?? null) !== 'openai_compatible_speech') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_NOT_CONFIGURED', 'Published speech route is unavailable');
        }
        return $route;
    }
}
