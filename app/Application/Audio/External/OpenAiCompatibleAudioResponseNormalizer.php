<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTranscriptionResultPolicy;

/** Strictly maps the two documented OpenAI-compatible response shapes to SandAI's canonical result. */
final class OpenAiCompatibleAudioResponseNormalizer
{
    /** @return array<string,mixed> */
    public function normalize(string $body, bool $diarization, ?string $verifiedDurationSeconds = null): array
    {
        try { $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw $this->invalid(); }
        if (!is_array($decoded) || array_is_list($decoded)) throw $this->invalid();
        $declaredDuration = $this->milliseconds($decoded['duration'] ?? null);
        $duration = $declaredDuration;
        if ($verifiedDurationSeconds !== null) {
            if (preg_match('/\A(0|[1-9][0-9]{0,8})\.[0-9]{3}\z/D', $verifiedDurationSeconds, $parts) !== 1) throw $this->invalid();
            $duration = ((int) $parts[1] * 1000) + (int) substr($verifiedDurationSeconds, -3);
            if ($duration < 1) throw $this->invalid();
        }
        $segments = $decoded['segments'] ?? null;
        if (!is_array($segments) || !array_is_list($segments) || count($segments) > 10000) throw $this->invalid();
        if ($segments === []) {
            if ($duration < 1) throw $this->invalid();
            return (new AudioTranscriptionResultPolicy())->normalize([
                'segments' => [$this->uncoveredSegment(0, $duration)],
                'audio_seconds' => intdiv($duration, 1000) . '.' . str_pad((string) ($duration % 1000), 3, '0', STR_PAD_LEFT),
                'warnings' => [],
            ], $diarization);
        }
        $normalized = [];
        $coveredUntil = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment) || array_is_list($segment)) throw $this->invalid();
            $start = $this->milliseconds($segment['start'] ?? null);
            $end = $this->milliseconds($segment['end'] ?? null);
            $text = $segment['text'] ?? null;
            if ($start < $coveredUntil || $end <= $start || $end > $duration || $end > $declaredDuration || !is_string($text)) throw $this->invalid();
            $unrecognized = trim($text) === '';
            $speaker = $segment['speaker'] ?? null;
            if ($diarization) {
                if ($speaker !== null && (!is_string($speaker) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $speaker) !== 1)) throw $this->invalid();
                if (!$unrecognized && $speaker === null) throw $this->invalid();
            } elseif ($speaker !== null) {
                throw $this->invalid();
            }
            if ($start > $coveredUntil) $normalized[] = $this->uncoveredSegment($coveredUntil, $start);
            $normalized[] = ['start_ms' => $start, 'end_ms' => $end, 'speaker' => $speaker, 'text' => trim($text), 'confidence' => null, 'warnings' => $unrecognized ? ['confidence_unavailable', 'no_recognized_speech'] : ['confidence_unavailable']];
            if (count($normalized) > 10000) throw $this->invalid();
            $coveredUntil = $end;
        }
        if ($coveredUntil < $duration) $normalized[] = $this->uncoveredSegment($coveredUntil, $duration);
        if (count($normalized) > 10000) throw $this->invalid();
        try {
            return (new AudioTranscriptionResultPolicy())->normalize([
                'segments' => $normalized,
                'audio_seconds' => intdiv($duration, 1000) . '.' . str_pad((string) ($duration % 1000), 3, '0', STR_PAD_LEFT),
                'warnings' => [],
            ], $diarization);
        } catch (\Throwable) { throw $this->invalid(); }
    }

    /** @return array{start_ms:int,end_ms:int,speaker:null,text:string,confidence:null,warnings:list<string>} */
    private function uncoveredSegment(int $startMs, int $endMs): array
    {
        return [
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'speaker' => null,
            'text' => '',
            'confidence' => null,
            'warnings' => ['confidence_unavailable', 'no_recognized_speech', 'uncovered_audio_range'],
        ];
    }

    private function milliseconds(mixed $value): int
    {
        if (!is_int($value) && !is_float($value)) throw $this->invalid();
        $seconds = (float) $value;
        if (!is_finite($seconds) || $seconds < 0.0 || $seconds >= 1000000000.0) throw $this->invalid();
        return (int) round($seconds * 1000.0, 0, PHP_ROUND_HALF_UP);
    }
    private function invalid(): ApiProblem { return new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID', 'OpenAI-compatible audio response is invalid'); }
}
