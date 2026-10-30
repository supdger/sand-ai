<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Fixed local-only process configuration. It never accepts request-provided paths. */
final readonly class WhisperCppExecutionConfig
{
    public function __construct(
        public string $executable,
        public string $modelPath,
        public int $timeoutSeconds,
        public string $probeExecutable,
        public string $converterExecutable,
    )
    {
        if ($executable === '' || $modelPath === '' || $probeExecutable === '' || $converterExecutable === '') {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local audio engine is not configured');
        }
        if (!str_starts_with($executable, '/') || !str_starts_with($modelPath, '/')
            || !str_starts_with($probeExecutable, '/')
            || !str_starts_with($converterExecutable, '/')
            || $timeoutSeconds < 1 || $timeoutSeconds > 3600) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local audio engine configuration is invalid');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['executable'] ?? ''),
            (string) ($config['model_path'] ?? ''),
            is_int($config['timeout_seconds'] ?? null) ? $config['timeout_seconds'] : 0,
            (string) ($config['probe_executable'] ?? ''),
            (string) ($config['converter_executable'] ?? ''),
        );
    }
}
