<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\RequestPayloadFingerprint;

/** Official processed-input limits bound the reservation, never measured usage. */
final class NeuronProfessionalRerankCapacity
{
    private const RULE = 'cohere_v2_processed_search_units@1';
    private const ENDPOINT = 'https://api.cohere.com/v2/rerank';

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    public static function forRequest(string $driver, string $uri, array $body): ?array
    {
        $keys = array_keys($body);
        sort($keys);
        if ($driver !== 'cohere_rerank' || $uri !== self::ENDPOINT
            || $keys !== ['documents', 'model', 'query', 'top_n']
            || !is_string($body['model']) || !is_string($body['query'])
            || trim($body['query']) === '' || strlen($body['query']) > 2000 || preg_match('//u', $body['query']) !== 1
            || !is_array($body['documents']) || !array_is_list($body['documents'])
            || count($body['documents']) < 2 || count($body['documents']) > 10
            || $body['top_n'] !== count($body['documents'])) {
            return null;
        }
        foreach ($body['documents'] as $document) {
            if (!is_string($document) || trim($document) === '' || strlen($document) > 500 || preg_match('//u', $document) !== 1) {
                return null;
            }
        }
        return self::proof($body['model'], count($body['documents']), ProviderRequestBodyBuilder::digest($body));
    }

    /** @param array<string,mixed>|null $proof */
    public static function assertBinding(?array $proof, string $driver, string $model, string $bodyDigest, string $unit): ?int
    {
        if ($proof === null) return null;
        $count = $proof['document_count'] ?? null;
        $expected = is_int($count) && $count >= 2 && $count <= 10
            && preg_match('/\A[a-f0-9]{64}\z/D', $bodyDigest) === 1
            ? self::proof($model, $count, $bodyDigest) : null;
        if ($driver !== 'cohere_rerank' || $unit !== 'rerank_search_units' || $expected === null
            || !RequestPayloadFingerprint::same($proof, $expected, [])) {
            throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Professional rerank capacity differs from its official request limits');
        }
        return $expected['upper_bound'];
    }

    /** @return array<string,mixed>|null */
    private static function proof(string $model, int $count, string $digest): ?array
    {
        // These are documented processing limits, not a tested-version allow list.
        $queryLimit = match ($model) {
            'rerank-v3.5', 'rerank-english-v3.0', 'rerank-multilingual-v3.0' => 2048,
            'rerank-v4.0-pro', 'rerank-v4.0-fast' => 16384,
            default => null,
        };
        if ($queryLimit === null) return null;
        $documentLimit = 4096; // v2 default; the official SDK sends no override.
        $chunks = intdiv($documentLimit + $queryLimit + 499, 500);
        // Cohere charges each 500-token query/document chunk as a document,
        // then one search unit per 100 documents. This is a conservative
        // derivation from provider truncation, not a local token estimate.
        $upperBound = intdiv($count * $chunks + 99, 100);
        return [
            'rule' => self::RULE, 'driver' => 'cohere_rerank', 'endpoint' => self::ENDPOINT,
            'remote_model' => $model, 'metering_unit' => 'rerank_search_units',
            'document_count' => $count, 'document_token_limit' => $documentLimit,
            'query_token_limit' => $queryLimit, 'billing_chunk_tokens' => 500,
            'documents_per_search_unit' => 100, 'upper_bound' => $upperBound,
            'body_sha256' => $digest,
            'references' => [
                'https://cohere.com/pricing',
                'https://docs.cohere.com/reference/rerank',
                'https://docs.cohere.com/docs/reranking-best-practices',
                'https://docs.cohere.com/docs/rerank-overview',
            ],
        ];
    }
}
