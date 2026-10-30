<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\ZAI\Audio\ZAITranscription;

/** Selects the configured endpoint while retaining the official SDK request assembler. */
final class NeuronZaiTranscriptionProvider extends ZAITranscription
{
    /** @param array<string,mixed> $parameters @param array<string,string> $headers */
    public function __construct(string $key, string $model, array $parameters, string $baseUri, array $headers, HttpClientInterface $client)
    {
        parent::__construct($key, $model, $parameters, $client);
        $this->baseUri = $baseUri;
        $this->httpHeaders = $headers;
    }
}
