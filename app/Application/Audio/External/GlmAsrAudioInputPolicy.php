<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\LocalAudioDurationProbe;
use app\Application\Usage\AudioSeconds;

/** Short-input limits are verified before the durable send claim, never inferred from text. */
final class GlmAsrAudioInputPolicy
{
    public const MAX_BYTES = 25_000_000;
    public const MAX_DURATION_MS = 30_000;

    public function verify(ControlledAudioInput $input, string $probeExecutable): ControlledAudioInput
    {
        if (!in_array($input->reference->mediaType, ['audio/wav', 'audio/mpeg'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_MEDIA_UNSUPPORTED', 'GLM ASR accepts WAV or MP3 recordings only');
        }
        clearstatcache(true, $input->temporaryPath);
        $bytes = filesize($input->temporaryPath);
        if (!is_int($bytes) || $bytes < 1) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio input is unavailable');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_INPUT_TOO_LARGE', 'GLM ASR accepts recordings of at most 25000000 bytes');
        }
        $header = file_get_contents($input->temporaryPath, false, null, 0, 12);
        $wav = is_string($header) && strlen($header) >= 12
            && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE';
        $mp3 = is_string($header) && strlen($header) >= 4
            && (str_starts_with($header, 'ID3')
                || (ord($header[0]) === 255 && (ord($header[1]) & 0xE0) === 0xE0
                    && (ord($header[1]) & 0x18) !== 0x08 && (ord($header[1]) & 0x06) === 0x02
                    && (ord($header[2]) & 0x0C) !== 0x0C
                    && (ord($header[2]) & 0xF0) !== 0 && (ord($header[2]) & 0xF0) !== 0xF0));
        if (($input->reference->mediaType === 'audio/wav' && !$wav)
            || ($input->reference->mediaType === 'audio/mpeg' && !$mp3)) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_MEDIA_UNSUPPORTED', 'The private recording does not have the declared WAV or MP3 container header');
        }
        $seconds = (new LocalAudioDurationProbe())->seconds($probeExecutable, $input->temporaryPath, 30);
        self::durationMs($seconds);
        return new ControlledAudioInput($input->temporaryPath, $input->reference, $seconds);
    }

    public static function durationMs(string $seconds): int
    {
        $canonical = (new AudioSeconds($seconds))->value;
        [$whole, $fraction] = explode('.', $canonical, 2);
        $milliseconds = ((int) $whole) * 1000 + (int) substr($fraction, 0, 3);
        if (preg_match('/[1-9]/', substr($fraction, 3)) === 1) $milliseconds++;
        if ($milliseconds > self::MAX_DURATION_MS) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_INPUT_TOO_LONG', 'GLM ASR accepts recordings of at most 30 seconds; use a supported long-recording route');
        }
        if ($milliseconds < 1) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio duration is invalid');
        }
        return $milliseconds;
    }

    /** @param array<string,mixed> $parameters */
    public static function assertParameters(array $parameters): void
    {
        if (($parameters['speaker_diarization'] ?? null) !== false) {
            throw new ApiProblem('SAND_AI_AUDIO_DIARIZATION_UNAVAILABLE', 'GLM ASR does not return speaker labels');
        }
        if (($parameters['language'] ?? null) !== 'auto') {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR', 'GLM ASR detects language automatically; set language to auto');
        }
    }
}
