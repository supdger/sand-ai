<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

interface SpeechSynthesisDriver
{
    public function code(): string;

    public function synthesize(SpeechSynthesisRequest $request): SpeechSynthesisResult;
}
