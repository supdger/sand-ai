<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Knowledge\ProfessionalRerankOutcome;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\RAG\Document;

/** Official rerank providers consume one frozen request through SandAI's transport. */
final class NeuronProfessionalRerankHttpClient implements HttpClientInterface
{
    private ?ProviderSendBoundary $boundary = null;
    private bool $requested = false;
    private ?ProfessionalRerankOutcome $evidence = null;
    /** @var list<array{index:int,relevance_score:float}>|null */
    private ?array $responseRankings = null;

    /**
     * @param array<string,string> $headers
     * @param \Closure(ProviderSendBoundary):array{status:int,body:string} $send
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $uri,
        private readonly array $headers,
        private readonly string $bodyDigest,
        private readonly int $expectedCount,
        private readonly ?array $capacityProof,
        private readonly \Closure $send,
    ) {
    }

    public function bindBoundary(ProviderSendBoundary $boundary): void
    {
        if ($this->boundary !== null || $boundary->started()) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank call cannot be reused');
        }
        $this->boundary = $boundary;
    }

    public function request(HttpRequest $request): HttpResponse
    {
        if ($this->boundary === null || $this->requested
            || $request->method !== HttpMethod::POST || $request->uri !== $this->uri
            || $request->headers !== $this->headers || $request->timeout !== null
            || !is_array($request->body)
            || !hash_equals($this->bodyDigest, ProviderRequestBodyBuilder::digest($request->body))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Official rerank request differs from its frozen dispatch');
        }
        $capacity = NeuronProfessionalRerankCapacity::forRequest($this->driver, $request->uri, $request->body);
        if (!RequestPayloadFingerprint::same(['capacity' => $this->capacityProof], ['capacity' => $capacity], [])) {
            throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Official rerank capacity changed before send');
        }
        $this->requested = true;
        $response = ($this->send)($this->boundary);
        $status = $response['status'];
        $this->evidence = $this->usage($status, null);
        try {
            $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $this->evidence = $this->usage($status, $payload);
        if ($status < 200 || $status >= 300) {
            if ($status === 429) {
                throw new ApiProblem('SAND_AI_PROVIDER_RATE_LIMITED', 'Professional rerank service rate limit reached', true);
            }
            if (in_array($status, [400, 401, 403, 404, 422], true)) {
                throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', 'Professional rerank service rejected the request');
            }
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'Professional rerank service outcome is uncertain', true);
        }
        if (!is_array($payload)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank response is invalid', true);
        }
        // Keep measured usage even if validation or the SDK mapper subsequently fails.
        $this->responseRankings = $this->validatedRankings($payload);
        return new HttpResponse($status, $response['body']);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank does not permit streaming');
    }

    public function usageEvidence(): ?ProfessionalRerankOutcome
    {
        return $this->evidence;
    }

    /** @param list<Document> $documents @param list<Document> $original */
    public function completion(array $documents, array $original): ProfessionalRerankOutcome
    {
        if ($this->evidence === null || $this->responseRankings === null
            || count($documents) !== $this->expectedCount || count($original) !== $this->expectedCount) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official rerank ordering is incomplete', true);
        }
        $rankings = [];
        foreach ($documents as $position => $document) {
            $result = $this->responseRankings[$position];
            $source = $original[$result['index']] ?? null;
            if (!$document instanceof Document || !$source instanceof Document || $document !== $source
                || !is_int($document->getId()) || $document->getScore() !== $result['relevance_score']) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official rerank ordering differs from its bounded response', true);
            }
            $rankings[] = ['source_block_id' => $document->getId(), 'relevance_score' => $result['relevance_score']];
        }
        return $this->evidence->withRankings($rankings);
    }

    private function usage(int $status, mixed $payload): ProfessionalRerankOutcome
    {
        $unit = $this->driver === 'cohere_rerank' ? 'rerank_search_units' : 'rerank_tokens';
        $value = null;
        if (is_array($payload)) {
            if ($this->driver === 'cohere_rerank' && is_array($payload['meta'] ?? null)
                && is_array($payload['meta']['billed_units'] ?? null)) {
                $value = $payload['meta']['billed_units']['search_units'] ?? null;
            } elseif ($this->driver === 'jina_rerank' && is_array($payload['usage'] ?? null)) {
                $value = $payload['usage']['total_tokens'] ?? null;
            }
        }
        $quantity = is_int($value) && $value >= 0 ? $value : null;
        return new ProfessionalRerankOutcome([], $unit, $quantity, $quantity !== null, true, $status);
    }

    /** @return list<array{index:int,relevance_score:float}> */
    private function validatedRankings(mixed $payload): array
    {
        $results = is_array($payload) ? ($payload['results'] ?? null) : null;
        if (!is_array($results) || !array_is_list($results) || count($results) !== $this->expectedCount) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank response does not cover all sources', true);
        }
        $seen = [];
        $rankings = [];
        foreach ($results as $item) {
            $index = is_array($item) ? ($item['index'] ?? null) : null;
            $score = is_array($item) ? ($item['relevance_score'] ?? null) : null;
            if (!is_int($index) || $index < 0 || $index >= $this->expectedCount || isset($seen[$index])
                || (!is_int($score) && !is_float($score)) || !is_finite((float) $score)) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank response contains invalid source scores', true);
            }
            $seen[$index] = true;
            $rankings[] = ['index' => $index, 'relevance_score' => (float) $score];
        }
        return $rankings;
    }
}
