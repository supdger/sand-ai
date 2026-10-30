<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Measures the entire private audio file before local ASR usage is recorded. */
final class LocalAudioDurationProbe
{
    public function seconds(string $executable, string $file, int $timeoutSeconds): string
    {
        $output = $this->read($executable, $file, $timeoutSeconds, [
            '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1',
        ], 64);
        return $this->duration($output);
    }

    /** Verifies the same first audio stream selected by the private converter. */
    public function sourceSeconds(string $executable, string $file, int $timeoutSeconds): string
    {
        $output = $this->read($executable, $file, $timeoutSeconds, [
            '-select_streams', 'a:0', '-show_entries',
            'format=format_name,duration,start_time:stream=start_time', '-of', 'json',
        ], 4096);
        $decoded = json_decode($output, true);
        $format = is_array($decoded) ? ($decoded['format'] ?? null) : null;
        $streams = is_array($decoded) ? ($decoded['streams'] ?? null) : null;
        if (!is_array($format) || !is_string($format['duration'] ?? null)
            || !is_string($format['format_name'] ?? null)
            || !is_array($streams) || !array_is_list($streams) || count($streams) !== 1
            || !is_array($streams[0])) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio source timeline could not be verified');
        }
        $containerStart = $format['start_time'] ?? null;
        $audioStart = $streams[0]['start_time'] ?? null;
        // WAV and native FLAC have an implicit sample clock starting at zero.
        // Timestamped containers must disclose both origins; do not infer an
        // audio offset from duration, a suffix or the first recognized words.
        $sampleClock = in_array($format['format_name'], ['wav', 'flac'], true);
        if (!($sampleClock && $containerStart === null && $audioStart === null)
            && (!$this->timestamp($containerStart) || !$this->timestamp($audioStart))) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio source timeline could not be verified');
        }
        return $this->duration($format['duration']);
    }

    private function timestamp(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A-?(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,9})?\z/D', $value) === 1;
    }

    /** @param list<string> $options */
    private function read(string $executable, string $file, int $timeoutSeconds, array $options, int $maxBytes): string
    {
        if (!is_file($executable) || !is_executable($executable) || !is_file($file)
            || $timeoutSeconds < 1) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local audio duration probe is unavailable');
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        try {
            $process = proc_open(
                [$executable, '-v', 'error', ...$options, $file],
                [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']],
                $pipes,
            );
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration probe could not start', true);
        }
        if (!is_resource($process) || !isset($pipes[1]) || !is_resource($pipes[1])) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration probe could not start', true);
        }
        stream_set_blocking($pipes[1], false);
        $started = hrtime(true);
        $output = '';
        $exitCode = -1;
        try {
            while (true) {
                $chunk = fread($pipes[1], 128);
                if ($chunk === false) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration could not be read', true);
                }
                $output .= $chunk;
                if (strlen($output) > $maxBytes) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration is invalid');
                }
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration probe status is unavailable', true);
                }
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    $tail = stream_get_contents($pipes[1], $maxBytes + 1);
                    $output .= is_string($tail) ? $tail : '';
                    break;
                }
                if (hrtime(true) - $started >= $timeoutSeconds * 1_000_000_000) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio duration probe timed out', true);
                }
                usleep(50_000);
            }
        } finally {
            fclose($pipes[1]);
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
            $closedExitCode = proc_close($process);
            if ($exitCode === -1) $exitCode = $closedExitCode;
        }
        if ($exitCode !== 0 || strlen($output) > $maxBytes) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio duration could not be verified');
        }
        return trim($output);
    }

    private function duration(string $output): string
    {
        $output = trim($output);
        if (strlen($output) > 64
            || preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,9}))?\z/D', $output, $matches) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio duration could not be verified');
        }
        $fraction = $matches[2] ?? '';
        $milliseconds = (int) $matches[1] * 1000
            + (int) str_pad(substr($fraction, 0, 3), 3, '0');
        if (strlen($fraction) > 3 && preg_match('/[1-9]/', substr($fraction, 3)) === 1) {
            $milliseconds++;
        }
        if ($milliseconds < 1 || $milliseconds > 999_999_999_999) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The private audio duration is invalid');
        }
        return intdiv($milliseconds, 1000) . '.'
            . str_pad((string) ($milliseconds % 1000), 3, '0', STR_PAD_LEFT);
    }
}
