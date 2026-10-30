<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;

final readonly class SpeechSynthesisRequest
{
    public function __construct(
        public string $text,
        public string $model,
        public string $voice,
        public string $format = 'mp3',
    ) {
        SpeechAudioFormat::validate($format);
        if (!mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text, 'UTF-8') > 4096
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) === 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $model) !== 1
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $voice) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Speech synthesis request is invalid');
        }
    }
}
