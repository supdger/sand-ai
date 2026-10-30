<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderIdempotencyCapability;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Knowledge\ProfessionalRerankOutcome;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\SystemHostResolver;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;

/** Runs official Neuron professional rerank providers behind the existing send boundary. */
final class NeuronProfessionalRerankProvider
{
    public const PROTOCOL = 'neuron_professional_rerank@1';

    public function __construct(
        private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]),
        private readonly HostResolver $resolver = new SystemHostResolver(),
        private readonly OpenAiCompatibleExecutionConfigPolicy $configPolicy = new OpenAiCompatibleExecutionConfigPolicy(),
    ) {
    }

    /**
     * Does not send a provider request. The caller must persist its dispatch before send.
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $candidates Already authorized, current source excerpts.
     * @return array{uri:string,body_digest:string,metering_upper_bound:?int,capacity_proof:?array,send:\Closure,usage_evidence:\Closure}
     */
    public function prepare(
        string $driver,
        array $config,
        string $remoteModel,
        string $query,
        array $candidates,
        int $timeoutMs,
        ProviderDispatchContext $dispatch,
    ): array {
        $payload = self::requestBody($driver, $remoteModel, $query, $candidates);
        if ($timeoutMs < 1000 || $timeoutMs > 120000) {
            throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Professional rerank timeout is invalid');
        }
        $normalized = $this->configPolicy->normalize($config);
        $endpoint = $this->endpoint($normalized);
        $bytes = ProviderRequestBodyBuilder::bytes($payload);
        $digest = hash('sha256', $bytes);
        $capacity = NeuronProfessionalRerankCapacity::forRequest($driver, $endpoint->url, $payload);
        if (!hash_equals($dispatch->bodyDigest, $digest)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank body differs from its dispatch ledger');
        }
        $capability = new ProviderIdempotencyCapability(
            $normalized['idempotency_mode'], $normalized['idempotency_header'],
        );
        if ($capability->mode === 'provider_key' && $dispatch->providerKey === null) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank provider idempotency key is missing');
        }
        // This exact SDK header set is frozen independently of server-only transport headers.
        $sdkHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $normalized['api_key'],
        ];
        $headers = $sdkHeaders;
        foreach ($normalized['extra_headers'] as $name => $value) {
            $headers[$name] = $value;
        }
        if ($capability->mode === 'provider_key') {
            $headers[(string) $capability->headerName] = (string) $dispatch->providerKey;
        }
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE') || $endpoint->curlResolveEntry === null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank endpoint pinning is unavailable');
        }
        $options = [
            'body' => $bytes, 'headers' => $headers,
            'timeout' => $timeoutMs / 1000,
            'connect_timeout' => min(10, $timeoutMs / 1000),
            'allow_redirects' => false, 'proxy' => '', 'http_errors' => false,
            'curl' => [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry]],
        ];
        $send = function (ProviderSendBoundary $boundary) use ($endpoint, $options, $normalized): array {
            $sink = new BoundedProviderResponseStream($normalized['response_max_bytes']);
            try {
                try {
                    $boundary->markStarted();
                    $response = $this->client->request('POST', $endpoint->url, [...$options, 'sink' => $sink]);
                } catch (GuzzleException) {
                    throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'Professional rerank transmission outcome is uncertain', true);
                }
                if ($sink->limitExceeded()) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank response exceeds its byte limit', true);
                }
                $sink->rewind();
                return ['status' => $response->getStatusCode(), 'body' => $sink->getContents()];
            } finally {
                $sink->close();
            }
        };
        $transport = new NeuronProfessionalRerankHttpClient(
            $driver, $endpoint->url, $sdkHeaders, $digest, count($candidates), $capacity, $send,
        );
        $baseUri = substr($endpoint->url, 0, -strlen('/rerank'));
        $sdk = $driver === 'cohere_rerank'
            ? new CohereRerankerPostProcessor($normalized['api_key'], $remoteModel, count($candidates), $baseUri, $transport)
            : new NeuronProfessionalRerankJinaPostProcessor($normalized['api_key'], $remoteModel, count($candidates), $baseUri, $transport);
        $documents = [];
        foreach ($candidates as $candidate) {
            $documents[] = (new Document($candidate['excerpt']))
                ->setId($candidate['source_block_id'])
                ->setSourceType('sand_ai_private')
                ->setSourceName('knowledge_professional_rerank')
                ->setMetadata(['citation' => $candidate]);
        }
        return [
            'uri' => $endpoint->url,
            'body_digest' => $digest,
            'metering_upper_bound' => $capacity['upper_bound'] ?? null,
            'capacity_proof' => $capacity,
            'send' => static function (ProviderSendBoundary $boundary) use ($transport, $sdk, $query, $documents): ProfessionalRerankOutcome {
                $transport->bindBoundary($boundary);
                try {
                    // The actual official provider performs request construction and source mapping.
                    $ranked = $sdk->process(new UserMessage($query), $documents);
                    return $transport->completion($ranked, $documents);
                } catch (ApiProblem $problem) {
                    throw $problem;
                } catch (\Throwable) {
                    // Provider payloads, secrets and vendor error messages stay in infrastructure.
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official professional rerank processing failed', true);
                }
            },
            'usage_evidence' => static fn (): ?ProfessionalRerankOutcome => $transport->usageEvidence(),
        ];
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public static function requestBody(string $driver, string $remoteModel, string $query, array $candidates): array
    {
        if (!in_array($driver, ['cohere_rerank', 'jina_rerank'], true)
            || trim($remoteModel) === '' || strlen($remoteModel) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $remoteModel) === 1
            || trim($query) === '' || strlen($query) > 2000 || preg_match('//u', $query) !== 1
            || !array_is_list($candidates) || count($candidates) < 2 || count($candidates) > 10) {
            throw new ApiProblem('SAND_AI_PROVIDER_REQUEST_INVALID', 'Professional rerank request is invalid');
        }
        $texts = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $id = is_array($candidate) ? ($candidate['source_block_id'] ?? null) : null;
            $excerpt = is_array($candidate) ? ($candidate['excerpt'] ?? null) : null;
            if (!is_int($id) || $id < 1 || isset($seen[$id])
                || !is_string($excerpt) || trim($excerpt) === '' || strlen($excerpt) > 500
                || preg_match('//u', $excerpt) !== 1) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Professional rerank source is invalid');
            }
            $seen[$id] = true;
            $texts[] = $driver === 'jina_rerank' ? ['text' => $excerpt] : $excerpt;
        }
        $body = ['model' => $remoteModel, 'query' => $query, 'top_n' => count($texts), 'documents' => $texts];
        if ($driver === 'jina_rerank') {
            $body['return_documents'] = false;
        }
        return $body;
    }

    /** @param array<string,mixed> $config */
    private function endpoint(array $config): ResolvedProviderEndpoint
    {
        $baseUrl = $config['base_url'];
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank endpoint base path is invalid');
        }
        foreach (explode('/', (string) ($parts['path'] ?? '')) as $rawSegment) {
            $segment = $rawSegment;
            $stable = false;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                if (preg_match('/%(?![0-9a-f]{2})/i', $segment) === 1) break;
                $decoded = rawurldecode($segment);
                if ($decoded === $segment) {
                    $stable = true;
                    break;
                }
                $segment = $decoded;
            }
            if (!$stable || $segment === '.' || $segment === '..' || strtolower($segment) === 'rerank'
                || str_contains($segment, '/') || str_contains($segment, '\\')
                || preg_match('/[\x00-\x20\x7f]/', $segment) === 1) {
                throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank endpoint base path is invalid');
            }
        }
        try {
            $verified = (new ProviderConnectionPolicy($this->resolver))->normalize([
                'base_url' => $baseUrl, 'api_key' => 'endpoint-policy-placeholder',
            ])['endpoint'];
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Professional rerank endpoint must resolve to public HTTPS');
        }
        return new ResolvedProviderEndpoint(rtrim($verified->baseUrl, '/') . '/rerank', $verified->curlResolveEntry());
    }
}
