<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Opt-in provider adapters; no default vendor, endpoint or credential is selected. */
final class PkulawReadonlyToolRegistration
{
    /** @return array<string,array<string,mixed>> */
    public static function definitions(array $settings): array
    {
        if (($settings['enabled'] ?? false) !== true) return [];
        $definitions = [];
        foreach (['search_article' => 'pkulaw_article_search', 'get_article' => 'pkulaw_article_lookup',
            'search_case' => 'pkulaw_case_search'] as $remote => $code) {
            $schemas = $settings[$remote] ?? null;
            if (!is_array($schemas) || ($schemas['input_schema'] ?? null) === null) continue;
            if (!array_key_exists('output_schema', $schemas)) {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Actual PKULAW output schema or explicit absence is required');
            }
            $search = $remote !== 'get_article';
            $properties = $search
                ? ['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]]
                : ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                    'number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]];
            $definitions[$code] = [
                'name' => $remote === 'search_case' ? '北大法宝案例语义候选检索' : ($search ? '北大法宝法规语义候选检索' : '北大法宝指定法条候选查询'),
                'description' => '仅返回实际来源元数据、定位及返回内容摘要哈希；不保存条文或案例正文，不确认效力或引用版本',
                'remote_name' => $remote,
                'citation_mode' => 'sand_ai_standard',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => array_keys($properties), 'properties' => $properties],
                'output_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['matches'],
                    'properties' => ['matches' => ['type' => 'array', 'maxItems' => $search ? 20 : 1,
                        'items' => ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['source_ref', 'title', 'excerpt'], 'properties' => [
                                'source_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                                'excerpt' => ['type' => 'string', 'maxLength' => 1000],
                            ]]]]],
                'adapter' => ['kind' => $code . '@1', 'remote_input_schema' => $schemas['input_schema'],
                    'remote_output_schema' => $schemas['output_schema']],
            ];
        }
        $filtered = $settings['search_case_filtered'] ?? null;
        if (is_array($filtered) && ($filtered['enabled'] ?? false) === true) {
            if (!is_string($filtered['remote_name'] ?? null) || trim($filtered['remote_name']) === ''
                || !is_array($filtered['input_schema'] ?? null) || !array_key_exists('output_schema', $filtered)) {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Filtered case registration requires the actual remote name and schemas');
            }
            // Projection is code-owned, independent of whether the legacy adapter is configured.
            $projection = ['type' => 'object', 'additionalProperties' => false, 'required' => ['matches'],
                'properties' => ['matches' => ['type' => 'array', 'maxItems' => 20,
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'required' => ['source_ref', 'title', 'excerpt'], 'properties' => [
                            'source_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'excerpt' => ['type' => 'string', 'maxLength' => 1000],
                        ]]]]];
            $definitions['pkulaw_case_search_filtered'] = [
                'name' => '北大法宝案例语义筛选候选检索',
                'description' => '按实际发布筛选字段检索，仅保存来源元数据，不保存判决正文或确认法源',
                'remote_name' => $filtered['remote_name'], 'citation_mode' => 'sand_ai_standard',
                'input_schema' => PkulawCaseFilteredAdapter::inputSchema($filtered['input_schema'], $filtered['exposed_filters'] ?? null),
                'output_schema' => $projection,
                'adapter' => ['kind' => 'pkulaw_case_search_filtered@1', 'remote_input_schema' => $filtered['input_schema'],
                    'remote_output_schema' => $filtered['output_schema'], 'exposed_filters' => $filtered['exposed_filters']],
            ];
        }
        return $definitions;
    }
}
