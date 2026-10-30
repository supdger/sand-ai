<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTranscriptionResultPolicy;
use app\Application\Audio\ControlledAudioInput;

/** Vendor multipart and text-only response mapping stay inside the ASR capability boundary. */
final class GlmAsrAudioTranscriptionClient
{
    /** @param list<string> $glossary */
    public function request(OpenAiCompatibleAudioRuntime $runtime, ControlledAudioInput $input, array $glossary): OpenAiCompatibleAudioHttpRequest
    {
        if ($runtime->remoteModel() !== 'glm-asr-2512' || $input->verifiedDurationSeconds === null
            || $runtime->providerIdempotencyMode() !== 'none') {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The GLM ASR target is unavailable');
        }
        GlmAsrAudioInputPolicy::durationMs($input->verifiedDurationSeconds);
        $mediaType = $input->reference->mediaType;
        if (!in_array($mediaType, ['audio/wav', 'audio/mpeg'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_MEDIA_UNSUPPORTED', 'GLM ASR accepts WAV or MP3 recordings only');
        }
        return (new NeuronAudioTranscriptionRequestBuilder())->glm($runtime, $input, $glossary);
    }

    /** @return array<string,mixed> */
    public function normalize(string $body, string $verifiedDurationSeconds): array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw $this->invalid();
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || array_diff(array_keys($decoded), ['id', 'created', 'request_id', 'model', 'text']) !== []
            || ($decoded['model'] ?? null) !== 'glm-asr-2512'
            || !is_string($decoded['text'] ?? null) || !mb_check_encoding($decoded['text'], 'UTF-8')
            || mb_strlen($decoded['text'], 'UTF-8') > 10000 || str_contains($decoded['text'], "\0")) {
            throw $this->invalid();
        }
        foreach (['id', 'request_id'] as $key) {
            if (array_key_exists($key, $decoded) && (!is_string($decoded[$key]) || trim($decoded[$key]) === '' || strlen($decoded[$key]) > 191)) throw $this->invalid();
        }
        if (array_key_exists('created', $decoded) && (!is_int($decoded['created']) || $decoded['created'] < 0)) throw $this->invalid();
        $durationMs = GlmAsrAudioInputPolicy::durationMs($verifiedDurationSeconds);
        $text = trim($decoded['text']);
        $warnings = ['confidence_unavailable', 'timing_unavailable'];
        if ($text === '') $warnings[] = 'no_recognized_speech';
        return (new AudioTranscriptionResultPolicy())->normalize([
            'segments' => [[
                'start_ms' => 0, 'end_ms' => $durationMs, 'speaker' => null,
                'text' => $text, 'confidence' => null, 'warnings' => $warnings,
            ]],
            'audio_seconds' => $verifiedDurationSeconds,
            'warnings' => ['timing_unavailable'],
        ], false);
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID', 'The GLM ASR response is invalid');
    }
}
