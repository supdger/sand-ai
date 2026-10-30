<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Provider\BoundedEmbeddingResponseStream;
use app\Infrastructure\Provider\HttpsProviderEndpointResolver;
use app\Infrastructure\Provider\OpenAiCompatibleEmbeddingResponseNormalizer;
use app\Infrastructure\Provider\OpenAiCompatibleEmbeddingProvider;
use app\Infrastructure\Provider\NeuronEmbeddingHttpClient;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;

/** Sends one fixed, non-sensitive sample to a saved embedding deployment. */
final class DeploymentEmbeddingVerifier
{
    private const SAMPLE = 'SandAI embedding deployment verification.';

    public function __construct(private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]))
    {
    }

    /**
     * @param array<string,mixed> $providerConfig
     * @return array{input_tokens:?int,output_tokens:?int,usage_measured:bool}
     */
    public function verify(array $providerConfig, string $remoteModel, int $timeoutMs): array
    {
        if (trim($remoteModel) === '' || strlen($remoteModel) > 160
            || $timeoutMs < 1000 || $timeoutMs > 120000) {
            throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Embedding verification request is invalid');
        }
        $config = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($providerConfig);
        $endpoint = (new HttpsProviderEndpointResolver())->embeddings($config);
        $headers = [
            'Authorization' => 'Bearer ' . $config['api_key'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        foreach ($config['extra_headers'] as $name => $value) {
            $headers[$name] = $value;
        }
        $options = [
            'body' => ProviderRequestBodyBuilder::bytes(OpenAiCompatibleEmbeddingProvider::requestBody($remoteModel, [self::SAMPLE])),
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
        $options['curl'] = [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry]];
        $body = new BoundedEmbeddingResponseStream($config['response_max_bytes']);
        $options['sink'] = $body;
        $send = function () use ($endpoint, $options, $body): array {
            $status = null;
            $shape = null;
            $stage = 'transport';
            try {
                $response = $this->client->request('POST', $endpoint->url, $options);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new EmbeddingVerificationFailure($status, 'http_status');
                }
                if ($body->exceeded()) {
                    throw new EmbeddingVerificationFailure($status, 'response_limit');
                }
                $body->rewind();
                $raw = $body->getContents();
                $stage = 'invalid_json';
                $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                $shape = $this->responseShape($payload, $raw);
                $stage = 'response_shape';
                $result = (new OpenAiCompatibleEmbeddingResponseNormalizer())->normalize($payload, 1);
                return $result;
            } catch (EmbeddingVerificationFailure $failure) {
                throw $failure;
            } catch (GuzzleException|\JsonException|ApiProblem) {
                throw new EmbeddingVerificationFailure($status, $body->exceeded() ? 'response_limit' : $stage, $shape);
            } finally {
                $body->close();
            }
        };
        $sdkHeaders = ['Accept' => 'application/json', 'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $config['api_key']];
        $transport = new NeuronEmbeddingHttpClient($endpoint->url, $sdkHeaders, hash('sha256', $options['body']), $send);
        $sdk = (new OpenAILikeEmbeddings(substr($endpoint->url, 0, -strlen('/embeddings')),
            $config['api_key'], $remoteModel, httpClient: $transport))->withoutDimensions();
        $result = $transport->completion($sdk->embedDocuments([new Document(self::SAMPLE)]));
        return [
            'input_tokens' => $result['usage_measured'] ? $result['input_tokens'] : null,
            'output_tokens' => $result['usage_measured'] ? 0 : null,
            'usage_measured' => $result['usage_measured'],
        ];
    }

    /** @return array<string,mixed> */
    private function responseShape(mixed $payload, string $raw): array
    {
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        $first = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : null;
        $vector = is_array($first) ? ($first['embedding'] ?? null) : null;
        $firstCharacter = substr(ltrim($raw), 0, 1);
        return [
            'root_type' => match ($firstCharacter) { '{' => 'object', '[' => 'array', default => 'scalar' },
            'data_is_list' => is_array($data) && array_is_list($data),
            'data_count' => is_array($data) ? count($data) : null,
            'first_vector_dimensions' => is_array($vector) ? count($vector) : null,
            'usage_present' => is_array($payload) && array_key_exists('usage', $payload),
            'error_present' => is_array($payload) && array_key_exists('error', $payload),
        ];
    }
}
