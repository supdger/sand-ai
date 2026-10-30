<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Preserves legacy locators without adding fields to immutable transcript evidence. */
final class AudioTimeRangeLocator
{
    /** @param array<string,mixed> $locator @return array<string,mixed> */
    public static function normalize(array $locator, int $startMs, int $endMs): array
    {
        $keys = ['type', 'start_ms', 'end_ms'];
        if (array_key_exists('granularity', $locator)) {
            $keys[] = 'granularity';
            if (!in_array($locator['granularity'], ['engine_segment', 'input_range_only'], true)) {
                self::invalid();
            }
        }
        if (count($locator) !== count($keys) || array_diff(array_keys($locator), $keys) !== []
            || ($locator['type'] ?? null) !== 'audio_time_range'
            || ($locator['start_ms'] ?? null) !== $startMs
            || ($locator['end_ms'] ?? null) !== $endMs
            || $startMs < 0 || $endMs <= $startMs) {
            self::invalid();
        }
        $normalized = ['type' => 'audio_time_range', 'start_ms' => $startMs, 'end_ms' => $endMs];
        if (array_key_exists('granularity', $locator)) $normalized['granularity'] = $locator['granularity'];
        return $normalized;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'The audio source range is invalid');
    }
}
