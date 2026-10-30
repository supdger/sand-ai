<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Administrator opt-in to actual subscribed read-only MCP tools. */
final class LegalFulltextToolRegistration
{
    /** @return array<string,array<string,mixed>> */
    public static function definitions(array $settings): array
    {
        if (($settings['enabled'] ?? false) !== true) return [];
        $configured = $settings['tools'] ?? null;
        if (!is_array($configured) || ($configured !== [] && array_is_list($configured))) self::invalid();
        $definitions = [];
        foreach ($configured as $code => $tool) {
            if (!is_array($tool)) self::invalid();
            if (($tool['enabled'] ?? false) !== true) continue;
            // Unknown name/schema means no registration, never a guessed remote tool.
            if (($tool['remote_name'] ?? null) === null || ($tool['input_schema'] ?? null) === null) continue;
            $keys = array_keys($tool);
            sort($keys, SORT_STRING);
            if ($keys !== ['description', 'enabled', 'input_schema', 'name', 'output_schema', 'remote_name', 'result_fields', 'result_mode']
                || !is_string($code) || !is_array($tool['input_schema']) || !is_array($tool['result_fields'])) self::invalid();
            $input = LegalFulltextAdapter::inputSchema($tool['input_schema']);
            $adapter = LegalFulltextAdapter::normalize([
                'kind' => 'legal_fulltext_fetch@1', 'remote_input_schema' => $tool['input_schema'],
                'remote_output_schema' => $tool['output_schema'], 'result_mode' => $tool['result_mode'],
                'result_fields' => $tool['result_fields'],
            ], $input);
            $definitions[$code] = ['name' => $tool['name'], 'description' => $tool['description'],
                'remote_name' => $tool['remote_name'], 'citation_mode' => 'sand_ai_standard',
                'input_schema' => $input, 'output_schema' => LegalFulltextAdapter::outputSchema($adapter['result_fields']),
                'adapter' => $adapter];
        }
        return $definitions;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Legal fulltext registration requires actual tool metadata and explicit output schema absence');
    }
}
