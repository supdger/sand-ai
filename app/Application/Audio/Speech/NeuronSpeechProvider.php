<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech;

/** Select the configured endpoint; generation and AudioContent mapping are SDK-owned. */
final class NeuronSpeechProvider extends OpenAITextToSpeech
{
    /** @param array<string,string> $headers */
    public function __construct(string $key, string $model, string $voice, string $baseUrl, array $headers, HttpClientInterface $client, string $format = 'mp3')
    {
        parent::__construct($key, $model, $voice, ['response_format' => SpeechAudioFormat::validate($format)], $client);
        $this->baseUri = $baseUrl;
        $this->httpHeaders = $headers;
    }
}
