<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Separate immutable contract: only explicitly exposed actual remote fields are forwarded. */
final class PkulawCaseFilteredAdapter
{
    public static function supports(mixed $kind): bool { return $kind === 'pkulaw_case_search_filtered@1'; }

    public static function inputSchema(array $remote, mixed $exposed): array
    {
        $remote = McpDocumentAdapter::remoteSchema($remote);
        if (!is_array($remote['properties'] ?? null) || array_is_list($remote['properties'])
            || !is_array($remote['required'] ?? null) || !array_is_list($remote['required'])
            || !is_array($exposed) || !array_is_list($exposed) || count($exposed) > 32
            || count(array_unique($exposed, SORT_REGULAR)) !== count($exposed)) self::invalid();
        $properties = ['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]];
        $required = ['query', 'limit'];
        foreach ($exposed as $field) {
            if (!is_string($field) || preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $field) !== 1
                || in_array($field, ['query', 'limit', 'text', 'size'], true)
                || !is_array($remote['properties'][$field] ?? null)) self::invalid();
            $properties[$field] = self::property($remote['properties'][$field]);
            if (in_array($field, $remote['required'], true)) $required[] = $field;
        }
        if (array_diff($remote['required'], ['text', 'size', ...$exposed]) !== []) self::invalid();
        // Use the existing mapped query/limit contract; remote names and constraints are never guessed.
        $mapped = $remote;
        $mapped['properties'] = array_intersect_key($remote['properties'], array_flip(['text', 'size']));
        $mapped['required'] = array_values(array_intersect($remote['required'], ['text', 'size']));
        PkulawArticleAdapter::normalize(['kind' => 'pkulaw_article_search@1',
            'remote_input_schema' => $mapped, 'remote_output_schema' => null],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['query', 'limit'],
                'properties' => array_intersect_key($properties, array_flip(['query', 'limit']))]);
        return ToolSchemaPolicy::normalize(['type' => 'object', 'additionalProperties' => false,
            'required' => $required, 'properties' => $properties]);
    }

    public static function normalize(array $adapter, array $inputSchema): array
    {
        $keys = array_keys($adapter); sort($keys, SORT_STRING);
        if ($keys !== ['exposed_filters', 'kind', 'remote_input_schema', 'remote_output_schema']
            || !self::supports($adapter['kind'] ?? null)) self::invalid();
        $remote = McpDocumentAdapter::remoteSchema($adapter['remote_input_schema']);
        $output = $adapter['remote_output_schema'] === null ? null : McpDocumentAdapter::remoteSchema($adapter['remote_output_schema']);
        $expected = self::inputSchema($remote, $adapter['exposed_filters']);
        if (ToolFingerprint::canonicalJson($expected) !== ToolFingerprint::canonicalJson($inputSchema)) self::invalid();
        return ['kind' => $adapter['kind'], 'remote_input_schema' => $remote,
            'remote_output_schema' => $output, 'exposed_filters' => $adapter['exposed_filters']];
    }

    public static function arguments(array $adapter, array|\stdClass $input): array
    {
        $schema = self::inputSchema($adapter['remote_input_schema'], $adapter['exposed_filters']);
        ToolSchemaPolicy::assertValue($schema, $input);
        $input = (array) $input;
        $arguments = PkulawArticleAdapter::arguments(['kind' => 'pkulaw_article_search@1',
            'remote_input_schema' => $adapter['remote_input_schema']], $input);
        foreach ($adapter['exposed_filters'] as $field) {
            if (array_key_exists($field, $input)) $arguments[$field] = $input[$field];
        }
        return $arguments;
    }

    public static function result(array $adapter, array $response, array $input): array
    {
        return PkulawCaseAdapter::result($adapter, $response, $input);
    }

    private static function property(array $property): array
    {
        // Annotation-only metadata does not alter validation. Unsupported semantic keywords reject registration.
        unset($property['title'], $property['description'], $property['default']);
        if (!in_array($property['type'] ?? null, ['string', 'integer', 'number', 'boolean', 'array'], true)) self::invalid();
        if (($property['type'] ?? null) === 'array') {
            if (!is_array($property['items'] ?? null) || ($property['items']['type'] ?? null) === 'array') self::invalid();
            $property['items'] = self::property($property['items']);
        }
        if (isset($property['enum'])) {
            foreach ($property['enum'] as $option) {
                if (!is_string($option) && !is_int($option) && !is_float($option) && !is_bool($option)) self::invalid();
            }
        }
        $normalized = ToolSchemaPolicy::normalize(['type' => 'object', 'additionalProperties' => false,
            'properties' => ['value' => $property], 'required' => ['value']]);
        return $normalized['properties']['value'];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', '案例筛选配置错误：只可开放真实远端 Schema 的原字段名及字符串、整数、数值、布尔或一层基础类型数组；对象、嵌套数组、nullable、除 date/date-time 外的 format 和其他未支持约束不能发布');
    }
}
