<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\ZAI\ZAI;

/** Official ZAI schema injection/message mapping with SandAI's pinned transport. */
final class NeuronSkillZaiProvider extends ZAI
{
    /** @param array<string,mixed> $parameters @param array<string,string> $headers */
    public function __construct(string $key, string $model, array $parameters, private readonly string $endpoint, private readonly array $headers, HttpClientInterface $client)
    {
        parent::__construct($key, $model, $parameters, false, $client);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post($this->endpoint, $payload, $this->headers);
    }
}
