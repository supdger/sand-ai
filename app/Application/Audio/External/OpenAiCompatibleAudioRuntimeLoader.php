<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

interface OpenAiCompatibleAudioRuntimeLoader
{
    public function load(ExternalAudioDispatchRequest $request): OpenAiCompatibleAudioRuntime;
}
