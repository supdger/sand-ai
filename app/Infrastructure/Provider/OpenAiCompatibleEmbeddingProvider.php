<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderIdempotencyCapability;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

/** Validates and sends one bounded OpenAI-compatible embedding batch. */
final class OpenAiCompatibleEmbeddingProvider
{
    public const PROTOCOL_NEURON = 'neuron_embedding_batch@1';
    public const PROTOCOL_LEGACY = 'legacy_embedding_batch@1';

    public function __construct(
        private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]),
        private readonly ProviderEndpointResolver $endpoints = new HttpsProviderEndpointResolver(),
        private readonly OpenAiCompatibleExecutionConfigPolicy $configPolicy = new OpenAiCompatibleExecutionConfigPolicy(),
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $texts
     */
    public function prepare(
        array $config,
        string $remoteModel,
        array $texts,
        int $timeoutMs,
        ProviderDispatchContext $dispatch,
        string $protocol = self::PROTOCOL_NEURON,
    ): PreparedEmbeddingProviderCall {
        if (trim($remoteModel) === '' || strlen($remoteModel) > 160 || $texts === []
            || count($texts) > 16 || !array_is_list($texts) || $timeoutMs < 1000 || $timeoutMs > 120000) {
            throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Embedding request is invalid');
        }
        $bytes = 0;
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '' || strlen($text) > 65536) {
                throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Embedding text is invalid');
            }
            $bytes += strlen($text);
        }
        if ($bytes > 65536) {
            throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Embedding batch is too large');
        }
        $normalized = $this->configPolicy->normalize($config);
        $endpoint = $this->endpoints->embeddings($normalized);
        $body = ProviderRequestBodyBuilder::bytes(self::requestBody($remoteModel, $texts, $protocol));
        if (!hash_equals($dispatch->bodyDigest, hash('sha256', $body))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding request differs from its dispatch ledger');
        }
        $capability = ProviderIdempotencyCapability::fromServerConfig('openai_compatible', $normalized);
        if ($capability->mode === 'provider_key' && $dispatch->providerKey === null) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Provider idempotency key is missing');
        }
        $headers = [
            'Authorization' => 'Bearer ' . $normalized['api_key'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        foreach ($normalized['extra_headers'] as $name => $value) $headers[$name] = $value;
        if ($capability->mode === 'provider_key') {
            $headers[(string) $capability->headerName] = (string) $dispatch->providerKey;
        }
        $requestOptions = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutMs / 1000,
            'connect_timeout' => min(10, $timeoutMs / 1000),
            'allow_redirects' => false,
            'proxy' => '',
            'http_errors' => false,
        ];
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE') || $endpoint->curlResolveEntry === null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint pinning is unavailable');
        }
        $requestOptions['curl'] = [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry]];
        $expected = count($texts);
        $responseLimit = $normalized['response_max_bytes'];
        $send = function (ProviderSendBoundary $boundary) use ($endpoint, $requestOptions, $expected, $responseLimit): array {
            $body = new BoundedEmbeddingResponseStream($responseLimit);
            try {
                try {
                    $boundary->markStarted();
                    $response = $this->client->request('POST', $endpoint->url, [...$requestOptions, 'sink' => $body]);
                } catch (GuzzleException) {
                    throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'Embedding provider request failed', true);
                }
                $status = $response->getStatusCode();
                if ($body->exceeded()) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding response is too large', true);
                }
                $body->rewind();
                $raw = $body->getContents();
                if ($status < 200 || $status >= 300) {
                    if ($status === 429) throw new ApiProblem('SAND_AI_PROVIDER_RATE_LIMITED', 'Embedding provider rate limit reached', true);
                    if (in_array($status, [400, 401, 403, 404, 422], true)) {
                        throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', 'Embedding provider rejected the request');
                    }
                    throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'Embedding provider outcome is uncertain', true);
                }
                try {
                    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider response is invalid', true);
                }
                return (new OpenAiCompatibleEmbeddingResponseNormalizer())->normalize($payload, $expected);
            } finally {
                $body->close();
            }
        };
        if ($protocol === self::PROTOCOL_LEGACY) {
            // Only a consumer matching the complete historical fingerprint
            // may select this path; the original body digest is checked above.
            return new PreparedEmbeddingProviderCall($send);
        }
        $sdkHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $normalized['api_key'],
        ];
        return new PreparedEmbeddingProviderCall(
            static function (ProviderSendBoundary $boundary) use ($endpoint, $normalized, $remoteModel, $texts, $body, $sdkHeaders, $send): array {
                $transport = new NeuronEmbeddingHttpClient(
                    $endpoint->url, $sdkHeaders, hash('sha256', $body),
                    static fn (): array => $send($boundary),
                );
                $sdk = (new OpenAILikeEmbeddings(
                    substr($endpoint->url, 0, -strlen('/embeddings')),
                    $normalized['api_key'], $remoteModel, httpClient: $transport,
                ))->withoutDimensions();
                $documents = array_map(static fn (string $text): Document => new Document($text), $texts);
                return $transport->completion($sdk->embedDocuments($documents));
            },
        );
    }

    /** @param list<string> $texts @return array<string,mixed> */
    public static function requestBody(string $remoteModel, array $texts, string $protocol = self::PROTOCOL_NEURON): array
    {
        return match ($protocol) {
            self::PROTOCOL_NEURON => ['model' => $remoteModel, 'input' => $texts, 'encoding_format' => 'float'],
            self::PROTOCOL_LEGACY => ['model' => $remoteModel, 'input' => $texts],
            default => throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Embedding request protocol is unknown'),
        };
    }
}
