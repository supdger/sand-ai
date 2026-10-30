<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;

/** Code-owned private audio formats; raw PCM without framing is not accepted. */
final class SpeechAudioFormat
{
    public static function validate(string $format): string
    {
        if (!in_array($format, ['mp3', 'wav'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Speech audio format is unsupported');
        }
        return $format;
    }

    public static function mime(string $format): string
    {
        return self::validate($format) === 'wav' ? 'audio/wav' : 'audio/mpeg';
    }

    /** @return list<string> */
    public static function responseMimes(string $format): array
    {
        return self::validate($format) === 'wav'
            ? ['audio/wav', 'audio/x-wav', 'audio/wave', 'application/octet-stream']
            : ['audio/mpeg', 'application/octet-stream'];
    }

    /** Require a complete bounded RIFF/WAVE PCM container with consistent sample metadata. */
    public static function validWav(string $body): bool
    {
        $length = strlen($body);
        if ($length < 44 || $length > 8388608 || substr($body, 0, 4) !== 'RIFF'
            || substr($body, 8, 4) !== 'WAVE' || self::uint32($body, 4) + 8 !== $length) {
            return false;
        }
        $offset = 12;
        $align = null;
        $dataSize = null;
        $chunks = 0;
        while ($offset < $length) {
            if (++$chunks > 1024 || $offset + 8 > $length) return false;
            $name = substr($body, $offset, 4);
            $size = self::uint32($body, $offset + 4);
            $start = $offset + 8;
            $end = $start + $size;
            if ($end > $length || $end + ($size % 2) > $length) return false;
            if ($name === 'fmt ') {
                if ($align !== null || $size < 16 || $size > 64) return false;
                $kind = self::uint16($body, $start);
                $channels = self::uint16($body, $start + 2);
                $rate = self::uint32($body, $start + 4);
                $bytesPerSecond = self::uint32($body, $start + 8);
                $align = self::uint16($body, $start + 12);
                $bits = self::uint16($body, $start + 14);
                if ($kind !== 1 || $channels < 1 || $channels > 8 || $rate < 8000 || $rate > 192000
                    || !in_array($bits, [8, 16, 24, 32], true)
                    || $align !== $channels * intdiv($bits, 8)
                    || $bytesPerSecond !== $rate * $align) return false;
            } elseif ($name === 'data') {
                if ($align === null || $dataSize !== null || $size < 1) return false;
                $dataSize = $size;
            }
            $offset = $end + ($size % 2);
        }
        return $offset === $length && $align !== null && $dataSize !== null && !($dataSize % $align);
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }

    private static function uint32(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16) | (ord($bytes[$offset + 3]) << 24);
    }
}
