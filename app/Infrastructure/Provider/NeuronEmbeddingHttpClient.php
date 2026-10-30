<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\RAG\Document;

/** The official batch mapper consumes one bounded, DNS-pinned response. */
final class NeuronEmbeddingHttpClient implements HttpClientInterface
{
    private bool $requested = false;
    /** @var array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool}|null */
    private ?array $result = null;

    /**
     * @param array<string,string> $headers
     * @param \Closure():array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} $send
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $headers,
        private readonly string $bodyDigest,
        private readonly \Closure $send,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        if ($this->requested || $request->method !== HttpMethod::POST
            || $request->uri !== $this->uri || $request->headers !== $this->headers
            || $request->timeout !== null || !is_array($request->body)
            || !hash_equals($this->bodyDigest, ProviderRequestBodyBuilder::digest($request->body))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Neuron embedding request differs from the frozen request');
        }
        $this->requested = true;
        $this->result = ($this->send)();
        $data = [];
        foreach ($this->result['vectors'] as $index => $vector) {
            $data[] = ['index' => $index, 'embedding' => $vector];
        }
        return new HttpResponse(200, json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Embedding requests do not support streaming');
    }

    /** @param list<Document> $documents @return array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} */
    public function completion(array $documents): array
    {
        if ($this->result === null || count($documents) !== count($this->result['vectors'])) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Neuron embedding batch is incomplete', true);
        }
        foreach ($documents as $index => $document) {
            if (!$document instanceof Document || $document->getEmbedding() !== $this->result['vectors'][$index]) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Neuron embedding differs from the bounded response', true);
            }
        }
        return $this->result;
    }
}
