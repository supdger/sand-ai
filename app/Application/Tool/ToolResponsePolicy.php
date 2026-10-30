<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

final class ToolResponsePolicy
{
    /** @param array<string,mixed> $outputSchema */
    public static function normalize(ToolHttpResponse $response, array $outputSchema, string $toolCode = '', int $searchLimit = 20): ToolProviderOutcome
    {
        if ($response->statusCode === 429) return self::failure('SAND_AI_TOOL_RATE_LIMITED', $response);
        if ($response->statusCode >= 500) return self::failure('SAND_AI_TOOL_UPSTREAM_UNAVAILABLE', $response);
        if ($response->statusCode < 200 || $response->statusCode >= 300) return self::failure('SAND_AI_TOOL_UPSTREAM_REJECTED', $response);
        try {
            $payload = StrictJsonObjectDecoder::decodeObject($response->body);
        } catch (\Throwable) {
            return self::failure('SAND_AI_TOOL_RESPONSE_INVALID', $response);
        }
        if (!is_array($payload) || array_is_list($payload) || array_diff(array_keys($payload), ['result', 'citations', 'metering']) !== []
            || !array_key_exists('result', $payload) || !array_key_exists('citations', $payload)
            || (!is_array($payload['result']) && !$payload['result'] instanceof \stdClass)
            || (is_array($payload['result']) && array_is_list($payload['result'])) || !is_array($payload['citations']) || !array_is_list($payload['citations'])) {
            return self::failure('SAND_AI_TOOL_RESPONSE_INVALID', $response);
        }
        try {
            ToolSchemaPolicy::assertValue($outputSchema, $payload['result']);
            $citations = self::citations($payload['citations'], $toolCode === 'external_search');
            if ($toolCode === 'external_search') {
                if (!is_array($payload['result'])) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'External search result is invalid');
                ExternalSearchResultPolicy::assertSources($payload['result'], $citations, $searchLimit);
            }
        } catch (ApiProblem) {
            return self::failure('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', $response);
        }
        return new ToolProviderOutcome('succeeded', $payload['result'], $citations, null, $response->statusCode, $response->latencyMs);
    }

    public static function fromTransportProblem(ApiProblem $problem): ToolProviderOutcome
    {
        $allowed = ['SAND_AI_TOOL_TIMEOUT', 'SAND_AI_TOOL_RESPONSE_TOO_LARGE', 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS'];
        $code = in_array($problem->errorCode, $allowed, true) ? $problem->errorCode : 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS';
        return new ToolProviderOutcome($code === 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS' ? 'ambiguous' : 'failed', null, [], $code, null, 0);
    }

    /**
     * @param array<string,mixed>|\stdClass $result
     * @param list<mixed> $citations
     * @param array<string,mixed> $outputSchema
     */
    public static function normalizeStructuredContent(array|\stdClass $result, array $citations, array $outputSchema, int $httpStatus, int $latencyMs, string $toolCode = '', int $searchLimit = 20, bool $documentSearch = false): ToolProviderOutcome
    {
        try {
            ToolSchemaPolicy::assertValue($outputSchema, $result);
            $searchResult = $toolCode === 'external_search' || $documentSearch;
            $normalizedCitations = self::citations($citations, $searchResult);
            if ($searchResult) {
                if (!is_array($result)) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'External search result is invalid');
                ExternalSearchResultPolicy::assertSources($result, $normalizedCitations, $searchLimit);
            }
        } catch (ApiProblem) {
            return new ToolProviderOutcome('failed', null, [], 'SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', $httpStatus, $latencyMs);
        }
        return new ToolProviderOutcome('succeeded', $result, $normalizedCitations, null, $httpStatus, $latencyMs);
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private static function citations(array $items, bool $allowEmpty = false): array
    {
        if (($items === [] && !$allowEmpty) || count($items) > 100) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool citations are invalid');
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), ['source_ref', 'locator', 'source_version', 'content_hash']) !== []
                || !is_string($item['source_ref'] ?? null) || !is_array($item['locator'] ?? null) || array_is_list($item['locator'])
                || (!is_string($item['source_version'] ?? null) && !is_string($item['content_hash'] ?? null))) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool citation is invalid');
            }
            $sourceRef = trim($item['source_ref']);
            if ($sourceRef === '' || strlen($sourceRef) > 256 || self::unsafeReference($sourceRef)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool citation is invalid');
            $locator = self::locator($item['locator']);
            $sourceVersion = isset($item['source_version']) ? trim((string) $item['source_version']) : null;
            $contentHash = isset($item['content_hash']) ? strtolower(trim((string) $item['content_hash'])) : null;
            if (($sourceVersion === null || $sourceVersion === '' || strlen($sourceVersion) > 128)
                && ($contentHash === null || preg_match('/\A[0-9a-f]{64}\z/', $contentHash) !== 1)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool citation version is invalid');
            if ($contentHash !== null && preg_match('/\A[0-9a-f]{64}\z/', $contentHash) !== 1) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool citation hash is invalid');
            $normalized[] = ['source_ref' => $sourceRef, 'locator' => $locator, 'source_version' => $sourceVersion ?: null, 'content_hash' => $contentHash];
        }
        return $normalized;
    }

    /** @param array<string,mixed> $locator @return array{type:string,value:string|int} */
    private static function locator(array $locator): array
    {
        $keys = array_keys($locator);
        sort($keys, SORT_STRING);
        if ($keys !== ['type', 'value'] || !is_string($locator['type'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $locator['type']) !== 1
            || (!is_string($locator['value'] ?? null) && !is_int($locator['value'] ?? null))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool locator is invalid');
        $value = $locator['value'];
        if ((is_string($value) && ($value === '' || strlen($value) > 512 || self::unsafeReference($value))) || (is_int($value) && $value < 0)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External tool locator is invalid');
        return ['type' => $locator['type'], 'value' => $value];
    }

    private static function unsafeReference(string $value): bool
    {
        return preg_match('/(?:\A|\s)(?:file|resource|data|php|phar):/i', $value) === 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1;
    }

    private static function failure(string $code, ToolHttpResponse $response): ToolProviderOutcome
    {
        return new ToolProviderOutcome('failed', null, [], $code, $response->statusCode, $response->latencyMs);
    }
}
