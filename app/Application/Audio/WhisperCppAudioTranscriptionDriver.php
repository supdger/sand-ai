<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** local whisper.cpp adapter; invokes argv directly and never shells or exposes raw output. */
final class WhisperCppAudioTranscriptionDriver implements LocalAudioTranscriptionDriver
{
    private const MAX_FINAL_TIMESTAMP_OVERRUN_MS = 1000;
    public function __construct(private readonly WhisperCppExecutionConfig $config) {}

    public function transcribeLocal(ControlledAudioInput $input, array $request): array
    {
        if ($request['speaker_diarization']) {
            throw new ApiProblem('SAND_AI_AUDIO_DIARIZATION_UNAVAILABLE', 'The configured local audio engine cannot honestly provide diarization');
        }
        if (!is_file($this->config->executable) || !is_executable($this->config->executable)
            || !is_file($this->config->modelPath) || !is_readable($this->config->modelPath)
            || !is_file($this->config->probeExecutable) || !is_executable($this->config->probeExecutable)
            || !is_file($this->config->converterExecutable) || !is_executable($this->config->converterExecutable)) {
            throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The local audio engine is not available');
        }
        $language = $request['language'] ?? null;
        $glossary = $request['glossary'] ?? null;
        if (!is_string($language)
            || ($language !== 'auto'
                && preg_match('/\A[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?\z/D', $language) !== 1)
            || !is_array($glossary) || !array_is_list($glossary)
            || count($glossary) > 100) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR', 'The local audio hints are invalid');
        }
        $seenTerms = [];
        foreach ($glossary as $term) {
            if (!is_string($term) || trim($term) === '' || trim($term) !== $term
                || mb_strlen($term, 'UTF-8') > 80 || in_array($term, $seenTerms, true)) {
                throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR', 'The local audio hints are invalid');
            }
            $seenTerms[] = $term;
        }
        $probe = new LocalAudioDurationProbe();
        $audioSeconds = $probe->sourceSeconds(
            $this->config->probeExecutable,
            $input->temporaryPath,
            min(30, $this->config->timeoutSeconds),
        );
        $wavPath = (new LocalAudioWavConverter())->convert(
            $this->config->converterExecutable,
            $input->temporaryPath,
            $this->config->timeoutSeconds,
        );
        try {
            $wavSeconds = $probe->seconds(
                $this->config->probeExecutable,
                $wavPath,
                min(30, $this->config->timeoutSeconds),
            );
            if ($this->milliseconds($wavSeconds) > $this->milliseconds($audioSeconds)) {
                throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The converted audio exceeds the verified source timeline');
            }
        } catch (\Throwable $failure) {
            @unlink($wavPath);
            @rmdir(dirname($wavPath));
            throw $failure;
        }
        $outputBase = tempnam(sys_get_temp_dir(), 'sand-ai-whisper-');
        if ($outputBase === false) {
            @unlink($wavPath);
            @rmdir(dirname($wavPath));
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'A local audio result file cannot be created', true);
        }
        @unlink($outputBase);
        $jsonPath = $outputBase . '.json';
        try {
            $argv = [$this->config->executable, '-m', $this->config->modelPath, '-f', $wavPath,
                '-oj', '-of', $outputBase, '-l', explode('-', $language, 2)[0]];
            if ($glossary !== []) {
                $argv[] = '--prompt';
                $argv[] = implode(', ', $glossary);
            }
            $this->run($argv);
            if (!is_file($jsonPath)) throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine did not produce a result', true);
            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (!is_array($decoded)) throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine result is invalid', true);
            return $this->canonical($decoded, $audioSeconds, $wavSeconds);
        } finally {
            @unlink($jsonPath);
            @unlink($wavPath);
            @rmdir(dirname($wavPath));
        }
    }

    /** @param list<string> $argv */
    private function run(array $argv): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($argv, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine could not start', true);
        }
        $started = hrtime(true);
        $exitCode = -1;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine status is unavailable', true);
                }
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (hrtime(true) - $started >= $this->config->timeoutSeconds * 1_000_000_000) {
                    throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine timed out', true);
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
                    if (!is_array($status) || !$status['running']) break;
                    usleep(50_000);
                }
                if (is_array($status) && $status['running']) proc_terminate($process, 9);
            }
            $closedExitCode = proc_close($process);
            if ($exitCode === -1) $exitCode = $closedExitCode;
        }
        if ($exitCode !== 0) {
            throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'The local audio engine did not produce a result', true);
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function canonical(array $raw, string $audioSeconds, string $wavSeconds): array
    {
        $items = $raw['transcription'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'The local audio transcript is invalid');
        }
        $durationMs = $this->milliseconds($audioSeconds);
        $recognitionDurationMs = $this->milliseconds($wavSeconds);
        $segments = [];
        $coveredUntil = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)
                || !is_numeric($item['offsets']['from'] ?? null)
                || !is_numeric($item['offsets']['to'] ?? null)) {
                throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'The local audio transcript is invalid');
            }
            $start = (int) $item['offsets']['from'];
            $end = (int) $item['offsets']['to'];
            $timingClipped = false;
            // whisper.cpp can place the final token boundary in decoder padding.
            // Restrict only that final, overlapping range to the independently
            // probed PCM duration. Container-only tail remains uncovered.
            // Other out-of-range/overlapping data fails.
            if ($index === count($items) - 1 && $start < $recognitionDurationMs
                && $end > $recognitionDurationMs && $end - $recognitionDurationMs <= self::MAX_FINAL_TIMESTAMP_OVERRUN_MS) {
                $end = $recognitionDurationMs;
                $timingClipped = true;
            }
            if ($start < $coveredUntil || $end <= $start || $end > $recognitionDurationMs) {
                throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'The local audio transcript time ranges are invalid');
            }
            if ($start > $coveredUntil) {
                $segments[] = $this->uncoveredSegment($coveredUntil, $start);
            }
            $text = trim($item['text']);
            $warnings = $text === ''
                ? ['local_confidence_unavailable', 'no_recognized_speech']
                : ['local_confidence_unavailable'];
            if ($timingClipped) $warnings[] = 'local_timing_clipped_to_audio';
            $segments[] = [
                'start_ms' => $start,
                'end_ms' => $end,
                'speaker' => null,
                'text' => $text,
                'confidence' => null,
                'warnings' => $warnings,
            ];
            $coveredUntil = $end;
        }
        if ($segments === []) {
            $segments[] = [
                'start_ms' => 0,
                'end_ms' => $durationMs,
                'speaker' => null,
                'text' => '',
                'confidence' => null,
                'warnings' => ['local_confidence_unavailable', 'no_recognized_speech'],
            ];
        } elseif ($coveredUntil < $durationMs) {
            $segments[] = $this->uncoveredSegment($coveredUntil, $durationMs);
        }
        return ['segments' => $segments, 'audio_seconds' => $audioSeconds, 'warnings' => ['local_whisper_cpp']];
    }

    private function milliseconds(string $seconds): int
    {
        $parts = explode('.', $seconds);
        return (int) $parts[0] * 1000 + (int) $parts[1];
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
            'warnings' => ['local_confidence_unavailable', 'no_recognized_speech', 'uncovered_audio_range'],
        ];
    }
}
