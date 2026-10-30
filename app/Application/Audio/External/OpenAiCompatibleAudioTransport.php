<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Injectable one-shot transport. Production wiring must disable redirects and proxies. */
interface OpenAiCompatibleAudioTransport
{
    public function send(OpenAiCompatibleAudioHttpRequest $request): OpenAiCompatibleAudioHttpResponse;
}
