<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\OpenAI;

/** Official SDK OpenAI streaming parser and tool assembly with the exact SandAI frozen HTTP body. */
final class NeuronAgentStreamProvider extends OpenAI
{
    public function __construct(string $key, string $model, private readonly array $payload, private readonly string $endpoint, private readonly array $headers, HttpClientInterface $client)
    {
        parent::__construct($key, $model, $payload, false, $client);
    }
    // SDK tools remain registered for native tool-message assembly; the already frozen SandAI schema includes its strict domain bounds.
    protected function createChatHttpRequest(array $payload): HttpRequest { return HttpRequest::post($this->endpoint, $this->payload, $this->headers); }
}
