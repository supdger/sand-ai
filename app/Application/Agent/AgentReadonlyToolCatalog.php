<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Tool\McpReadonlyToolRegistry;
use app\Application\Tool\McpSnapshotPolicy;
use app\Application\Tool\McpStreamableHttpClient;
use app\Application\Tool\ToolFingerprint;
use app\Application\Tool\ToolSchemaPolicy;
use app\Infrastructure\Persistence\Model\Tool;
use app\Infrastructure\Persistence\Model\ToolVersion;
use app\Infrastructure\Persistence\Model\ToolEnvironmentBinding;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshot;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshotItem;

/** Server-resolved immutable selection. No endpoint or credential enters the SDK descriptor. */
final class AgentReadonlyToolCatalog
{
    public function freeze(int $environmentId, array $selections): array
    {
        if ($environmentId < 1) self::drift();
        return array_map(fn(array $selection): array => $this->binding($environmentId, $selection),
            AgentReadonlyToolSelection::normalize($selections));
    }

    public function assertCurrent(int $environmentId, array $frozen): void
    {
        if (!array_is_list($frozen) || count($frozen) > 2) self::drift();
        foreach ($frozen as $binding) {
            if (!is_array($binding) || !is_array($binding['selection'] ?? null)) self::drift();
            $fresh = $this->binding($environmentId, $binding['selection']);
            if (!hash_equals(ToolFingerprint::for('agent_readonly_binding', $fresh),
                ToolFingerprint::for('agent_readonly_binding', $binding))) self::drift();
        }
    }

    private function binding(int $environmentId, array $selection): array
    {
        $selection = AgentReadonlyToolSelection::normalize([$selection])[0];
        $tool = Tool::where('code', $selection['code'])->where('state', 'published')
            ->where('status', 1)->where('effect_class', 'read_only')
            ->whereIn('adapter', ['standard_readonly_http', 'mcp_streamable_http'])->find();
        $version = $tool === null ? null : ToolVersion::where('tool_id', (int) $tool->id)
            ->where('version_no', $selection['version'])->where('state', 'published')->find();
        if ($version === null) throw new ApiProblem('SAND_AI_TOOL_VERSION_NOT_PUBLISHED', 'Readonly tool version is unavailable');
        $binding = ToolEnvironmentBinding::where('environment_id', $environmentId)->where('tool_id', (int) $tool->id)
            ->where('tool_version_id', (int) $version->id)->where('state', 'published')->where('status', 1)->find();
        if ($binding === null) throw new ApiProblem('SAND_AI_TOOL_NOT_BOUND', 'Readonly tool is not bound in the agent environment');
        $connection = ToolConnection::where('id', (int) $binding->tool_connection_id)
            ->where('environment_id', $environmentId)->where('state', 'published')->where('status', 1)->find();
        $revision = ToolConnectionRevision::where('id', (int) $binding->tool_connection_revision_id)
            ->where('tool_connection_id', (int) $binding->tool_connection_id)->where('state', 'published')->find();
        if ($connection === null || $revision === null || !is_string($revision->data_class)
            || preg_match('/\A[a-z0-9][a-z0-9._-]{1,31}\z/D', $revision->data_class) !== 1
            || !is_array($version->review_policy) || !is_bool($version->review_policy['review_required'] ?? null)) self::drift();
        $input = ToolSchemaPolicy::normalize((array) $version->input_schema);
        $output = ToolSchemaPolicy::normalize((array) $version->output_schema);
        // Use the SDK's supported schema model now; reject unsupported tools before publishing.
        try { \NeuronAI\Tools\ToolPropertyFactory::fromSchema($input); }
        catch (\Throwable) { throw new ApiProblem('SAND_AI_AGENT_TOOL_FORBIDDEN', 'Readonly tool schema is not supported by the authorized SDK tool interface'); }
        $item = null;
        $snapshot = null;
        $protocol = null;
        if ((string) $tool->adapter === 'mcp_streamable_http') {
            $item = ToolMcpSnapshotItem::where('id', (int) $version->mcp_snapshot_item_id)->find();
            $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)->where('state', 'tested')->find();
            if ($item === null || $snapshot === null || (int) $snapshot->environment_id !== $environmentId
                || (int) $snapshot->tool_connection_id !== (int) $connection->id
                || (int) $snapshot->tool_connection_revision_id !== (int) $revision->id) self::drift();
            $protocol = (string) $snapshot->protocol_version;
            McpStreamableHttpClient::assertProtocolVersion($protocol);
            McpSnapshotPolicy::assertFrozen([
                'local_tool_code' => (string) $item->local_tool_code, 'remote_name' => (string) $item->remote_name,
                'remote_description' => (string) $item->remote_description, 'remote_input_schema' => (array) $item->remote_input_schema,
                'remote_output_schema' => is_array($item->remote_output_schema) ? $item->remote_output_schema : null,
                'input_schema' => (array) $item->input_schema, 'output_schema' => (array) $item->output_schema,
                'citation_mode' => (string) $item->citation_mode, 'registry_definition_hash' => (string) $item->registry_definition_hash,
                'item_hash' => (string) $item->item_hash,
            ], new McpReadonlyToolRegistry());
        } elseif ($version->mcp_snapshot_item_id !== null) self::drift();
        $frozen = [
            'execution_limits' => AgentReadonlyToolProtocol::LIMITS,
            'version' => 1, 'selection' => $selection, 'tool_id' => (int) $tool->id, 'tool_version_id' => (int) $version->id,
            'binding_id' => (int) $binding->id, 'connection_id' => (int) $connection->id,
            'connection_revision_id' => (int) $revision->id, 'connection_config_fingerprint' => (string) $revision->config_fingerprint,
            'adapter' => (string) $tool->adapter, 'effect_class' => 'read_only', 'data_class' => $revision->data_class,
            'description' => (string) $version->description, 'input_schema' => $input, 'output_schema' => $output,
            'review_required' => $version->review_policy['review_required'], 'timeout_ms' => (int) $version->timeout_ms,
            'response_max_bytes' => (int) $version->response_max_bytes, 'mcp_snapshot_id' => $snapshot === null ? null : (int) $snapshot->id,
            'mcp_snapshot_item_id' => $item === null ? null : (int) $item->id,
            'mcp_snapshot_item_hash' => $item === null ? null : (string) $item->item_hash,
            'mcp_registry_hash' => $item === null ? null : (string) $item->registry_definition_hash,
            'wire_contract' => $protocol === null ? 'standard_readonly_http/v1' : 'mcp_streamable_http/' . $protocol . '/json',
        ];
        return $frozen + ['snapshot_sha256' => ToolFingerprint::for('agent_readonly_binding', $frozen)];
    }

    private static function drift(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Frozen readonly tool binding is unavailable or changed');
    }
}
