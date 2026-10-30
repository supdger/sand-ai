<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;

/** Streams a trusted canonical WAV into one private <=960044-byte WAV at a time. */
final class PrivatePcmAudioChunker
{
    /**
     * Caller owns normalization and private-storage durability. The callback must upload synchronously,
     * verify stored bytes, and return an opaque reference. No provider call is permitted in the callback.
     * This method owns and removes its temporary chunk even when upload fails.
     * @param \Closure(string,array<string,int>,string):string $persist
     * @return list<array<string,int|string>>
     */
    public function split(string $path, string $expectedSha256, AudioChunkPlan $plan, \Closure $persist): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1 || !is_file($path) || is_link($path)) self::invalid();
        $input = fopen($path, 'rb');
        if ($input === false) self::invalid();
        $directory = sys_get_temp_dir() . '/sand-ai-chunk-' . bin2hex(random_bytes(16));
        $outputPath = $directory . '/chunk.wav';
        $created = false;
        try {
            // Canonical WAV is deliberately strict: no arbitrary container scanning or imprecise seek.
            $bytes = 44 + $plan->sampleCount * 2;
            $stat = fstat($input);
            if (!is_array($stat) || $stat['size'] !== $bytes || $this->read($input, 44) !== $this->header($plan->sampleCount)) self::invalid();
            if (!mkdir($directory, 0700)) self::invalid();
            $created = true;
            $sourceHash = hash_init('sha256');
            hash_update($sourceHash, $this->header($plan->sampleCount));
            $chunks = [];
            for ($sequence = 1; $sequence <= $plan->count(); $sequence++) {
                $range = $plan->chunk($sequence);
                $output = fopen($outputPath, 'x+b');
                if ($output === false) self::invalid();
                try {
                    if (!chmod($outputPath, 0600)) self::invalid();
                    $header = $this->header($range['end_sample'] - $range['start_sample']);
                    $hash = hash_init('sha256');
                    hash_update($hash, $header);
                    $this->write($output, $header);
                    $remaining = $range['byte_size'] - 44;
                    while ($remaining > 0) {
                        $data = $this->read($input, min(65536, $remaining));
                        hash_update($hash, $data);
                        hash_update($sourceHash, $data);
                        $this->write($output, $data);
                        $remaining -= strlen($data);
                    }
                    if (!fflush($output)) self::invalid();
                    $sha = hash_final($hash);
                } finally {
                    fclose($output);
                }
                // Manifest cannot be frozen until the final normalized-input SHA check below succeeds.
                $ref = $persist($outputPath, $range, $sha);
                if (preg_match('/\Asaac_[a-f0-9]{32}\z/D', $ref) !== 1) self::invalid();
                $chunks[] = $range + ['chunk_ref' => $ref, 'content_sha256' => $sha];
                if (!unlink($outputPath)) self::invalid();
            }
            if (fread($input, 1) !== '' || !hash_equals($expectedSha256, hash_final($sourceHash))) self::invalid();
            return $chunks;
        } finally {
            fclose($input);
            if ($created) {
                if (is_file($outputPath)) @unlink($outputPath);
                @rmdir($directory);
            }
        }
    }

    public function header(int $samples): string
    {
        if ($samples < 1 || $samples > AudioChunkPlan::MAX_SAMPLES) self::invalid();
        $bytes = $samples * 2;
        return 'RIFF' . pack('V', 36 + $bytes) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', $bytes);
    }

    /** @param resource $input */
    private function read($input, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $part = fread($input, $bytes - strlen($data));
            if (!is_string($part) || $part === '') self::invalid();
            $data .= $part;
        }
        return $data;
    }

    /** @param resource $output */
    private function write($output, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($output, substr($data, $offset));
            if (!is_int($written) || $written < 1) self::invalid();
            $offset += $written;
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'The private PCM audio could not be split safely');
    }
}
