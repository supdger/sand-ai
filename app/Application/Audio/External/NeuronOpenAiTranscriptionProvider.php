<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;

/** The SDK owns multipart assembly; endpoint and headers come from the verified runtime. */
final class NeuronOpenAiTranscriptionProvider extends OpenAISpeechToText
{
    /** @param array<string,mixed> $parameters @param array<string,string> $headers */
    public function __construct(string $key, string $model, string $language, array $parameters, string $baseUri, array $headers, HttpClientInterface $client)
    {
        parent::__construct($key, $model, $language, $parameters, $client);
        $this->baseUri = $baseUri;
        $this->httpHeaders = $headers;
    }
}
