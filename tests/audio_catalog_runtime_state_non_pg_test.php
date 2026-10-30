<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
if (!class_exists(\app\Api\Support\ApiProblem::class)) {
    require $root . '/app/Api/Support/ApiProblem.php';
}
if (!class_exists(\app\Application\Audio\AudioTranscriptionCatalog::class)) {
    require getenv('SANDPACKAGE_TEST_CATALOG') ?: $root . '/app/Application/Audio/AudioTranscriptionCatalog.php';
}

use app\Application\Audio\AudioTranscriptionCatalog;

$catalog = require getenv('SANDPACKAGE_TEST_METADATA') ?: $root . '/config/sand_ai_audio_transcription.php';
$states = static fn (array $result): array => array_column($result['adapter_kinds'], 'runtime_state');

$absent = AudioTranscriptionCatalog::fromConfiguration($catalog, []);
if ($absent['runtime_state'] !== 'route_available'
    || $absent['technical']['workload_endpoint_installed'] !== true
    || $states($absent) !== ['not_configured', 'not_configured']) {
    throw new RuntimeException('Installed route was confused with a configured engine');
}

$model = tempnam(sys_get_temp_dir(), 'sand-ai-audio-model-');
if (!is_string($model)) {
    throw new RuntimeException('Could not prepare isolated audio configuration fixture');
}
try {
    $local = [
        'local_whisper_cpp' => [
            'executable' => PHP_BINARY,
            'model_path' => $model,
            'probe_executable' => PHP_BINARY,
            'converter_executable' => PHP_BINARY,
            'timeout_seconds' => 120,
        ],
    ];
    $configured = AudioTranscriptionCatalog::fromConfiguration($catalog, $local);
    if ($states($configured) !== ['configured', 'not_configured']) {
        throw new RuntimeException('Configured local engine was not reported');
    }
    $public = json_encode($configured, JSON_THROW_ON_ERROR);
    if (str_contains($public, PHP_BINARY) || str_contains($public, $model)) {
        throw new RuntimeException('Audio preflight leaked host paths');
    }
    $local['external_enabled'] = true;
    $local['openai_compatible_audio'] = ['enabled' => true];
    $both = AudioTranscriptionCatalog::fromConfiguration($catalog, $local);
    if ($states($both) !== ['configured', 'configured']) {
        throw new RuntimeException('External switch was not reported independently');
    }
    $local['local_whisper_cpp']['model_path'] = '/missing/model.bin';
    $missing = AudioTranscriptionCatalog::fromConfiguration($catalog, $local);
    if ($states($missing) !== ['not_configured', 'configured']) {
        throw new RuntimeException('Missing local model was presented as configured');
    }
    $local['local_whisper_cpp']['model_path'] = $model;
    $local['local_whisper_cpp']['probe_executable'] = '/missing/ffprobe';
    if ($states(AudioTranscriptionCatalog::fromConfiguration($catalog, $local)) !== ['not_configured', 'configured']) {
        throw new RuntimeException('Missing duration probe was presented as configured');
    }
    $local['local_whisper_cpp']['probe_executable'] = PHP_BINARY;
    $local['local_whisper_cpp']['converter_executable'] = '/missing/ffmpeg';
    if ($states(AudioTranscriptionCatalog::fromConfiguration($catalog, $local)) !== ['not_configured', 'configured']) {
        throw new RuntimeException('Missing audio converter was presented as configured');
    }
} finally {
    unlink($model);
}
echo "audio-catalog-runtime-state 7/7\n";
