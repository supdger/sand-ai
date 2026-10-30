<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfilePolicy;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;
use think\facade\Db;

/**
 * Rebuilds a dispatch request solely from a claimed task's frozen authority
 * and the currently locked, published route. Provider plaintext is consumed
 * only inside ProviderExecutionConfigFingerprint and never leaves this class.
 */
final class PostgresExternalAudioRequestFactory implements ExternalAudioDispatchRequestFactory
{
    public function __construct(private readonly ProviderExecutionConfigFingerprint $fingerprints = new ProviderExecutionConfigFingerprint()) {}

    public function create(int $environmentId, int $taskRunId, array $input): ExternalAudioDispatchRequest
    {
        if ($environmentId < 1 || $taskRunId < 1 || config('sand_ai_audio_execution.external_enabled', false) !== true) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'OpenAI-compatible audio is not configured');
        }
        FrozenAudioTaskInputPolicy::assert($input, $environmentId);
        if ((!in_array($input['execution_driver']['code'], ['openai_compatible_audio', 'glm_asr_audio'], true) || $input['execution_driver']['version'] !== '1.0.0')
            || (($input['request']['data_egress_policy'] ?? null) !== 'approved_cloud')) {
            throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'Frozen external audio task input is invalid');
        }

        return Db::connect('pgsql')->transaction(function () use ($environmentId, $taskRunId, $input): ExternalAudioDispatchRequest {
            $frozenTarget = AudioExecutionTarget::fromPersistence($this->array($input['execution_target'] ?? null));
            $pricing = AudioCallStartPricingSnapshot::fromPersistence($this->array($input['pricing_snapshot'] ?? null));
            $attribution = UsageAttribution::fromCurrentPersisted($this->array($input['usage_attribution'] ?? null), $environmentId, 'capability', 'sand_ai.audio.transcription');
            $trustedScope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
            if (!hash_equals($trustedScope, (string) ($input['_trusted_identity_scope'] ?? ''))) {
                throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'Frozen external audio task identity is invalid');
            }

            $profile = CapabilityProfile::where('environment_id', $environmentId)->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->whereNull('delete_time')->order('revision', 'desc')->lock(true)->find();
            if ($profile === null || (string) $profile->data_egress_policy !== 'approved_cloud') $this->unavailable();
            $profileRevision = ConfigRevision::where('resource_type', 'capability_profile')->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($profileRevision === null) $this->unavailable();
            $route = CapabilityRoute::where('profile_id', (int) $profile->id)->where('capability_code', 'audio_transcription')->where('status', 1)->whereNull('delete_time')->order('priority')->lock(true)->find();
            if ($route === null || (int) $route->deployment_id < 1 || (string) $route->driver_code !== $input['execution_driver']['code']) $this->unavailable();
            $driver = CapabilityDriverRegistry::parseDriver('audio_transcription', $input['execution_driver']['code']);
            if (($driver['data_egress'] ?? null) !== 'approved_cloud') $this->unavailable();

            $deployment = ModelDeployment::where('id', (int) $route->deployment_id)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($deployment === null) $this->unavailable();
            $deploymentRevision = ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            $model = AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
            if ($deploymentRevision === null || $provider === null || $model === null || (string) $provider->adapter !== 'openai_compatible'
                || !in_array('audio_transcription', is_array($model->capabilities) ? $model->capabilities : [], true)
                || !in_array(trim((string) $deployment->remote_model), $input['execution_driver']['code'] === 'glm_asr_audio' ? ['glm-asr-2512'] : ['whisper-1', 'gpt-4o-transcribe-diarize'], true)) $this->unavailable();

            $target = AudioExecutionTarget::fromSnapshots((int) $provider->id, (int) $model->id, (int) $deployment->id,
                ['id' => (int) $provider->id, 'code' => (string) $provider->code, 'name' => (string) $provider->getAttr('name')],
                ['id' => (int) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->getAttr('name')],
                ['id' => (int) $deployment->id, 'remote_model' => trim((string) $deployment->remote_model), 'revision' => (int) $deployment->revision,
                    'route' => ['selection_kind' => 'capability_profile', 'profile_id' => (int) $profile->id, 'profile_revision' => (int) $profile->revision, 'route_id' => (int) $route->id]]);
            if ($this->canonical($target->persistence()) !== $this->canonical($frozenTarget->persistence())) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The frozen external audio route changed');
            }
            $fingerprint = $this->fingerprints->fromEncrypted('openai_compatible', is_array($provider->encrypted_config) ? $provider->encrypted_config : []);
            $request = $this->array($input['request'] ?? null);
            // Preserve the original writer's duration policy, including its absence,
            // so resuming a legacy request does not change its fingerprint or receipts.
            $existing = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')
                ->where('environment_id', $environmentId)->where('task_run_id', $taskRunId)
                ->where('logical_execution_id', 'audio-task:' . $taskRunId)->find();
            $durationPolicy = ExternalAudioDispatchRequest::VERIFIED_DURATION_POLICY;
            if (is_array($existing)) {
                $frozen = $existing['frozen_request'] ?? null;
                if (is_string($frozen)) $frozen = json_decode($frozen, true, 32, JSON_THROW_ON_ERROR);
                $durationPolicy = ExternalAudioDispatchRequest::fromFrozen($this->array($frozen))->durationPolicy;
            }
            return new ExternalAudioDispatchRequest(
                'audio-task:' . $taskRunId, $trustedScope, (string) $request['file_ref'], (string) $input['file_sha256'], (string) $input['file_media_type'],
                ['language' => $request['language'], 'speaker_diarization' => $request['speaker_diarization'], 'glossary' => $request['glossary']],
                $input['execution_driver']['code'], '1.0.0', (int) $provider->id, (int) $model->id, (int) $deployment->id, (int) $profile->id, (int) $profile->revision, (int) $route->id,
                'approved_cloud', (int) $deploymentRevision->id, $fingerprint['hmac_sha256'], $pricing->persistence(), $attribution->toPersisted(), $target->persistence(), $durationPolicy,
            );
        });
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array { if (!is_array($value)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'Frozen external audio task input is invalid'); return $value; }
    private function canonical(mixed $value): string { if (is_array($value) && !array_is_list($value)) ksort($value); if (is_array($value)) foreach ($value as &$item) $item = json_decode($this->canonical($item), true, 512, JSON_THROW_ON_ERROR); unset($item); return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); }
    private function unavailable(): never { throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The published OpenAI-compatible audio route is unavailable'); }
}
