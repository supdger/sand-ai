<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

final readonly class SpeechResolvedRuntime
{
    public function __construct(
        public SpeechExecutionTarget $target,
        public SpeechSynthesisRequest $request,
        public SpeechSynthesisDriver $driver,
    ) {
    }
}
