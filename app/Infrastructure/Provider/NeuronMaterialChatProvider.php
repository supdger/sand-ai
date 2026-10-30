<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * Neuron's OpenAI-compatible protocol with the already frozen SandAI request.
 * No Agent loop, tool execution or framework retry is enabled by this adapter.
 */
final class NeuronMaterialChatProvider extends OpenAI
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function __construct(
        string $key,
        string $model,
        array $payload,
        private readonly string $endpoint,
        private readonly array $headers,
        HttpClientInterface $httpClient,
    ) {
        // Neuron spreads parameters after its message mapper. The frozen
        // messages therefore keep their original string content and digest.
        parent::__construct($key, $model, $payload, false, $httpClient);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post($this->endpoint, $payload, $this->headers);
    }
}
