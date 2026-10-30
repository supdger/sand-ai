<?php

declare(strict_types=1);

namespace app\Application\Audio;

/** One canonical source-provenance builder shared by local and external ASR paths. */
final class AudioTranscriptionSourcedResultBuilder
{
    /** @param array{segments:list<array<string,mixed>>,audio_seconds:string,warnings:list<string>} $normalized @return array<string,mixed> */
    public function build(VerifiedPrivateAudioReference $reference, array $normalized, string $driverCode, string $driverVersion = '1.0.0', ?string $granularity = null): array
    {
        if ($granularity !== null && !in_array($granularity, ['engine_segment', 'input_range_only'], true)) {
            throw new \InvalidArgumentException('Unsupported audio source granularity');
        }
        $segments = []; $fieldSources = [];
        foreach ($normalized['segments'] as $index => $segment) {
            $source = ['file_ref' => $reference->fileRef, 'content_sha256' => $reference->contentSha256, 'locator' => ['type' => 'audio_time_range', 'start_ms' => $segment['start_ms'], 'end_ms' => $segment['end_ms']]];
            if ($granularity !== null) $source['locator']['granularity'] = $granularity;
            $segments[] = $segment + ['source' => $source];
            foreach (['start_ms', 'end_ms', 'speaker', 'text', 'confidence', 'warnings'] as $field) $fieldSources[] = ['field_path' => "/segments/{$index}/{$field}", 'source' => $source];
        }
        return ['schema' => ['code' => 'sand_ai.audio_transcription', 'version' => '1.0.0'], 'segments' => $segments, 'field_sources' => $fieldSources, 'warnings' => $normalized['warnings'], 'usage' => ['audio_seconds' => $normalized['audio_seconds']], 'review_required' => true, 'technical' => ['capability_code' => 'audio_transcription', 'capability_version' => 'audio_transcription@1.0.0', 'driver_code' => $driverCode, 'driver_version' => $driverVersion]];
    }
}
