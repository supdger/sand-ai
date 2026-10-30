<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Host-owned local configuration is frozen privately without disclosing paths or file hashes. */
final class LocalAudioExecutionFingerprintPolicy
{
    public const VERSION = 'local_audio_execution_config@2';

    /** @return array{version:string,hmac_sha256:string} */
    public function capture(): array
    {
        $config = WhisperCppExecutionConfig::fromConfig((array) config('sand_ai_audio_execution.local_whisper_cpp', []));
        $files = [
            'executable' => $config->executable,
            'model' => $config->modelPath,
            'probe' => $config->probeExecutable,
            'converter' => $config->converterExecutable,
        ];
        $projection = [
            'driver_code' => 'local_whisper_cpp',
            'timeout_seconds' => $config->timeoutSeconds,
            'timeline_policy' => LocalAudioWavConverter::TIMELINE_POLICY,
            'files' => [],
        ];
        foreach ($files as $kind => $path) {
            if (!is_file($path) || !is_readable($path) || ($kind !== 'model' && !is_executable($path))) {
                throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The configured local transcription tool or model is unavailable');
            }
            $digest = hash_file('sha256', $path);
            $resolved = realpath($path);
            if (!is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1 || $resolved === false) {
                throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local transcription runtime cannot be fingerprinted safely');
            }
            $projection['files'][$kind] = ['configured_path' => $path, 'resolved_path' => $resolved, 'sha256' => $digest];
        }
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider execution fingerprint key is unavailable');
        }
        return [
            'version' => self::VERSION,
            'hmac_sha256' => hash_hmac(
                'sha256',
                json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                hash_hmac('sha256', 'sand-ai-local-audio-execution-fingerprint@1', $key, true),
            ),
        ];
    }
}
