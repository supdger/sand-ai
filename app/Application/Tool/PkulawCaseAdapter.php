<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** The published semantic case-search contract; a result is a candidate, never confirmed authority. */
final class PkulawCaseAdapter
{
    public static function supports(mixed $kind): bool
    {
        return $kind === 'pkulaw_case_search@1';
    }

    public static function normalize(array $adapter, array $inputSchema): array
    {
        $keys = array_keys($adapter);
        sort($keys, SORT_STRING);
        if ($keys !== ['kind', 'remote_input_schema', 'remote_output_schema'] || !self::supports($adapter['kind'])) self::invalid();
        $remote = McpDocumentAdapter::remoteSchema($adapter['remote_input_schema']);
        $output = $adapter['remote_output_schema'] === null ? null : McpDocumentAdapter::remoteSchema($adapter['remote_output_schema']);
        $allowed = ['text', 'size', 'case_type', 'doc_type', 'courthouse_name', 'courthouse_province',
            'decision_date_start', 'decision_date_end'];
        if (!is_array($remote['properties'] ?? null) || array_is_list($remote['properties'])
            || !is_array($remote['required'] ?? null) || !array_is_list($remote['required'])
            || array_diff(array_keys($remote['properties']), $allowed) !== []
            || array_diff($remote['required'], ['text', 'size']) !== []
            || !in_array('text', $remote['required'], true)) self::invalid();
        // Reuse the same strict mapped-property validation without changing the actual frozen schemas.
        $mappedRemote = $remote;
        $mappedRemote['properties'] = array_intersect_key($remote['properties'], array_flip(['text', 'size']));
        PkulawArticleAdapter::normalize([
            'kind' => 'pkulaw_article_search@1',
            'remote_input_schema' => $mappedRemote,
            'remote_output_schema' => $output,
        ], $inputSchema);
        return ['kind' => $adapter['kind'], 'remote_input_schema' => $remote, 'remote_output_schema' => $output];
    }

    /** @return array<string,string|int> */
    public static function arguments(array $adapter, array|\stdClass $input): array
    {
        return PkulawArticleAdapter::arguments([
            'kind' => 'pkulaw_article_search@1', 'remote_input_schema' => $adapter['remote_input_schema'],
        ], $input);
    }

    public static function result(array $adapter, array $response, array $input): array
    {
        if (array_key_exists('isError', $response) && $response['isError'] !== false) self::invalidResult();
        $payload = PkulawArticleAdapter::payload($response);
        if (!is_array($payload) || !array_is_list($payload) || count($payload) > 100
            || !is_int($input['limit'] ?? null) || $input['limit'] < 1 || $input['limit'] > 20) self::invalidResult();
        $matches = [];
        $citations = [];
        $seen = [];
        foreach ($payload as $case) {
            if (!is_array($case) || array_is_list($case)) self::invalidResult();
            foreach (['gid', 'title', 'url'] as $key) {
                if (!is_string($case[$key] ?? null) || trim($case[$key]) === '' || !PkulawArticleAdapter::text($case[$key])) self::invalidResult();
            }
            if (strlen($case['gid']) > 128 || strlen($case['title']) > 4096
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $case['gid']) !== 1) self::invalidResult();
            $url = PkulawArticleAdapter::url($case['url']);
            $body = [];
            $bytes = 0;
            foreach (['ascertain', 'identified', 'referee_result'] as $key) {
                if (!is_string($case[$key] ?? null) || !PkulawArticleAdapter::text($case[$key])) self::invalidResult();
                $bytes += strlen($case[$key]);
                if ($bytes > 524288) self::invalidResult();
                $body[$key] = $case[$key];
            }
            // Defined hash of the actual returned sections, not a full judgment or citation version.
            $hash = hash('sha256', ToolFingerprint::canonicalJson($body));
            $sourceRef = 'pkulaw_case:' . hash('sha256', $case['gid'] . "\0" . $url . "\0" . $hash);
            if (isset($seen[$sourceRef])) continue;
            $seen[$sourceRef] = true;
            $notes = ['来源条目：' . $case['gid']];
            foreach (['case_number' => '案号', 'courthouse_name' => '审理法院', 'decision_date' => '来源审结日期',
                'case_type' => '案件类型', 'doc_type' => '文书类型', 'cause_of_action' => '案由'] as $key => $label) {
                if (!is_string($case[$key] ?? null) || strlen($case[$key]) > 4096 || !PkulawArticleAdapter::text($case[$key])) self::invalidResult();
                if ($case[$key] !== '') $notes[] = $label . '：' . $case[$key];
            }
            if (count($matches) >= $input['limit']) continue;
            $matches[] = ['source_ref' => $sourceRef, 'title' => mb_substr($case['title'], 0, 300, 'UTF-8'),
                'excerpt' => mb_substr(implode('；', $notes), 0, 1000, 'UTF-8')];
            $citations[] = ['source_ref' => $sourceRef, 'locator' => ['type' => 'url', 'value' => $url], 'content_hash' => $hash];
        }
        return ['result' => ['matches' => $matches], 'citations' => $citations];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'PKULAW case registration needs the actual subscribed tool schemas');
    }

    private static function invalidResult(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'PKULAW case candidate result does not match its published contract');
    }
}
