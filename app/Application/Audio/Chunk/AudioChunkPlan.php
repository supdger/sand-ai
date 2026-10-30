<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;

/** Exact sample coverage for normalized 16 kHz mono signed-16 PCM; no overlapping billable input. */
final readonly class AudioChunkPlan
{
    public const SAMPLE_RATE = 16000;
    public const SAMPLES_PER_CHUNK = 480000;
    public const MAX_SAMPLES = 230400000; // Four hours, at most 480 provider calls.
    public const RECIPE = 'pcm_s16le_mono_16000_fixed30/v1';

    public function __construct(public int $sampleCount)
    {
        if ($sampleCount < 1 || $sampleCount > self::MAX_SAMPLES) {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_CAPACITY_EXCEEDED', 'The recording exceeds the four-hour processing limit');
        }
    }

    public function count(): int
    {
        return intdiv($this->sampleCount + self::SAMPLES_PER_CHUNK - 1, self::SAMPLES_PER_CHUNK);
    }

    /** @return array{sequence_no:int,start_sample:int,end_sample:int,start_ms:int,end_ms:int,byte_size:int} */
    public function chunk(int $sequence): array
    {
        if ($sequence < 1 || $sequence > $this->count()) {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'Audio chunk is outside the frozen recording');
        }
        $start = ($sequence - 1) * self::SAMPLES_PER_CHUNK;
        $end = min($start + self::SAMPLES_PER_CHUNK, $this->sampleCount);
        return ['sequence_no' => $sequence, 'start_sample' => $start, 'end_sample' => $end,
            'start_ms' => intdiv($start, 16), 'end_ms' => intdiv($end + 15, 16),
            'byte_size' => 44 + ($end - $start) * 2];
    }
}
