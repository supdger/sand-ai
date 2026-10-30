<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

/** Private binary result. Callers must persist it before returning any public response. */
final readonly class SpeechSynthesisResult
{
    public function __construct(
        public string $audio,
        public int $inputCharacters,
        public string $format = 'mp3',
    ) {
        SpeechAudioFormat::validate($format);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Speech audio cannot be serialized');
    }

    public function __debugInfo(): array
    {
        return ['input_characters' => $this->inputCharacters];
    }
}
