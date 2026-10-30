<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

final class McpSnapshotPolicy
{
    /**
     * @param list<array<string,mixed>> $remoteTools
     * @return array{snapshot_hash:string,items:list<array<string,mixed>>}
     */
    public static function build(array $remoteTools, McpReadonlyToolRegistry $registry, string $protocolVersion = McpStreamableHttpClient::PROTOCOL_VERSION): array
    {
        $definitionsByRemoteName = [];
        foreach ($registry->all() as $definition) {
            $remoteName = (string) $definition['remote_name'];
            if (isset($definitionsByRemoteName[$remoteName])) self::invalidRegistry();
            $definitionsByRemoteName[$remoteName] = $definition;
        }

        $seenRemoteNames = [];
        $items = [];
        foreach ($remoteTools as $remoteTool) {
            if (!is_array($remoteTool) || array_is_list($remoteTool) || !is_string($remoteTool['name'] ?? null)) self::invalidResponse();
            $remoteName = $remoteTool['name'];
            if (isset($seenRemoteNames[$remoteName])) self::invalidResponse();
            $seenRemoteNames[$remoteName] = true;
            $definition = $definitionsByRemoteName[$remoteName] ?? null;
            if (!is_array($definition)) continue;
            if (!array_key_exists('inputSchema', $remoteTool)) self::invalidResponse();
            $adapter = $definition['adapter'] ?? null;
            $remoteInput = is_array($adapter) ? McpDocumentAdapter::remoteSchema($remoteTool['inputSchema']) : ToolSchemaPolicy::normalize($remoteTool['inputSchema']);
            $expectedInput = is_array($adapter) ? $adapter['remote_input_schema'] : $definition['input_schema'];
            if (!hash_equals(self::hash($expectedInput), self::hash($remoteInput))) {
                throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'MCP tool input schema does not match the local read-only registration');
            }
            $remoteOutput = null;
            if (array_key_exists('outputSchema', $remoteTool)) {
                $remoteOutput = is_array($adapter) ? McpDocumentAdapter::remoteSchema($remoteTool['outputSchema']) : ToolSchemaPolicy::normalize($remoteTool['outputSchema']);
            }
            if (is_array($adapter) && $adapter['remote_output_schema'] !== $remoteOutput
                && self::hash(['schema' => $adapter['remote_output_schema']]) !== self::hash(['schema' => $remoteOutput])) {
                throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'MCP document output schema changed after registration');
            }
            $description = $remoteTool['description'] ?? '';
            if (!is_string($description) || strlen($description) > 32768 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $description) === 1) self::invalidResponse();
            $item = [
                'local_tool_code' => (string) $definition['code'],
                'remote_name' => $remoteName,
                'remote_description' => $description,
                'remote_input_schema' => $remoteInput,
                'remote_output_schema' => $remoteOutput,
                'input_schema' => $definition['input_schema'],
                'output_schema' => $definition['output_schema'],
                'citation_mode' => $definition['citation_mode'],
                'registry_definition_hash' => McpReadonlyToolRegistry::definitionHash($definition),
            ];
            $item['item_hash'] = self::hash($item);
            $items[] = $item;
        }
        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['local_tool_code'], (string) $right['local_tool_code']));
        if ($items === []) throw new ApiProblem('SAND_AI_MCP_NO_ALLOWED_TOOLS', 'MCP server exposed no locally registered read-only tools');
        return ['snapshot_hash' => self::snapshotHash($items, $protocolVersion), 'items' => $items];
    }

    /** @param array<string,mixed> $frozen */
    public static function assertFrozen(array $frozen, McpReadonlyToolRegistry $registry): void
    {
        $definition = $registry->require((string) ($frozen['local_tool_code'] ?? ''));
        if (!hash_equals((string) ($frozen['registry_definition_hash'] ?? ''), McpReadonlyToolRegistry::definitionHash($definition))) {
            throw new ApiProblem('SAND_AI_MCP_REGISTRY_DRIFTED', 'Managed MCP read-only registration changed after publication');
        }
        $item = $frozen;
        $itemHash = $item['item_hash'] ?? null;
        unset($item['item_hash']);
        if (!is_string($itemHash) || preg_match('/\A[0-9a-f]{64}\z/', $itemHash) !== 1 || !hash_equals($itemHash, self::hash($item))
            || !hash_equals(self::hash($definition['input_schema']), self::hash($frozen['input_schema'] ?? null))
            || !hash_equals(self::hash($definition['output_schema']), self::hash($frozen['output_schema'] ?? null))
            || ($frozen['remote_name'] ?? null) !== $definition['remote_name']
            || ($frozen['citation_mode'] ?? null) !== 'sand_ai_standard'
            || (isset($definition['adapter']) && (self::hash(['schema' => $frozen['remote_input_schema'] ?? null]) !== self::hash(['schema' => $definition['adapter']['remote_input_schema']])
                || self::hash(['schema' => $frozen['remote_output_schema'] ?? null]) !== self::hash(['schema' => $definition['adapter']['remote_output_schema']])))) {
            throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Published MCP snapshot item is internally inconsistent');
        }
    }

    /** @param list<array<string,mixed>> $items */
    public static function snapshotHash(array $items, string $protocolVersion = McpStreamableHttpClient::PROTOCOL_VERSION): string
    {
        usort($items, static fn (array $left, array $right): int => strcmp((string) ($left['local_tool_code'] ?? ''), (string) ($right['local_tool_code'] ?? '')));
        McpStreamableHttpClient::assertProtocolVersion($protocolVersion);
        return self::hash(['protocol_version' => $protocolVersion, 'items' => $items]);
    }

    /** @param list<array<string,mixed>> $items */
    public static function assertSnapshot(string $snapshotHash, array $items, McpReadonlyToolRegistry $registry, string $protocolVersion = McpStreamableHttpClient::PROTOCOL_VERSION): void
    {
        if ($items === [] || preg_match('/\A[0-9a-f]{64}\z/', $snapshotHash) !== 1) {
            throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Managed MCP snapshot evidence is incomplete');
        }
        foreach ($items as $item) self::assertFrozen($item, $registry);
        if (!hash_equals($snapshotHash, self::snapshotHash($items, $protocolVersion))) {
            throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Managed MCP snapshot hash no longer matches its immutable items');
        }
    }

    private static function hash(mixed $value): string
    {
        return hash('sha256', ToolFingerprint::canonicalJson($value));
    }

    private static function invalidResponse(): never
    {
        throw new ApiProblem('SAND_AI_MCP_RESPONSE_INVALID', 'MCP server returned an invalid tool catalog');
    }

    private static function invalidRegistry(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Managed MCP tool registry contains duplicate remote names');
    }
}
