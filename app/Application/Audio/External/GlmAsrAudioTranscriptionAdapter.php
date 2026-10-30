<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\AudioTranscriptionSourcedResultBuilder;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Gateway\ProviderSendBoundary;

/** One short clip, one dispatch. Unknown outcomes are never automatically reposted. */
final class GlmAsrAudioTranscriptionAdapter implements ExternalAudioAdapter
{
    public function __construct(
        private readonly OpenAiCompatibleAudioRuntimeLoader $runtimeLoader,
        private readonly OpenAiCompatibleAudioTransport $transport,
        private readonly ProviderSendBoundary $sendBoundary,
        private readonly ControlledAudioInput $input,
        private readonly GlmAsrAudioTranscriptionClient $client = new GlmAsrAudioTranscriptionClient(),
    ) {}

    public function preflight(ExternalAudioDispatchRequest $request): void
    {
        GlmAsrAudioInputPolicy::assertParameters($request->parameters);
        $runtime = $this->runtimeLoader->load($request);
        if ($request->driverCode !== 'glm_asr_audio' || $runtime->timeoutMs() < 1000 || $runtime->timeoutMs() > 120000) {
            throw new \RuntimeException('GLM ASR is not configured');
        }
        $this->client->request($runtime, $this->input, $request->parameters['glossary']);
    }

    public function dispatch(ExternalAudioDispatchRequest $request, string $providerIdempotencyKey): ExternalAudioDispatchOutcome
    {
        if ($providerIdempotencyKey === '' || $this->sendBoundary->started()) return ExternalAudioDispatchOutcome::notDispatched();
        try {
            $this->preflight($request);
            if ($this->input->reference->fileRef !== $request->fileRef
                || $this->input->reference->mediaType !== $request->mediaType
                || !hash_equals($this->input->reference->contentSha256, $request->fileSha256)) {
                return ExternalAudioDispatchOutcome::notDispatched();
            }
            $runtime = $this->runtimeLoader->load($request);
            $http = $this->client->request($runtime, $this->input, $request->parameters['glossary']);
        } catch (\Throwable) {
            return ExternalAudioDispatchOutcome::notDispatched();
        }
        try {
            $this->sendBoundary->markStarted();
            $response = $this->transport->send($http);
            if ($response->truncated || $response->status < 200 || $response->status >= 300
                || strlen($response->body) > $runtime->responseMaxBytes()) {
                return ExternalAudioDispatchOutcome::ambiguous();
            }
            $normalized = $this->client->normalize($response->body, (string) $this->input->verifiedDurationSeconds);
            return ExternalAudioDispatchOutcome::completed((new AudioTranscriptionSourcedResultBuilder())->build(
                $this->input->reference, $normalized, 'glm_asr_audio', '1.0.0', 'input_range_only',
            ));
        } catch (\Throwable) {
            return ExternalAudioDispatchOutcome::ambiguous();
        }
    }

    public function query(string $providerJobId): ?array { return null; }
}
