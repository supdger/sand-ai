<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Captures the real SDK request before I/O. It never fabricates a response.
 * SandAI's wire adaptations are limited to auto-language omission and the
 * controlled private upload filename. Existing transport owns the actual send.
 */
final class NeuronAudioTranscriptionRequestCapture implements HttpClientInterface
{
    private readonly \RuntimeException $capturedSignal;
    private ?OpenAiCompatibleAudioHttpRequest $captured = null;
    private bool $requested = false;

    /** @param array<string,string> $fields @param list<string> $fieldOrder */
    public function __construct(
        private readonly OpenAiCompatibleAudioRuntime $runtime,
        private readonly ControlledAudioInput $input,
        private readonly array $fields,
        private readonly array $fieldOrder,
        private readonly string $extension,
        private readonly bool $omitAutomaticLanguage,
        private readonly bool $verification = false,
    ) {
        $this->capturedSignal = new \RuntimeException('SDK audio request captured before transport');
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $body = is_array($request->body) ? $request->body : [];
        $file = $body['file'] ?? null;
        $stream = is_resource($file) ? $file : (is_array($file) ? ($file['contents'] ?? null) : null);
        try {
            if ($this->requested || $request->method !== HttpMethod::POST
                || $request->uri !== $this->runtime->endpoint()->url
                || $request->headers !== self::headers($this->runtime) || $request->timeout !== null
                || !is_resource($stream) || get_resource_type($stream) !== 'stream') {
                throw new \RuntimeException('SDK audio request does not match the frozen runtime');
            }
            $this->requested = true;
            $metadata = stream_get_meta_data($stream);
            if (($metadata['uri'] ?? null) !== $this->input->temporaryPath || ftell($stream) !== 0
                || (is_array($file) && (array_keys($file) !== ['contents', 'filename']
                    || $file['filename'] !== basename($this->input->temporaryPath)))) {
                throw new \RuntimeException('SDK audio upload is not the controlled private input');
            }
            $digest = hash_init('sha256');
            if (hash_update_stream($digest, $stream) === false
                || !hash_equals($this->input->reference->contentSha256, hash_final($digest))) {
                throw new \RuntimeException('SDK audio content differs from the frozen private input');
            }
            unset($body['file']);
            if ($this->omitAutomaticLanguage) {
                if (($body['language'] ?? null) !== 'auto') {
                    throw new \RuntimeException('SDK automatic language differs from the frozen request');
                }
                unset($body['language']);
            }
            $actual = $body;
            $expected = $this->fields;
            ksort($actual);
            ksort($expected);
            if ($actual !== $expected) {
                throw new \RuntimeException('SDK audio fields differ from the frozen request');
            }
            $multipart = [];
            foreach ($this->fieldOrder as $name) {
                $multipart[$name] = $name === 'file'
                    ? new \CURLFile($this->input->temporaryPath, $this->input->reference->mediaType, $this->verification ? 'verification.wav' : 'audio.' . $this->extension)
                    : $body[$name];
            }
            $this->captured = new OpenAiCompatibleAudioHttpRequest(
                $this->runtime->endpoint(), self::headers($this->runtime), $multipart,
                $this->runtime->timeoutMs(), $this->runtime->responseMaxBytes(),
            );
        } finally {
            // The SDK opens a local file solely for assembly; the real transport
            // opens its own bounded lifetime handle after the send boundary.
            if (is_resource($stream)) fclose($stream);
        }
        throw $this->capturedSignal;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new \RuntimeException('External ASR streaming is not part of the frozen contract');
    }

    public function capturedBy(\Throwable $exception): bool
    {
        return $exception === $this->capturedSignal && $this->captured !== null;
    }

    public function completion(): OpenAiCompatibleAudioHttpRequest
    {
        return $this->captured ?? throw new \RuntimeException('SDK audio request was not captured');
    }

    /** @return array<string,string> */
    public static function headers(OpenAiCompatibleAudioRuntime $runtime): array
    {
        return ['Authorization' => 'Bearer ' . $runtime->apiKey(), 'Accept' => 'application/json'] + $runtime->extraHeaders();
    }

    public function __serialize(): array { throw new \LogicException('Private SDK audio request cannot be serialized'); }
    public function __debugInfo(): array { return []; }
}
