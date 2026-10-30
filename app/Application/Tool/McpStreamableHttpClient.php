<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** SandAI read-only contract; Neuron owns MCP handshake, request IDs and catalog pagination. */
final class McpStreamableHttpClient
{
    public const PROTOCOL_VERSION = '2025-11-25';
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18'];

    public static function assertProtocolVersion(string $version): void
    {
        if (!in_array($version, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            throw new ApiProblem('SAND_AI_MCP_PROTOCOL_MISMATCH', 'MCP server did not complete a supported stable initialization');
        }
    }

    public static function protocolVersionFromWireContract(mixed $wireContract): string
    {
        foreach (self::SUPPORTED_PROTOCOL_VERSIONS as $version) {
            if ($wireContract === 'mcp_streamable_http/' . $version . '/json') return $version;
        }
        throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Frozen MCP wire contract is invalid');
    }

    public function __construct(
        private readonly StandardReadonlyHttpTransport $transport = new GuzzleStandardReadonlyHttpTransport(),
        private readonly int $maxPages = 20,
        private readonly int $maxTools = 500,
        private readonly int $maxSchemaBytes = 524288,
    ) {
        if ($maxPages < 1 || $maxPages > 100 || $maxTools < 1 || $maxTools > 5000 || $maxSchemaBytes < 1024 || $maxSchemaBytes > 4194304) {
            throw new ApiProblem('SAND_AI_MCP_CONFIGURATION_INVALID', 'Managed MCP safety limits are invalid');
        }
    }

    /**
     * @param array<string,string> $managedHeaders
     * @param callable(string,int,string,?int):void $onExchangeEvent
     * @return array{server_name:string,server_version:string,tools:list<array<string,mixed>>,request_count:int,total_latency_ms:int}
     */
    public function discover(VerifiedToolEndpoint $endpoint, array $managedHeaders, int $timeoutMs, int $responseMaxBytes, callable $onExchangeEvent, ?callable $onProtocolNegotiated = null): array
    {
        $transport = $this->controlledTransport($endpoint, $managedHeaders, $timeoutMs, $responseMaxBytes, $onExchangeEvent, null, $onProtocolNegotiated);
        $client = new NeuronReadonlyMcpClient(['transport' => $transport]);
        $tools = $client->listTools();
        return $transport->facts() + ['tools' => $tools];
    }

    /**
     * @param array<string,string> $managedHeaders
     * @param array<string,mixed> $frozenItem
     * @param array<string,mixed>|\stdClass $input
     * @param callable(string,int,string,?int):void $onExchangeEvent
     */
    public function call(VerifiedToolEndpoint $endpoint, array $managedHeaders, int $timeoutMs, int $responseMaxBytes, array $frozenItem, array|\stdClass $input, McpReadonlyToolRegistry $registry, callable $onExchangeEvent, string $expectedProtocolVersion = self::PROTOCOL_VERSION): ToolProviderOutcome
    {
        McpSnapshotPolicy::assertFrozen($frozenItem, $registry);
        ToolSchemaPolicy::assertValue((array) $frozenItem['input_schema'], $input);
        $definition = $registry->require((string) $frozenItem['local_tool_code']);
        $adapter = $definition['adapter'] ?? null;
        $arguments = is_array($adapter) ? McpDocumentAdapter::arguments($adapter, $input) : (array) $input;
        $transport = $this->controlledTransport($endpoint, $managedHeaders, $timeoutMs, $responseMaxBytes, $onExchangeEvent, $expectedProtocolVersion);
        $client = new NeuronReadonlyMcpClient(['transport' => $transport]);
        $response = $client->callTool((string) $frozenItem['remote_name'], $arguments);
        $result = $response['result'];
        if (is_array($adapter)) {
            $mapped = McpDocumentAdapter::result($adapter, $result, (array) $input);
            $facts = $transport->facts();
            return ToolResponsePolicy::normalizeStructuredContent(
                $mapped['result'], $mapped['citations'], (array) $frozenItem['output_schema'],
                $transport->lastStatusCode(), $facts['total_latency_ms'], (string) $frozenItem['local_tool_code'],
                is_array($input) && is_int($input['limit'] ?? null) ? $input['limit'] : 20,
                $adapter['kind'] === 'document_search@1' || PkulawArticleAdapter::supports($adapter['kind']) || PkulawCaseAdapter::supports($adapter['kind']) || PkulawCaseFilteredAdapter::supports($adapter['kind']),
            );
        }
        if (($result['isError'] ?? false) === true || !is_array($result['structuredContent'] ?? null) || array_is_list($result['structuredContent'])) {
            throw new ApiProblem('SAND_AI_MCP_STRUCTURED_RESULT_REQUIRED', 'MCP tool did not return an accepted structured result');
        }
        $structured = $result['structuredContent'];
        $keys = array_keys($structured);
        sort($keys, SORT_STRING);
        if ($keys !== ['citations', 'result'] || (!is_array($structured['result']) && !$structured['result'] instanceof \stdClass)
            || (is_array($structured['result']) && array_is_list($structured['result']))
            || !is_array($structured['citations']) || !array_is_list($structured['citations'])) {
            throw new ApiProblem('SAND_AI_MCP_STRUCTURED_RESULT_REQUIRED', 'MCP tool did not return an accepted structured result');
        }
        if (is_array($frozenItem['remote_output_schema'] ?? null)) {
            ToolSchemaPolicy::assertValue($frozenItem['remote_output_schema'], $structured);
        }
        $facts = $transport->facts();
        return ToolResponsePolicy::normalizeStructuredContent(
            $structured['result'], $structured['citations'], (array) $frozenItem['output_schema'],
            $transport->lastStatusCode(), $facts['total_latency_ms'], (string) $frozenItem['local_tool_code'],
            is_array($input) && is_int($input['limit'] ?? null) ? $input['limit'] : 20,
        );
    }

    /** @param array<string,string> $headers @param callable(string,int,string,?int):void $events */
    private function controlledTransport(VerifiedToolEndpoint $endpoint, array $headers, int $timeoutMs, int $responseMaxBytes, callable $events, ?string $expectedProtocolVersion = null, ?callable $onProtocolNegotiated = null): NeuronReadonlyMcpTransport
    {
        return new NeuronReadonlyMcpTransport($this->transport, $endpoint, $headers, $timeoutMs, $responseMaxBytes,
            \Closure::fromCallable($events), $this->maxPages, $this->maxTools, $this->maxSchemaBytes, $expectedProtocolVersion,
            $onProtocolNegotiated === null ? null : \Closure::fromCallable($onProtocolNegotiated));
    }
}
