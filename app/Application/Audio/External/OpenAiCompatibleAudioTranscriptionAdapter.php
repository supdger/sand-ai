<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\AudioTranscriptionSourcedResultBuilder;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Gateway\ProviderSendBoundary;

/**
 * One synchronous OpenAI-compatible transcription call. No polling, redirect,
 * proxy, query override, provider idempotency header, or automatic repost exists.
 */
final class OpenAiCompatibleAudioTranscriptionAdapter implements ExternalAudioAdapter
{
    /** @var \Closure(ExternalAudioDispatchRequest):ControlledAudioInput */
    private readonly \Closure $inputLoader;

    /** @param callable(ExternalAudioDispatchRequest):ControlledAudioInput $inputLoader */
    public function __construct(
        private readonly OpenAiCompatibleAudioRuntimeLoader $runtimeLoader,
        private readonly OpenAiCompatibleAudioTransport $transport,
        private readonly ProviderSendBoundary $sendBoundary,
        callable $inputLoader,
        private readonly OpenAiCompatibleAudioResponseNormalizer $normalizer = new OpenAiCompatibleAudioResponseNormalizer(),
    ) { $this->inputLoader = \Closure::fromCallable($inputLoader); }

    public function preflight(ExternalAudioDispatchRequest $request): void
    {
        $runtime = $this->runtimeLoader->load($request);
        if ($runtime->providerIdempotencyMode() !== 'none' || $runtime->timeoutMs() < 1000 || $runtime->timeoutMs() > 120000) {
            throw new \RuntimeException('Audio runtime is not configured');
        }
    }

    public function dispatch(ExternalAudioDispatchRequest $request, string $providerIdempotencyKey): ExternalAudioDispatchOutcome
    {
        // The key is deliberately not sent: official audio idempotency/query semantics are not frozen.
        if ($providerIdempotencyKey === '' || $this->sendBoundary->started()) return ExternalAudioDispatchOutcome::notDispatched();
        try {
            $runtime = $this->runtimeLoader->load($request);
            $input = ($this->inputLoader)($request);
            if (!$input instanceof ControlledAudioInput || $input->verifiedDurationSeconds === null || $runtime->providerIdempotencyMode() !== 'none'
                || $input->reference->fileRef !== $request->fileRef
                || $input->reference->mediaType !== $request->mediaType
                || !hash_equals($input->reference->contentSha256, $request->fileSha256)) return ExternalAudioDispatchOutcome::notDispatched();
            $http = (new NeuronAudioTranscriptionRequestBuilder())->openAi($runtime, $input, $request->parameters);
        } catch (\Throwable) {
            return ExternalAudioDispatchOutcome::notDispatched();
        }
        try {
            // This is intentionally the instruction immediately before the transport call.
            $this->sendBoundary->markStarted();
            $response = $this->transport->send($http);
            if ($response->truncated || $response->status < 200 || $response->status >= 300 || strlen($response->body) > $runtime->responseMaxBytes()) {
                return ExternalAudioDispatchOutcome::ambiguous();
            }
            $reference = new VerifiedPrivateAudioReference($request->fileRef, $request->mediaType, $request->fileSha256);
            return ExternalAudioDispatchOutcome::completed((new AudioTranscriptionSourcedResultBuilder())->build($reference, $this->normalizer->normalize($response->body, $request->parameters['speaker_diarization'], $input->verifiedDurationSeconds), 'openai_compatible_audio', '1.0.0', 'engine_segment'));
        } catch (\Throwable) {
            // timeout, reset, 5xx, truncated/bad JSON and all post-send failures are ambiguous.
            return ExternalAudioDispatchOutcome::ambiguous();
        }
    }

    public function query(string $providerJobId): ?array { return null; }

}
