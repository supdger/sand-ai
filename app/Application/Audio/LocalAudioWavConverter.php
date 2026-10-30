<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Converts a private recording to the PCM input required by whisper-cli. */
final class LocalAudioWavConverter
{
    public const TIMELINE_POLICY = 'original_container_playback@1';
    private const MAX_OUTPUT_BYTES = 536870912;

    public function convert(string $executable, string $source, int $timeoutSeconds): string
    {
        if (!is_file($executable) || !is_executable($executable) || !is_file($source)
            || $timeoutSeconds < 1) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local audio converter is unavailable');
        }
        $reserved = tempnam(sys_get_temp_dir(), 'sand-ai-pcm-');
        $directory = is_string($reserved) ? $reserved . '.d' : '';
        if ($reserved === false || !mkdir($directory, 0700)) {
            if (is_string($reserved)) {
                @unlink($reserved);
            }
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'A local audio input file cannot be created', true);
        }
        @unlink($reserved);
        $output = $directory . '/input.wav';
        $completed = false;
        try {
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            try {
                $process = proc_open(
                    // Keep the first audio stream on the container playback
                    // clock. start_at_zero subtracts the container origin once;
                    // the resampler fills initial silence and timestamp gaps.
                    [$executable, '-nostdin', '-v', 'error', '-y', '-copyts', '-start_at_zero', '-i', $source,
                        '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '16000',
                        '-af', 'aresample=async=1:first_pts=0:min_comp=0:min_hard_comp=0',
                        '-c:a', 'pcm_s16le', '-f', 'wav', $output],
                    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                    $pipes,
                );
            } catch (\Throwable) {
                throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio converter could not start', true);
            }
            if (!is_resource($process)) {
                throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio converter could not start', true);
            }
            $started = hrtime(true);
            $exitCode = -1;
            try {
                while (true) {
                    $status = proc_get_status($process);
                    if (!is_array($status)) {
                        throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio converter status is unavailable', true);
                    }
                    if (!$status['running']) {
                        $exitCode = (int) $status['exitcode'];
                        break;
                    }
                    clearstatcache(true, $output);
                    if (is_file($output) && filesize($output) > self::MAX_OUTPUT_BYTES) {
                        throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio input exceeds the processing limit');
                    }
                    if (hrtime(true) - $started >= $timeoutSeconds * 1_000_000_000) {
                        throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio converter timed out', true);
                    }
                    usleep(50_000);
                }
            } finally {
                $status = proc_get_status($process);
                if (is_array($status) && $status['running']) {
                    proc_terminate($process);
                    $graceStarted = hrtime(true);
                    while (hrtime(true) - $graceStarted < 500_000_000) {
                        $status = proc_get_status($process);
                        if (!is_array($status) || !$status['running']) {
                            break;
                        }
                        usleep(50_000);
                    }
                    if (is_array($status) && $status['running']) {
                        proc_terminate($process, 9);
                    }
                }
                proc_close($process);
            }
            clearstatcache(true, $output);
            if ($exitCode !== 0 || !is_file($output)) {
                throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio could not be converted');
            }
            $size = filesize($output);
            $header = file_get_contents($output, false, null, 0, 12);
            if (!is_int($size) || $size <= 44
                || $size > self::MAX_OUTPUT_BYTES || !is_string($header)
                || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF'
                || substr($header, 8, 4) !== 'WAVE') {
                throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio could not be converted');
            }
            $completed = true;
            return $output;
        } finally {
            if (!$completed) {
                @unlink($output);
                @rmdir($directory);
            }
        }
    }
}
