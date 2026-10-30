<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Tool\BoundedToolResponseStream;
use app\Infrastructure\Provider\ResolvedProviderEndpoint;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** One bounded, DNS-pinned send. SDK request mapping cannot change the frozen speech input. */
final class NeuronSpeechHttpClient implements HttpClientInterface
{
    private bool $sent = false;
    private ?string $audio = null;

    /** @param array<string,string> $headers */
    public function __construct(
        private readonly ResolvedProviderEndpoint $endpoint,
        private readonly array $headers,
        private readonly SpeechSynthesisRequest $speech,
        private readonly ProviderSendBoundary $boundary,
        private readonly int $timeoutMs,
        private readonly int $maxBytes,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $expected = [
            'model' => $this->speech->model, 'input' => $this->speech->text,
            'voice' => $this->speech->voice, 'instructions' => '', 'response_format' => $this->speech->format,
        ];
        if ($this->sent || $request->method !== HttpMethod::POST || $request->uri !== $this->endpoint->url
            || $request->headers !== $this->headers || $request->timeout !== null || $request->body !== $expected) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Neuron speech request differs from the frozen synthesis input');
        }
        $this->sent = true;
        // The existing compatible wire contract has no instructions parameter.
        // An empty SDK default is omitted; nonempty instructions cannot pass the exact proof above.
        $payload = $request->body;
        unset($payload['instructions']);
        $sink = new BoundedToolResponseStream($this->maxBytes);
        $this->boundary->markStarted();
        try {
            $response = (new Client(['handler' => new CurlHandler()]))->request('POST', $this->endpoint->url, [
                'headers' => $this->headers,
                'json' => $payload,
                'http_errors' => false, 'allow_redirects' => false, 'proxy' => '',
                'connect_timeout' => min(30, $this->timeoutMs / 1000),
                'timeout' => $this->timeoutMs / 1000, 'sink' => $sink,
                'curl' => [
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                    CURLOPT_RESOLVE => $this->endpoint->curlResolveEntry === null ? [] : [$this->endpoint->curlResolveEntry],
                ],
            ]);
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= $this->maxBytes) {
                $chunk = $stream->read(min(65536, $this->maxBytes + 1 - strlen($body)));
                if ($chunk === '') throw new \RuntimeException('Speech response stream stalled');
                $body .= $chunk;
            }
        } catch (\Throwable) {
            if ($sink->exceeded()) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_INVALID', 'Speech provider returned invalid audio');
            }
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_UNAVAILABLE', 'Speech provider call failed', true);
        }
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($response->getStatusCode() !== 200 || !in_array($contentType, SpeechAudioFormat::responseMimes($this->speech->format), true)
            || strlen($body) < 32 || strlen($body) > $this->maxBytes) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_INVALID', 'Speech provider returned invalid audio');
        }
        $this->audio = $body;
        return new HttpResponse($response->getStatusCode(), $body, $response->getHeaders());
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'This synthesis intent does not permit streaming');
    }

    public function completion(AssistantMessage $message): string
    {
        $blocks = $message->getContentBlocks();
        if ($this->audio === null || count($blocks) !== 1 || !$blocks[0] instanceof AudioContent
            || $blocks[0]->sourceType !== SourceType::BASE64
            || base64_decode($blocks[0]->getContent(), true) !== $this->audio) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_INVALID', 'Neuron speech output differs from the received private audio');
        }
        return $this->audio;
    }
}
