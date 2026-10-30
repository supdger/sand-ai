<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;
use app\Application\Gateway\ProviderSendBoundary;

/** Selects a code-owned ASR client from the frozen driver, independently of provider names. */
final class RegisteredExternalAudioAdapter implements ExternalAudioAdapter
{
    private readonly ProviderSendBoundary $sendBoundary;

    public function __construct(
        private readonly OpenAiCompatibleAudioRuntimeLoader $runtimeLoader,
        private readonly OpenAiCompatibleAudioTransport $transport,
        private readonly ControlledAudioInput $input,
    ) {
        $this->sendBoundary = new ProviderSendBoundary();
    }

    public function preflight(ExternalAudioDispatchRequest $request): void { $this->adapter($request)->preflight($request); }
    public function dispatch(ExternalAudioDispatchRequest $request, string $providerIdempotencyKey): ExternalAudioDispatchOutcome
    {
        return $this->adapter($request)->dispatch($request, $providerIdempotencyKey);
    }
    public function query(string $providerJobId): ?array { return null; }

    private function adapter(ExternalAudioDispatchRequest $request): ExternalAudioAdapter
    {
        return match ($request->driverCode) {
            'openai_compatible_audio' => new OpenAiCompatibleAudioTranscriptionAdapter(
                $this->runtimeLoader, $this->transport, $this->sendBoundary,
                fn (ExternalAudioDispatchRequest $_): ControlledAudioInput => $this->input,
            ),
            'glm_asr_audio' => new GlmAsrAudioTranscriptionAdapter(
                $this->runtimeLoader, $this->transport, $this->sendBoundary, $this->input,
            ),
            default => throw new \RuntimeException('External ASR driver is not registered'),
        };
    }
}
