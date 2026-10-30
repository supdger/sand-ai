<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

final class AudioTranscriptionResultPolicy
{
    /**
     * @param array<string,mixed> $result
     * @return array{segments:list<array{start_ms:int,end_ms:int,speaker:?string,text:string,confidence:?string,warnings:list<string>}>,audio_seconds:string,warnings:list<string>}
     */
    public function normalize(array $result, bool $speakerDiarization): array
    {
        $this->exactKeys($result, ['segments', 'audio_seconds', 'warnings'], 'result');
        $segments = $result['segments'] ?? null;
        if (!is_array($segments) || !array_is_list($segments) || $segments === [] || count($segments) > 10000) {
            $this->fail('segments must contain between 1 and 10000 entries');
        }
        $normalizedSegments = [];
        $previousEnd = 0;
        $maximumEnd = 0;
        foreach ($segments as $index => $segment) {
            $this->exactKeys($segment, ['start_ms', 'end_ms', 'speaker', 'text', 'confidence', 'warnings'], "segments[{$index}]");
            $start = $segment['start_ms'] ?? null;
            $end = $segment['end_ms'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || ($index > 0 && $start < $previousEnd)) {
                $this->fail('segment ranges must be ordered, non-overlapping non-negative integer milliseconds');
            }
            $speaker = $segment['speaker'] ?? null;
            if ($speaker !== null && (!is_string($speaker) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $speaker) !== 1)) {
                $this->fail('segment speaker must be null or a stable opaque label');
            }
            $text = $segment['text'] ?? null;
            if (!is_string($text) || mb_strlen($text, 'UTF-8') > 10000) {
                $this->fail('segment text must be a string of at most 10000 characters');
            }
            if ($speakerDiarization && $speaker === null && trim($text) !== '') {
                $this->fail('speaker is required when speaker diarization is requested');
            }
            $confidence = $segment['confidence'] ?? null;
            if ($confidence !== null && (!is_string($confidence) || preg_match('/\A(?:0\.[0-9]{4}|1\.0000)\z/D', $confidence) !== 1)) {
                $this->fail('segment confidence must be a canonical decimal string from 0.0000 to 1.0000');
            }
            $warnings = $this->warnings($segment['warnings'] ?? null, "segments[{$index}].warnings");
            if ($confidence === null && !in_array('confidence_unavailable', $warnings, true)
                && !in_array('local_confidence_unavailable', $warnings, true)) {
                $this->fail('unknown confidence must be marked as unavailable');
            }
            if ((trim($text) === '') !== in_array('no_recognized_speech', $warnings, true)) {
                $this->fail('an empty segment must be marked as having no recognized speech');
            }
            $normalizedSegments[] = [
                'start_ms' => $start,
                'end_ms' => $end,
                'speaker' => $speaker,
                'text' => trim($text),
                'confidence' => $confidence,
                'warnings' => $warnings,
            ];
            $previousEnd = $end;
            $maximumEnd = max($maximumEnd, $end);
        }
        $audioSeconds = $result['audio_seconds'] ?? null;
        if (!is_string($audioSeconds) || preg_match('/\A(0|[1-9][0-9]{0,8})\.[0-9]{3}\z/D', $audioSeconds, $matches) !== 1) {
            $this->fail('audio_seconds must be a canonical non-negative decimal string with three fractional digits');
        }
        $durationMs = ((int) $matches[1] * 1000) + (int) substr($audioSeconds, -3);
        if ($durationMs < $maximumEnd) {
            $this->fail('audio_seconds cannot be shorter than the last segment');
        }

        return [
            'segments' => $normalizedSegments,
            'audio_seconds' => $audioSeconds,
            'warnings' => $this->warnings($result['warnings'] ?? null, 'warnings'),
        ];
    }

    /** @return list<string> */
    private function warnings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            $this->fail("{$field} must be a list with at most 50 entries");
        }
        $warnings = [];
        foreach ($value as $warning) {
            if (!is_string($warning) || trim($warning) === '' || trim($warning) !== $warning
                || mb_strlen($warning, 'UTF-8') > 160 || in_array($warning, $warnings, true)) {
                $this->fail("{$field} entries must be unique non-empty strings of at most 160 characters");
            }
            $warnings[] = $warning;
        }
        return $warnings;
    }

    /** @param list<string> $keys */
    private function exactKeys(mixed $value, array $keys, string $field): void
    {
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            $this->fail("{$field} contains unsupported fields");
        }
    }

    private function fail(string $message): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', $message);
    }
}
