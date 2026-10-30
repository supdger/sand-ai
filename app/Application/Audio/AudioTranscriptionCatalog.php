<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Read-only capability metadata; it never creates configuration or implies runtime availability. */
final class AudioTranscriptionCatalog
{
    /** @return array<string,mixed> */
    public static function index(): array
    {
        $configuration = require dirname(__DIR__, 3) . '/config/sand_ai_audio_transcription.php';
        if (!is_array($configuration)) {
            self::invalid();
        }

        $execution = config('sand_ai_audio_execution', []);
        return self::fromConfiguration($configuration, is_array($execution) ? $execution : []);
    }

    /** @param array<string,mixed> $configuration @param array<string,mixed> $execution @return array<string,mixed> */
    public static function fromConfiguration(array $configuration, array $execution = []): array
    {
        self::exactKeys($configuration, ['version', 'display_name', 'plain_summary', 'runtime_state', 'readiness_note', 'adapter_kinds']);
        if (($configuration['version'] ?? null) !== 'audio_transcription_catalog@2'
            || ($configuration['runtime_state'] ?? null) !== 'route_available') {
            self::invalid();
        }
        foreach (['display_name', 'plain_summary', 'readiness_note'] as $field) {
            self::nonEmptyText($configuration[$field] ?? null);
        }
        $adapters = $configuration['adapter_kinds'] ?? null;
        if (!is_array($adapters) || !array_is_list($adapters) || count($adapters) !== 2) {
            self::invalid();
        }
        $projected = [];
        $seen = [];
        foreach ($adapters as $adapter) {
            self::exactKeys($adapter, ['code', 'display_name', 'runtime_state', 'supported_data_egress_policies', 'private_input_rule', 'temporary_cleanup_rule']);
            $code = $adapter['code'] ?? null;
            if (!is_string($code) || !in_array($code, ['local', 'external'], true) || isset($seen[$code])
                || ($adapter['runtime_state'] ?? null) !== 'not_configured') {
                self::invalid();
            }
            $seen[$code] = true;
            foreach (['display_name', 'private_input_rule', 'temporary_cleanup_rule'] as $field) {
                self::nonEmptyText($adapter[$field] ?? null);
            }
            $policies = $adapter['supported_data_egress_policies'] ?? null;
            $expectedPolicies = $code === 'local' ? ['local_only', 'private_only', 'approved_cloud'] : ['approved_cloud'];
            if ($policies !== $expectedPolicies) {
                self::invalid();
            }
            $projected[] = [
                'display_name' => $adapter['display_name'],
                'runtime_state' => self::adapterConfigured($code, $execution) ? 'configured' : 'not_configured',
                'supported_data_egress_policies' => $policies,
                'private_input_rule' => $adapter['private_input_rule'],
                'temporary_cleanup_rule' => $adapter['temporary_cleanup_rule'],
                'technical' => ['adapter_kind' => $code],
            ];
        }
        if (array_keys($seen) !== ['local', 'external']) {
            self::invalid();
        }
        self::rejectSensitiveConfiguration($configuration);

        return [
            'display_name' => $configuration['display_name'],
            'plain_summary' => $configuration['plain_summary'],
            'runtime_state' => 'route_available',
            'readiness_note' => $configuration['readiness_note'],
            'adapter_kinds' => $projected,
            'technical' => [
                'capability_code' => 'audio_transcription',
                'capability_version' => 'audio_transcription@1.0.0',
                'catalog_version' => 'audio_transcription_catalog@2',
                'workload_endpoint_installed' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $execution */
    private static function adapterConfigured(string $code, array $execution): bool
    {
        if ($code === 'external') {
            $external = $execution['openai_compatible_audio'] ?? null;
            $glm = $execution['glm_asr_audio'] ?? null;
            $probe = is_array($glm) ? ($glm['probe_executable'] ?? null) : null;
            $glmConfigured = is_array($glm) && ($glm['enabled'] ?? null) === true
                && ($glm['provider_idempotency_mode'] ?? null) === 'none'
                && ($glm['synchronous_only'] ?? null) === true
                && is_string($probe) && str_starts_with($probe, '/')
                && is_file($probe) && is_executable($probe);
            $local = $execution['local_whisper_cpp'] ?? null;
            $externalProbe = is_array($local) ? ($local['probe_executable'] ?? null) : null;
            $externalConfigured = is_array($external) && ($external['enabled'] ?? null) === true
                && ($external['provider_idempotency_mode'] ?? null) === 'none'
                && ($external['synchronous_only'] ?? null) === true
                && is_string($externalProbe) && str_starts_with($externalProbe, '/')
                && is_file($externalProbe) && is_executable($externalProbe);
            return ($execution['external_enabled'] ?? null) === true
                && ($externalConfigured || $glmConfigured);
        }
        $local = $execution['local_whisper_cpp'] ?? null;
        if (!is_array($local)) {
            return false;
        }
        $executable = $local['executable'] ?? null;
        $modelPath = $local['model_path'] ?? null;
        $probeExecutable = $local['probe_executable'] ?? null;
        $converterExecutable = $local['converter_executable'] ?? null;
        $timeout = $local['timeout_seconds'] ?? null;
        return is_string($executable) && str_starts_with($executable, '/')
            && is_file($executable) && is_executable($executable)
            && is_string($modelPath) && str_starts_with($modelPath, '/')
            && is_file($modelPath) && is_readable($modelPath)
            && is_string($probeExecutable) && str_starts_with($probeExecutable, '/')
            && is_file($probeExecutable) && is_executable($probeExecutable)
            && is_string($converterExecutable) && str_starts_with($converterExecutable, '/')
            && is_file($converterExecutable) && is_executable($converterExecutable)
            && is_int($timeout) && $timeout >= 1 && $timeout <= 3600;
    }

    /** @param array<mixed> $value @param list<string> $keys */
    private static function exactKeys(mixed $value, array $keys): void
    {
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            self::invalid();
        }
    }

    private static function nonEmptyText(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > 500) {
            self::invalid();
        }
    }

    private static function rejectSensitiveConfiguration(mixed $value): void
    {
        if (!is_array($value)) {
            if (is_string($value) && self::containsAccessLocation($value)) {
                self::invalid();
            }
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match('/(?:secret|password|credential|token|api_?key|endpoint|url)/i', $key) === 1) {
                self::invalid();
            }
            self::rejectSensitiveConfiguration($child);
        }
    }

    private static function containsAccessLocation(string $value): bool
    {
        return preg_match('#(?:[a-z][a-z0-9+.-]*)://#i', $value) === 1
            || preg_match('#\A(?:/|\\\\|[A-Za-z]:[\\\\/]|\./|\.\./|~/|~\\\\)#', $value) === 1
            || preg_match('#\A(?:[^\\s/\\\\]+[\\\\/])+[^\\s/\\\\]+\z#', $value) === 1;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_CATALOG_INVALID', 'The audio transcription catalogue is invalid');
    }
}
