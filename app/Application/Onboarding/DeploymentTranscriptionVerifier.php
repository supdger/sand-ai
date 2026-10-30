<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Audio\External\GuzzleOpenAiCompatibleAudioTransport;
use app\Application\Audio\External\NeuronAudioTranscriptionRequestBuilder;
use app\Application\Audio\External\OpenAiCompatibleAudioResponseNormalizer;
use app\Application\Audio\External\OpenAiCompatibleAudioTranscriptionConfigPolicy;
use app\Application\Audio\External\GlmAsrAudioInputPolicy;
use app\Application\Audio\External\GlmAsrAudioTranscriptionClient;
use app\Application\Audio\External\OpenAiCompatibleAudioRuntime;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Infrastructure\Provider\HttpsProviderEndpointResolver;

/** Verifies a saved transcription deployment with the packaged spoken sample. */
final class DeploymentTranscriptionVerifier
{
    private const SAMPLE_SHA256 = '4ad32f3c91f35d132bd740fd63cf0aab2e8c0636936d4a9b0e7f5c0f659328e3';

    /** Runs the same registered local engine on the existing non-sensitive packaged sample. */
    public function verifyLocal(string $runtimeCode): void
    {
        \app\Application\Audio\LocalAudioDeploymentPolicy::assertTarget('local_runtime', 'audio_transcription', $runtimeCode);
        $sample = $this->sample();
        $driver = new \app\Application\Audio\WhisperCppAudioTranscriptionDriver(
            \app\Application\Audio\WhisperCppExecutionConfig::fromConfig((array) config('sand_ai_audio_execution.local_whisper_cpp', [])),
        );
        $result = (new \app\Application\Audio\AudioTranscriptionResultPolicy())->normalize(
            $driver->transcribeLocal(new ControlledAudioInput($sample, new VerifiedPrivateAudioReference(
                'saf_' . str_repeat('v', 32), 'audio/wav', self::SAMPLE_SHA256,
            )), ['language' => 'en', 'speaker_diarization' => false, 'glossary' => []]),
            false,
        );
        foreach ($result['segments'] as $segment) {
            if (is_string($segment['text'] ?? null) && trim($segment['text']) !== '') {
                return;
            }
        }
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'Local transcription verification returned no recognized speech');
    }

    private function sample(): string
    {
        $sample = dirname(__DIR__, 3) . '/plugin/sand-ai/resources/verification/transcription-sample.wav';
        if (!is_file($sample) || filesize($sample) !== 103710
            || !hash_equals(self::SAMPLE_SHA256, (string) hash_file('sha256', $sample))) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Transcription verification sample is unavailable');
        }
        return $sample;
    }

    /** @param array<string,mixed> $providerConfig */
    public function verify(array $providerConfig, string $remoteModel, int $timeoutMs): void
    {
        if (!in_array($remoteModel, ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Transcription model is unsupported');
        }
        $config = (new OpenAiCompatibleAudioTranscriptionConfigPolicy())->normalize($providerConfig);
        $sample = $this->sample();
        if ($remoteModel === 'glm-asr-2512') {
            $this->verifyGlm($config, $sample, $timeoutMs);
            return;
        }
        $diarization = $remoteModel === 'gpt-4o-transcribe-diarize';
        $runtime = new OpenAiCompatibleAudioRuntime(
            (new HttpsProviderEndpointResolver())->audioTranscriptions($config),
            $config['api_key'], $config['extra_headers'], $remoteModel,
            $timeoutMs, $config['response_max_bytes'], 'none',
        );
        $request = (new NeuronAudioTranscriptionRequestBuilder())->openAi(
            $runtime,
            new ControlledAudioInput($sample, new VerifiedPrivateAudioReference(
                'saf_' . str_repeat('v', 32), 'audio/wav', self::SAMPLE_SHA256,
            )),
            ['language' => 'en', 'speaker_diarization' => $diarization, 'glossary' => []],
            verification: true,
        );
        $response = (new GuzzleOpenAiCompatibleAudioTransport())->send($request);
        if ($response->truncated || $response->status < 200 || $response->status >= 300) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Transcription verification outcome is ambiguous', true);
        }
        $result = (new OpenAiCompatibleAudioResponseNormalizer())->normalize($response->body, $diarization);
        foreach ($result['segments'] as $segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null) && trim($segment['text']) !== '') {
                return;
            }
        }
        throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Transcription verification returned no recognized speech');
    }

    /** @param array<string,mixed> $config */
    private function verifyGlm(array $config, string $sample, int $timeoutMs): void
    {
        if ($timeoutMs < 1000 || $timeoutMs > 120000) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The GLM ASR timeout is invalid');
        }
        $input = (new GlmAsrAudioInputPolicy())->verify(
            new ControlledAudioInput($sample, new VerifiedPrivateAudioReference(
                'saf_' . str_repeat('v', 32), 'audio/wav', self::SAMPLE_SHA256,
            )),
            (string) config('sand_ai_audio_execution.glm_asr_audio.probe_executable', ''),
        );
        $runtime = new OpenAiCompatibleAudioRuntime(
            (new HttpsProviderEndpointResolver())->audioTranscriptions($config),
            $config['api_key'], $config['extra_headers'], 'glm-asr-2512',
            $timeoutMs, $config['response_max_bytes'], 'none',
        );
        $client = new GlmAsrAudioTranscriptionClient();
        $response = (new GuzzleOpenAiCompatibleAudioTransport())->send($client->request($runtime, $input, []));
        if ($response->truncated || $response->status < 200 || $response->status >= 300) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Transcription verification outcome is ambiguous', true);
        }
        $result = $client->normalize($response->body, (string) $input->verifiedDurationSeconds);
        if (($result['segments'][0]['text'] ?? '') === '') {
            throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Transcription verification returned no recognized speech');
        }
    }
}
