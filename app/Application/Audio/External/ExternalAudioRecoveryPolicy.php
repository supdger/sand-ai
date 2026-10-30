<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;

/** Ensures recovery is receipt-only and rejects accidental raw Provider projections before terminalization. */
final class ExternalAudioRecoveryPolicy
{
    /** @param array<string,mixed> $result */
    public static function assertCanonicalResult(array $result, string $expectedDriverCode, string $expectedDriverVersion): void
    {
        $keys = ['schema','segments','field_sources','warnings','usage','review_required','technical'];
        if (array_keys($result) !== $keys || ($result['schema'] ?? null) !== ['code'=>'sand_ai.audio_transcription','version'=>'1.0.0'] || ($result['review_required'] ?? null) !== true || !is_array($result['segments'] ?? null) || !is_array($result['usage'] ?? null) || ($result['technical'] ?? null) !== ['capability_code'=>'audio_transcription','capability_version'=>'audio_transcription@1.0.0','driver_code'=>$expectedDriverCode,'driver_version'=>$expectedDriverVersion]) self::invalid();
        self::assertSafe($result);
    }
    private static function assertSafe(mixed $value, int $depth = 0): void
    {
        if ($depth > 10 || is_object($value) || is_resource($value)) self::invalid();
        if (is_array($value)) { foreach ($value as $key => $item) { if (is_string($key) && $key !== 'field_path' && preg_match('/(?:secret|token|password|api_key|credential|endpoint|url|raw|object_key|bucket|storage|location|path)/i',$key) === 1) self::invalid(); self::assertSafe($item,$depth+1); } return; }
        if (is_string($value) && ((preg_match('/\A\/segments\/\d+\/(?:start_ms|end_ms|speaker|text|confidence|warnings)\z/', $value) !== 1 && preg_match('/(?:[a-z][a-z0-9+.-]*:\/\/|\A(?:\/|\.\/|\.\.\/|~\/))/', $value) === 1) || strlen($value)>262144)) self::invalid();
        if (!is_null($value) && !is_string($value) && !is_int($value) && !is_bool($value) && !is_float($value)) self::invalid();
    }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'External audio recovery result is invalid'); }
}
