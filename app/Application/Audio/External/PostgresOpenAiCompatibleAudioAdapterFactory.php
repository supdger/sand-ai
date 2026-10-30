<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;
use app\Application\Gateway\ProviderSendBoundary;

/** Builds a one-input adapter; no input path is retained after loader callback returns. */
final class PostgresOpenAiCompatibleAudioAdapterFactory implements ExternalAudioAdapterFactory
{
    public function __construct(
        private readonly OpenAiCompatibleAudioRuntimeLoader $runtimeLoader = new PostgresOpenAiCompatibleAudioRuntimeLoader(),
        private readonly OpenAiCompatibleAudioTransport $transport = new GuzzleOpenAiCompatibleAudioTransport(),
    ) {}

    public function create(ControlledAudioInput $input): ExternalAudioAdapter
    {
        return new RegisteredExternalAudioAdapter($this->runtimeLoader, $this->transport, $input);
    }
}
