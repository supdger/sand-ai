<?php

declare(strict_types=1);

return [
    'external_enabled' => false,
    // Opt-in only after registered 106–108 installation and independent runtime acceptance.
    'long_recording' => [
        'enabled' => false,
        'private_root' => (string) env('SAND_AI_AUDIO_CHUNK_PRIVATE_ROOT', ''),
        'store_code' => (string) env('SAND_AI_AUDIO_CHUNK_STORE_CODE', ''),
        'capacity_bytes' => (int) env('SAND_AI_AUDIO_CHUNK_CAPACITY_BYTES', 0),
        'storage_timeout_seconds' => 120,
    ],
    // No defaults: an absent executable/model keeps local ASR not_configured.
    'local_whisper_cpp' => [
        'executable' => (string) env('SAND_AI_WHISPER_CPP_EXECUTABLE', ''),
        'model_path' => (string) env('SAND_AI_WHISPER_CPP_MODEL_PATH', ''),
        'probe_executable' => (string) env('SAND_AI_FFPROBE_EXECUTABLE', ''),
        'converter_executable' => (string) env('SAND_AI_FFMPEG_EXECUTABLE', ''),
        'timeout_seconds' => (int) env('SAND_AI_WHISPER_CPP_TIMEOUT_SECONDS', 120),
    ],
    // SAND-121E does not enable outbound calls. A future authorized runtime must
    // explicitly set this and publish an approved_cloud capability route.
    'glm_asr_audio' => [
        'enabled' => false,
        'provider_idempotency_mode' => 'none',
        'synchronous_only' => true,
        'probe_executable' => (string) env('SAND_AI_FFPROBE_EXECUTABLE', ''),
    ],
    'openai_compatible_audio' => [
        'enabled' => false,
        'provider_idempotency_mode' => 'none',
        'synchronous_only' => true,
    ],
];
