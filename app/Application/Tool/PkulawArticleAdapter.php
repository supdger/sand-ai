<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** PKULAW's published article contract; transport, credentials and authorization stay managed. */
final class PkulawArticleAdapter
{
    public static function supports(mixed $kind): bool
    {
        return in_array($kind, ['pkulaw_article_search@1', 'pkulaw_article_lookup@1'], true);
    }

    /** Exact remote schemas must come from the subscribed service's actual tools/list. */
    public static function normalize(array $adapter, array $inputSchema): array
    {
        $keys = array_keys($adapter);
        sort($keys, SORT_STRING);
        if ($keys !== ['kind', 'remote_input_schema', 'remote_output_schema'] || !self::supports($adapter['kind'])) self::invalid();
        $remote = McpDocumentAdapter::remoteSchema($adapter['remote_input_schema']);
        $output = $adapter['remote_output_schema'] === null ? null : McpDocumentAdapter::remoteSchema($adapter['remote_output_schema']);
        $search = $adapter['kind'] === 'pkulaw_article_search@1';
        $map = $search ? ['text' => 'query', 'size' => 'limit'] : ['title' => 'title', 'number' => 'number'];
        $allowed = $search
            ? ['text', 'size', 'lib', 'timeliness', 'issue_department', 'implement_date_start', 'implement_date_end']
            : ['title', 'number'];
        if (!is_array($remote['properties'] ?? null) || array_is_list($remote['properties'])
            || !is_array($remote['required'] ?? null) || !array_is_list($remote['required'])
            || array_diff(array_keys($remote['properties']), $allowed) !== []
            || array_diff($remote['required'], array_keys($map)) !== []
            || !in_array($search ? 'text' : 'title', $remote['required'], true)
            || (!$search && !in_array('number', $remote['required'], true))) self::invalid();
        foreach ($map as $remoteKey => $localKey) {
            $property = $remote['properties'][$remoteKey] ?? null;
            $local = $inputSchema['properties'][$localKey] ?? null;
            $type = $remoteKey === 'size' ? 'integer' : 'string';
            if (!is_array($property) || ($property['type'] ?? null) !== $type
                || !is_array($local) || ($local['type'] ?? null) !== $type
                || !in_array($localKey, $inputSchema['required'] ?? [], true)) self::invalid();
            $keywords = $type === 'string'
                ? ['type', 'description', 'default', 'minLength', 'maxLength', 'enum']
                : ['type', 'description', 'default', 'minimum', 'maximum', 'enum'];
            if (array_diff(array_keys($property), $keywords) !== []) self::invalid();
            foreach ($type === 'string' ? ['minLength', 'maxLength'] : ['minimum', 'maximum'] as $bound) {
                if (isset($property[$bound]) && (!is_int($property[$bound]) || $property[$bound] < 0)) self::invalid();
            }
            if (isset($property['enum']) && (!is_array($property['enum']) || !array_is_list($property['enum'])
                || $property['enum'] === [])) self::invalid();
        }
        if (array_diff(array_keys($inputSchema['properties']), array_values($map)) !== []) self::invalid();
        return ['kind' => $adapter['kind'], 'remote_input_schema' => $remote, 'remote_output_schema' => $output];
    }

    /** @return array<string,string|int> */
    public static function arguments(array $adapter, array|\stdClass $input): array
    {
        $input = (array) $input;
        $map = $adapter['kind'] === 'pkulaw_article_search@1'
            ? ['text' => 'query', 'size' => 'limit'] : ['title' => 'title', 'number' => 'number'];
        $arguments = [];
        foreach ($map as $remoteKey => $localKey) {
            $value = $input[$localKey] ?? null;
            $schema = $adapter['remote_input_schema']['properties'][$remoteKey];
            if (($remoteKey === 'size' && (!is_int($value) || $value < 1 || $value > 20))
                || ($remoteKey !== 'size' && (!is_string($value) || trim($value) === '' || !self::text($value)))) self::invalidResult();
            if (is_string($value)) {
                $length = mb_strlen($value, 'UTF-8');
                if ((isset($schema['minLength']) && $length < $schema['minLength'])
                    || (isset($schema['maxLength']) && $length > $schema['maxLength'])) self::invalidResult();
            } elseif ((isset($schema['minimum']) && $value < $schema['minimum'])
                || (isset($schema['maximum']) && $value > $schema['maximum'])) self::invalidResult();
            if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) self::invalidResult();
            $arguments[$remoteKey] = $value;
        }
        return $arguments;
    }

    /** No article body or vendor payload is included in the persisted tool result. */
    public static function result(array $adapter, array $response, array $input): array
    {
        if (array_key_exists('isError', $response) && $response['isError'] !== false) self::invalidResult();
        $payload = self::payload($response);
        $search = $adapter['kind'] === 'pkulaw_article_search@1';
        if (!$search) {
            if (!is_array($payload) || array_is_list($payload)) self::invalidResult();
            $payload = [$payload];
        }
        if (!is_array($payload) || !array_is_list($payload) || count($payload) > 100) self::invalidResult();
        $limit = $search ? ($input['limit'] ?? null) : 1;
        if (!is_int($limit) || $limit < 1 || $limit > 20) self::invalidResult();
        $matches = [];
        $citations = [];
        $seen = [];
        foreach ($payload as $article) {
            if (!is_array($article) || array_is_list($article)) self::invalidResult();
            foreach (['gid', 'title', 'article', 'url'] as $key) {
                if (!is_string($article[$key] ?? null) || trim($article[$key]) === '' || !self::text($article[$key])) self::invalidResult();
            }
            if (strlen($article['gid']) > 128 || strlen($article['title']) > 4096 || strlen($article['article']) > 60000
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $article['gid']) !== 1) self::invalidResult();
            $url = self::url($article['url']);
            $hash = hash('sha256', $article['article']);
            $sourceRef = 'pkulaw_article:' . hash('sha256', $article['gid'] . "\0" . $url . "\0" . $hash);
            if (isset($seen[$sourceRef])) continue;
            $seen[$sourceRef] = true;
            $notes = ['来源条目：' . $article['gid']];
            foreach (['doc_no' => '文号', 'timeliness' => '来源时效标注', 'effectiveness' => '来源效力标注',
                'issue_department' => '制定机关', 'issue_date' => '公布日期', 'implementation_date' => '来源施行日期'] as $key => $label) {
                if (!array_key_exists($key, $article)) continue;
                if (!is_string($article[$key]) || strlen($article[$key]) > 4096 || !self::text($article[$key])) self::invalidResult();
                if ($article[$key] !== '') $notes[] = $label . '：' . $article[$key];
            }
            if (count($matches) >= $limit) continue;
            $matches[] = ['source_ref' => $sourceRef, 'title' => mb_substr($article['title'], 0, 300, 'UTF-8'),
                'excerpt' => mb_substr(implode('；', $notes), 0, 1000, 'UTF-8')];
            // This hash identifies the returned article bytes, not a licensed full-source snapshot.
            $citations[] = ['source_ref' => $sourceRef, 'locator' => ['type' => 'url', 'value' => $url],
                'content_hash' => $hash];
        }
        return ['result' => ['matches' => $matches], 'citations' => $citations];
    }

    /** Official CLI Application profile: structured object first, otherwise JSON text blocks. */
    public static function payload(array $response): mixed
    {
        $structured = $response['structuredContent'] ?? null;
        if (is_array($structured) && !array_is_list($structured) && $structured !== []) return $structured;
        $blocks = $response['content'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === [] || count($blocks) > 32) self::invalidResult();
        $parsed = [];
        $bytes = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)
                || !self::text($block['text'])) self::invalidResult();
            $text = trim($block['text']);
            $bytes += strlen($text);
            if ($bytes > 524288) self::invalidResult();
            if ($text === '') continue;
            try {
                $parsed[] = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::invalidResult();
            }
        }
        if ($parsed === []) self::invalidResult();
        return count($parsed) === 1 ? $parsed[0] : $parsed;
    }

    public static function url(string $url): string
    {
        if (strlen($url) > 512 || preg_match('/\A[\x21-\x7e]+\z/D', $url) !== 1 || str_contains($url, '\\')) self::invalidResult();
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !in_array($parts['host'] ?? null, ['pkulaw.com', 'www.pkulaw.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('#\A/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+\.html\z/D', $parts['path'] ?? '') !== 1) self::invalidResult();
        return $url;
    }

    public static function text(string $value): bool
    {
        return preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'PKULAW article registration needs the actual subscribed tool schemas');
    }

    private static function invalidResult(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'PKULAW article candidate result does not match its published contract');
    }
}
