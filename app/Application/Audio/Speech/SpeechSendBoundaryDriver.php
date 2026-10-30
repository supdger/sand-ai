<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Application\Gateway\ProviderSendBoundary;

/** Optional transport contract; older drivers remain conservatively ambiguous. */
interface SpeechSendBoundaryDriver extends SpeechSynthesisDriver
{
    /** Mark the boundary before any provider request may begin. */
    public function synthesizeWithBoundary(SpeechSynthesisRequest $request, ProviderSendBoundary $boundary): SpeechSynthesisResult;
}
