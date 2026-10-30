<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Code-owned read-only MCP allowlist. Remote annotations never grant read-only status. */
final class McpReadonlyToolRegistry
{
    /** @var array<string,array<string,mixed>> */
    private readonly array $definitions;

    /** @param array<string,array<string,mixed>>|null $definitions */
    public function __construct(?array $definitions = null)
    {
        $configured = $definitions ?? config('sand_ai_mcp.tools', []);
        if (!is_array($configured) || array_is_list($configured)) {
            throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed MCP tool registry is invalid');
        }
        if ($definitions === null) {
            $settings = config('sand_ai_pkulaw', []);
            if (!is_array($settings)) throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed PKULAW registration is invalid');
            $additional = PkulawReadonlyToolRegistration::definitions($settings);
            if (array_intersect_key($configured, $additional) !== []) {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed PKULAW registration conflicts with an existing tool');
            }
            $configured += $additional;
            $fulltextSettings = config('sand_ai_legal_fulltext', []);
            if (!is_array($fulltextSettings)) throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed legal fulltext registration is invalid');
            $fulltexts = LegalFulltextToolRegistration::definitions($fulltextSettings);
            if (array_intersect_key($configured, $fulltexts) !== []) {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed legal fulltext registration conflicts with an existing tool');
            }
            $configured += $fulltexts;
        }
        $normalized = [];
        foreach ($configured as $code => $definition) {
            if (!is_string($code) || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1
                || !is_array($definition) || array_is_list($definition)) {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed MCP tool registry is invalid');
            }
            $keys = array_keys($definition);
            sort($keys, SORT_STRING);
            $expectedKeys = ['citation_mode', 'description', 'input_schema', 'name', 'output_schema', 'remote_name'];
            if (array_key_exists('adapter', $definition)) $expectedKeys[] = 'adapter';
            sort($expectedKeys, SORT_STRING);
            if ($keys !== $expectedKeys
                || !is_string($definition['name']) || trim($definition['name']) === '' || mb_strlen($definition['name'], 'UTF-8') > 128
                || !is_string($definition['description']) || mb_strlen($definition['description'], 'UTF-8') > 1000
                || !is_string($definition['remote_name']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $definition['remote_name']) !== 1
                || $definition['citation_mode'] !== 'sand_ai_standard') {
                throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed MCP tool registry is invalid');
            }
            $normalized[$code] = [
                'code' => $code,
                'name' => trim($definition['name']),
                'description' => trim($definition['description']),
                'remote_name' => $definition['remote_name'],
                'input_schema' => ToolSchemaPolicy::normalize($definition['input_schema']),
                'output_schema' => ToolSchemaPolicy::normalize($definition['output_schema']),
                'citation_mode' => 'sand_ai_standard',
            ];
            if (array_key_exists('adapter', $definition)) {
                $normalized[$code]['adapter'] = McpDocumentAdapter::normalize($definition['adapter'], $normalized[$code]['input_schema']);
            }
        }
        ksort($normalized, SORT_STRING);
        $this->definitions = $normalized;
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string,mixed> */
    public function require(string $code): array
    {
        $definition = $this->definitions[$code] ?? null;
        if (!is_array($definition)) {
            throw new ApiProblem('SAND_AI_MCP_TOOL_NOT_ALLOWED', 'MCP tool is not registered as a managed read-only tool');
        }
        return $definition;
    }

    /** @param array<string,mixed> $definition */
    public static function definitionHash(array $definition): string
    {
        return hash('sha256', ToolFingerprint::canonicalJson($definition));
    }
}
