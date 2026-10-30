<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use NeuronAI\Tools\ToolPropertyFactory;

/** Code-owned document projections. Neuron owns MCP and tool schema property support. */
final class McpDocumentAdapter
{
    /** @param array<string,mixed> $inputSchema @return array<string,mixed> */
    public static function normalize(mixed $adapter, array $inputSchema): array
    {
        if (!is_array($adapter) || array_is_list($adapter)) self::invalid();
        if (LegalFulltextAdapter::supports($adapter['kind'] ?? null)) return LegalFulltextAdapter::normalize($adapter, $inputSchema);
        if (PkulawCaseFilteredAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseFilteredAdapter::normalize($adapter, $inputSchema);
        if (PkulawCaseAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseAdapter::normalize($adapter, $inputSchema);
        if (PkulawArticleAdapter::supports($adapter['kind'] ?? null)) return PkulawArticleAdapter::normalize($adapter, $inputSchema);
        $search = ($adapter['kind'] ?? null) === 'document_search@1';
        if (!$search && ($adapter['kind'] ?? null) !== 'document_fetch@1') self::invalid();
        $keys = ['argument_map', 'kind', 'remote_input_schema', 'remote_output_schema', 'source_hosts'];
        if ($search) $keys[] = 'search_fields';
        sort($keys, SORT_STRING);
        $actualKeys = array_keys($adapter);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $keys) self::invalid();
        $remote = self::remoteSchema($adapter['remote_input_schema']);
        $output = $adapter['remote_output_schema'] === null ? null : self::remoteSchema($adapter['remote_output_schema']);
        $map = $adapter['argument_map'];
        if (!is_array($map) || array_is_list($map) || $map === [] || count($map) > 16) self::invalid();
        if (array_diff(array_keys($remote), ['type', 'properties', 'required', 'additionalProperties', 'description']) !== []
            || ($remote['type'] ?? null) !== 'object' || !is_array($remote['properties'] ?? null)
            || (isset($remote['required']) && (!is_array($remote['required']) || !array_is_list($remote['required'])))
            || (isset($remote['additionalProperties']) && !is_bool($remote['additionalProperties']))) self::invalid();
        $localKeys = [];
        foreach ($map as $remoteKey => $localKey) {
            if (!self::field($remoteKey) || !self::field($localKey) || isset($localKeys[$localKey])) self::invalid();
            $localKeys[$localKey] = true;
            $property = $remote['properties'][$remoteKey] ?? null;
            $local = $inputSchema['properties'][$localKey] ?? null;
            if (!is_array($property) || !is_array($local) || ($property['type'] ?? null) !== 'string' || ($local['type'] ?? null) !== 'string'
                || array_diff(array_keys($property), ['type', 'description', 'default', 'minLength', 'maxLength']) !== []
                || !in_array($localKey, $inputSchema['required'] ?? [], true)) self::invalid();
            foreach (['minLength', 'maxLength'] as $bound) {
                if (isset($property[$bound]) && (!is_int($property[$bound]) || $property[$bound] < 0
                    || !is_int($local[$bound] ?? null)
                    || ($bound === 'minLength' ? $local[$bound] < $property[$bound] : $local[$bound] > $property[$bound]))) self::invalid();
            }
        }
        foreach ($remote['required'] ?? [] as $required) {
            if (!is_string($required) || !array_key_exists($required, $map)) self::invalid();
        }
        $semanticInput = $search ? 'query' : 'url';
        if (count($map) !== 1 || array_values($map) !== [$semanticInput]
            || ($inputSchema['properties'][$semanticInput]['minLength'] ?? 0) < 1
            || !is_int($inputSchema['properties'][$semanticInput]['maxLength'] ?? null)
            || $inputSchema['properties'][$semanticInput]['maxLength'] > ($search ? 2000 : 512)
            || array_diff(array_keys($inputSchema['properties']), $search ? ['query', 'limit'] : ['url']) !== []) self::invalid();
        if ($search && (($inputSchema['properties']['limit']['type'] ?? null) !== 'integer'
            || ($inputSchema['properties']['limit']['minimum'] ?? 0) < 1
            || !is_int($inputSchema['properties']['limit']['maximum'] ?? null)
            || $inputSchema['properties']['limit']['maximum'] > 20
            || !in_array('limit', $inputSchema['required'] ?? [], true))) self::invalid();
        $hosts = $adapter['source_hosts'];
        if (!is_array($hosts) || !array_is_list($hosts) || $hosts === [] || count($hosts) > 16) self::invalid();
        foreach ($hosts as $host) {
            if (!is_string($host) || strtolower($host) !== $host || strlen($host) > 253
                || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host) !== 1
                || preg_match('/(?:\A|\.)(?:localhost|local|internal|test|invalid|example|onion)\z/D', $host) === 1) self::invalid();
        }
        if (count(array_unique($hosts, SORT_STRING)) !== count($hosts)) self::invalid();
        sort($hosts, SORT_STRING);
        $normalized = ['kind' => $adapter['kind'], 'remote_input_schema' => $remote, 'remote_output_schema' => $output,
            'argument_map' => $map, 'source_hosts' => $hosts];
        if ($search) {
            $fields = $adapter['search_fields'];
            if (!is_array($fields) || array_is_list($fields)) self::invalid();
            $fieldKeys = array_keys($fields);
            sort($fieldKeys, SORT_STRING);
            if ($fieldKeys !== ['content', 'items', 'title', 'url']) self::invalid();
            foreach ($fields as $field) if (!self::field($field)) self::invalid();
            if (count(array_unique(array_values($fields), SORT_STRING)) !== 4) self::invalid();
            $normalized['search_fields'] = $fields;
        }
        return $normalized;
    }

    /** The schema remains the actual catalog schema, including SDK-supported metadata and nullable types. */
    public static function remoteSchema(mixed $schema): array
    {
        if (!is_array($schema) || array_is_list($schema) || ($schema['type'] ?? null) !== 'object') self::invalid();
        self::bounded($schema, 0);
        if (strlen(ToolFingerprint::canonicalJson($schema)) > 32768) self::invalid();
        try {
            ToolPropertyFactory::fromSchema($schema);
        } catch (\Throwable) {
            self::invalid();
        }
        return $schema;
    }

    /** @param array<string,mixed> $adapter @param array<string,mixed>|\stdClass $input @return array<string,string|int> */
    public static function arguments(array $adapter, array|\stdClass $input): array
    {
        if (LegalFulltextAdapter::supports($adapter['kind'] ?? null)) return LegalFulltextAdapter::arguments($adapter, $input);
        if (PkulawCaseFilteredAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseFilteredAdapter::arguments($adapter, $input);
        if (PkulawCaseAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseAdapter::arguments($adapter, $input);
        if (PkulawArticleAdapter::supports($adapter['kind'] ?? null)) return PkulawArticleAdapter::arguments($adapter, $input);
        $input = (array) $input;
        $arguments = [];
        foreach ($adapter['argument_map'] as $remoteKey => $localKey) {
            if (!is_string($input[$localKey] ?? null)) self::invalidResult();
            $arguments[$remoteKey] = $input[$localKey];
        }
        if ($adapter['kind'] === 'document_fetch@1') self::sourceUrl($input['url'], $adapter['source_hosts']);
        return $arguments;
    }

    /** @return array{result:array<string,mixed>,citations:list<array<string,mixed>>} */
    public static function result(array $adapter, array $response, array $input): array
    {
        if (LegalFulltextAdapter::supports($adapter['kind'] ?? null)) return LegalFulltextAdapter::result($adapter, $response, $input);
        if (PkulawCaseFilteredAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseFilteredAdapter::result($adapter, $response, $input);
        if (PkulawCaseAdapter::supports($adapter['kind'] ?? null)) return PkulawCaseAdapter::result($adapter, $response, $input);
        if (PkulawArticleAdapter::supports($adapter['kind'] ?? null)) return PkulawArticleAdapter::result($adapter, $response, $input);
        if (array_key_exists('isError', $response) && $response['isError'] !== false) self::invalidResult();
        if ($adapter['kind'] === 'document_fetch@1') {
            $blocks = $response['content'] ?? null;
            if (!is_array($blocks) || !array_is_list($blocks) || $blocks === [] || count($blocks) > 32) self::invalidResult();
            $parts = [];
            $bytes = 0;
            foreach ($blocks as $block) {
                if (!is_array($block) || ($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)
                    || !self::text($block['text'])) self::invalidResult();
                $bytes += strlen($block['text']) + ($parts === [] ? 0 : 1);
                if ($bytes > 60000) self::invalidResult();
                $parts[] = $block['text'];
            }
            $content = implode("\n", $parts);
            if ($content === '') self::invalidResult();
            $url = self::sourceUrl($input['url'], $adapter['source_hosts']);
            return ['result' => ['content' => $content], 'citations' => [self::citation($url, $content)]];
        }
        $fields = $adapter['search_fields'];
        $structured = $response['structuredContent'] ?? null;
        if (!is_array($structured) || array_is_list($structured) || !is_array($structured[$fields['items']] ?? null)
            || !array_is_list($structured[$fields['items']]) || count($structured[$fields['items']]) > 100
            || !is_int($input['limit'] ?? null) || $input['limit'] < 1 || $input['limit'] > 20) self::invalidResult();
        $matches = [];
        $citations = [];
        $seen = [];
        foreach ($structured[$fields['items']] as $document) {
            if (!is_array($document) || array_is_list($document) || !is_string($document[$fields['title']] ?? null)
                || !is_string($document[$fields['content']] ?? null) || !is_string($document[$fields['url']] ?? null)
                || !self::text($document[$fields['title']]) || !self::text($document[$fields['content']])
                || strlen($document[$fields['title']]) > 4096 || strlen($document[$fields['content']]) > 60000) self::invalidResult();
            $url = self::sourceUrl($document[$fields['url']], $adapter['source_hosts']);
            $title = mb_substr(trim($document[$fields['title']]), 0, 300, 'UTF-8');
            if ($title === '') self::invalidResult();
            $citation = self::citation($url, $document[$fields['content']]);
            if (isset($seen[$citation['source_ref']])) continue;
            $seen[$citation['source_ref']] = true;
            if (count($matches) >= $input['limit']) continue;
            $matches[] = ['source_ref' => $citation['source_ref'], 'title' => $title,
                'excerpt' => mb_substr($document[$fields['content']], 0, 1000, 'UTF-8')];
            $citations[] = $citation;
        }
        return ['result' => ['matches' => $matches], 'citations' => $citations];
    }

    /** @param list<string> $hosts */
    private static function sourceUrl(mixed $url, array $hosts): string
    {
        if (!is_string($url) || strlen($url) > 512 || preg_match('/\A[\x21-\x7e]+\z/D', $url) !== 1
            || str_contains($url, '\\')) self::invalidResult();
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
            || !in_array($parts['host'], $hosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || (isset($parts['port']) && $parts['port'] !== 443)) self::invalidResult();
        return $url;
    }

    /** @return array<string,mixed> */
    private static function citation(string $url, string $content): array
    {
        return ['source_ref' => 'public_document:' . hash('sha256', $url . "\0" . $content),
            'locator' => ['type' => 'url', 'value' => $url], 'content_hash' => hash('sha256', $content)];
    }

    private static function field(mixed $field): bool
    {
        return is_string($field) && preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $field) === 1;
    }

    private static function text(string $value): bool
    {
        return preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1;
    }

    private static function bounded(mixed $value, int $depth): void
    {
        if ($depth > 12) self::invalid();
        if (is_array($value)) {
            if (count($value) > 128) self::invalid();
            foreach ($value as $key => $child) {
                if (is_string($key) && (strlen($key) > 128 || in_array($key, ['$ref', 'oneOf', 'anyOf', 'allOf', 'prefixItems'], true))) self::invalid();
                self::bounded($child, $depth + 1);
            }
        } elseif (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value)
            && (!is_string($value) || strlen($value) > 16384 || !self::text($value))) self::invalid();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed document adapter is invalid or unsupported by Neuron');
    }

    private static function invalidResult(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'MCP document result or controlled source is invalid');
    }
}
