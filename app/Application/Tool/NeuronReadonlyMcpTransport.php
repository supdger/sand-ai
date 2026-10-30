<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use NeuronAI\MCP\McpTransportInterface;

/** Official MCP protocol execution consumes the existing bounded, current-authorized HTTP transport. */
final class NeuronReadonlyMcpTransport implements McpTransportInterface
{
    private int $deadline;
    private int $sequence = 0;
    private int $latency = 0;
    private int $lastStatus = 0;
    private int $pages = 0;
    private ?string $sessionId = null;
    private ?array $pending = null;
    private array $catalog = [];
    private array $seenCursors = [];
    private ?array $server = null;
    private ?string $protocolVersion = null;

    /** @param array<string,string> $managedHeaders */
    public function __construct(
        private readonly StandardReadonlyHttpTransport $transport,
        private readonly VerifiedToolEndpoint $endpoint,
        private readonly array $managedHeaders,
        private readonly int $timeoutMs,
        private readonly int $responseMaxBytes,
        private readonly \Closure $onExchangeEvent,
        private readonly int $maxPages,
        private readonly int $maxTools,
        private readonly int $maxSchemaBytes,
        private readonly ?string $expectedProtocolVersion = null,
        private readonly ?\Closure $onProtocolNegotiated = null,
    ) {
        if ($expectedProtocolVersion !== null) McpStreamableHttpClient::assertProtocolVersion($expectedProtocolVersion);
        $this->deadline = (int) hrtime(true) + $timeoutMs * 1_000_000;
    }

    public function connect(): void
    {
        // The verified endpoint is already fixed; construction cannot contact the server.
    }

    public function setProtocolVersion(string $version): void
    {
        McpStreamableHttpClient::assertProtocolVersion($version);
        if (($this->expectedProtocolVersion !== null && $version !== $this->expectedProtocolVersion)
            || ($this->protocolVersion !== null && $version !== $this->protocolVersion)) {
            throw new ApiProblem('SAND_AI_MCP_PROTOCOL_MISMATCH', 'MCP server did not complete the required stable initialization');
        }
        $this->protocolVersion = $version;
        if ($this->onProtocolNegotiated !== null) ($this->onProtocolNegotiated)($version);
    }

    public function disconnect(): void
    {
        // Do not add an unmetered remote DELETE or reset a possibly sent intent.
        $this->pending = null;
    }

    public function send(array $data): void
    {
        if ($this->pending !== null || ($data['jsonrpc'] ?? null) !== '2.0') $this->invalidResponse();
        $method = $data['method'] ?? null;
        $kind = match ($method) {
            'initialize' => 'initialize',
            'notifications/initialized' => 'initialized',
            'tools/list' => 'tools_list',
            'tools/call' => 'tools_call',
            default => throw new ApiProblem('SAND_AI_MCP_SERVER_MESSAGE_REJECTED', 'MCP request is outside the read-only managed profile'),
        };
        $notification = $method === 'notifications/initialized';
        if (!$notification && !is_int($data['id'] ?? null)) $this->invalidResponse();
        if ($method === 'tools/list' && ++$this->pages > $this->maxPages) {
            throw new ApiProblem('SAND_AI_MCP_CATALOG_LIMIT_EXCEEDED', 'MCP tool catalog pagination exceeds the managed limit');
        }
        $headers = $this->protocolHeaders($this->managedHeaders, $this->sessionId, $this->endpoint);
        if ($method === 'initialize') unset($headers['MCP-Protocol-Version']);
        $response = $this->requestHttp($this->endpoint, $headers, $data, $this->timeoutMs, $this->responseMaxBytes,
            $this->onExchangeEvent, $kind, ++$this->sequence, $this->deadline, $notification);
        $this->latency += (int) $response->latencyMs;
        $this->lastStatus = $response->statusCode;
        if ($notification) {
            if ($response->statusCode !== 202 || $response->body !== '') {
                throw new ApiProblem('SAND_AI_MCP_SERVER_MESSAGE_REJECTED', 'MCP server sent an unsolicited message');
            }
            return;
        }
        $result = $this->rpcResult($response, $data['id']);
        if ($method === 'initialize') $this->acceptInitialization($response, $result);
        if ($method === 'tools/list') $this->acceptCatalogPage($result);
        $this->pending = StrictJsonObjectDecoder::decodeObject($response->body);
        if ($method === 'tools/list' && ($result['nextCursor'] ?? null) === '') {
            unset($this->pending['result']['nextCursor']);
        }
    }

    public function receive(): array
    {
        if ($this->pending === null) $this->invalidResponse();
        $message = $this->pending;
        $this->pending = null;
        return $message;
    }

    /** @return array{server_name:string,server_version:string,request_count:int,total_latency_ms:int} */
    public function facts(): array
    {
        if ($this->server === null || $this->protocolVersion === null) $this->invalidResponse();
        return $this->server + ['protocol_version' => $this->protocolVersion, 'request_count' => $this->sequence, 'total_latency_ms' => $this->latency];
    }

    public function lastStatusCode(): int
    {
        return $this->lastStatus;
    }

    private function acceptInitialization(ToolHttpResponse $response, array $result): void
    {
        if (!is_string($result['protocolVersion'] ?? null)
            || ((!is_array($result['capabilities'] ?? null) || array_is_list($result['capabilities']))
                && !(($result['capabilities'] ?? null) instanceof \stdClass))
            || !is_array($result['serverInfo'] ?? null) || array_is_list($result['serverInfo'])
            || !is_string($result['serverInfo']['name'] ?? null) || trim($result['serverInfo']['name']) === ''
            || !is_string($result['serverInfo']['version'] ?? null) || trim($result['serverInfo']['version']) === '') {
            throw new ApiProblem('SAND_AI_MCP_PROTOCOL_MISMATCH', 'MCP server did not complete the required stable initialization');
        }
        $sessionValues = $response->headerValues('MCP-Session-Id');
        if (count($sessionValues) > 1) {
            throw new ApiProblem('SAND_AI_MCP_SESSION_INVALID', 'MCP server returned ambiguous session identifiers');
        }
        $this->sessionId = $sessionValues[0] ?? null;
        if ($this->sessionId !== null && preg_match('/\A[\x21-\x7e]{1,1024}\z/D', $this->sessionId) !== 1) {
            throw new ApiProblem('SAND_AI_MCP_SESSION_INVALID', 'MCP server returned an invalid session identifier');
        }
        $this->server = [
            'server_name' => trim($result['serverInfo']['name']),
            'server_version' => trim($result['serverInfo']['version']),
        ];
    }

    private function acceptCatalogPage(array $result): void
    {
        if (!is_array($result['tools'] ?? null) || !array_is_list($result['tools'])) $this->invalidResponse();
        foreach ($result['tools'] as $tool) {
            if (!is_array($tool) || array_is_list($tool)) $this->invalidResponse();
            $this->catalog[] = $tool;
            if (count($this->catalog) > $this->maxTools) {
                throw new ApiProblem('SAND_AI_MCP_CATALOG_LIMIT_EXCEEDED', 'MCP tool catalog exceeds the managed limit');
            }
        }
        $this->assertCatalogBytes($this->catalog);
        $next = $result['nextCursor'] ?? null;
        if ($next !== null && $next !== '') {
            if (!is_string($next) || strlen($next) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $next) === 1
                || isset($this->seenCursors[$next])) {
                throw new ApiProblem('SAND_AI_MCP_CURSOR_INVALID', 'MCP tool pagination cursor is invalid or repeated');
            }
            $this->seenCursors[$next] = true;
        }
    }
    /** @param array<string,string> $managedHeaders @return array<string,string> */
    private function protocolHeaders(array $managedHeaders, ?string $sessionId, VerifiedToolEndpoint $endpoint): array
    {
        foreach (array_keys($managedHeaders) as $name) {
            if (in_array(strtolower($name), ['mcp-session-id', 'mcp-protocol-version', 'origin', 'accept'], true)) unset($managedHeaders[$name]);
        }
        $headers = array_replace($managedHeaders, ['Origin' => $endpoint->origin, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => $this->protocolVersion ?? McpStreamableHttpClient::PROTOCOL_VERSION]);
        if ($sessionId !== null) $headers['MCP-Session-Id'] = $sessionId;
        return $headers;
    }


    /** @param array<string,string> $headers @param array<string,mixed> $payload @param callable(string,int,string,?int):void $onExchangeEvent */
    private function requestHttp(VerifiedToolEndpoint $endpoint, array $headers, array $payload, int $timeoutMs, int $responseMaxBytes, callable $onExchangeEvent, string $kind, int $sequence, int $deadline, bool $notification = false): ToolHttpResponse
    {
        $remainingMs = min($timeoutMs, (int) floor(($deadline - (int) hrtime(true)) / 1_000_000));
        if ($remainingMs < 100) {
            throw new ApiProblem('SAND_AI_TOOL_TIMEOUT', 'Managed MCP operation exceeded its published timeout');
        }
        $response = $this->transport->request($endpoint, $headers, $payload, $remainingMs, $responseMaxBytes, static fn () => $onExchangeEvent($kind, $sequence, 'dispatch_started', null));
        $onExchangeEvent($kind, $sequence, 'response_known', $response->statusCode);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new ApiProblem('SAND_AI_MCP_UPSTREAM_REJECTED', 'MCP server rejected the managed protocol request');
        }
        if ($notification && $response->body === '' && in_array($response->statusCode, [200, 202, 204], true)) return $response;
        $contentType = strtolower((string) $response->header('Content-Type'));
        if (preg_match('#\Atext/event-stream(?:\s*;|\z)#', $contentType) === 1) {
            if ($notification || !is_int($payload['id'] ?? null)) {
                $this->invalidResponse();
            }
            return $this->sseResponse($response, $payload['id']);
        }
        if (!preg_match('#\Aapplication/json(?:\s*;|\z)#', $contentType)) {
            throw new ApiProblem('SAND_AI_MCP_RESPONSE_INVALID', 'MCP server did not return application/json');
        }
        return $response;
    }

    private function sseResponse(ToolHttpResponse $response, int $expectedId): ToolHttpResponse
    {
        $body = str_starts_with($response->body, "\xEF\xBB\xBF") ? substr($response->body, 3) : $response->body;
        $lines = preg_split('/\r\n|\r|\n/', $body);
        if ($lines === false) $this->invalidResponse();
        array_pop($lines);
        $data = [];
        $eventType = 'message';
        $responseJson = null;
        foreach ($lines as $line) {
            if ($line === '') {
                if ($data !== []) {
                    $json = implode("\n", $data);
                    if ($json !== '') {
                        if ($eventType !== 'message') $this->invalidResponse();
                        $message = StrictJsonObjectDecoder::decodeObject($json);
                        if (($message['jsonrpc'] ?? null) !== '2.0') $this->invalidResponse();
                        if (array_key_exists('method', $message)) {
                            if (!is_string($message['method']) || $message['method'] === ''
                                || array_key_exists('id', $message) || array_key_exists('result', $message)
                                || array_key_exists('error', $message)) {
                                throw new ApiProblem('SAND_AI_MCP_SERVER_MESSAGE_REJECTED', 'MCP server sent an unsolicited request or notification');
                            }
                        } elseif (!array_key_exists('id', $message) || $message['id'] !== $expectedId || $responseJson !== null) {
                            $this->invalidResponse();
                        } else {
                            $responseJson = $json;
                        }
                    }
                }
                $data = [];
                $eventType = 'message';
                continue;
            }
            if ($line[0] === ':') continue;
            $parts = explode(':', $line, 2);
            $value = $parts[1] ?? '';
            if (str_starts_with($value, ' ')) $value = substr($value, 1);
            if ($parts[0] === 'data') $data[] = $value;
            if ($parts[0] === 'event') $eventType = $value === '' ? 'message' : $value;
        }
        if ($data !== [] || $responseJson === null) {
            throw new ApiProblem('SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'External tool request result is uncertain');
        }
        return new ToolHttpResponse($response->statusCode, $responseJson, $response->latencyMs, $response->headers);
    }

    /** @return array<string,mixed> */
    private function rpcResult(ToolHttpResponse $response, int $expectedId): array
    {
        $message = StrictJsonObjectDecoder::decodeObject($response->body);
        if (!is_array($message) || array_is_list($message) || isset($message['method']) || ($message['jsonrpc'] ?? null) !== '2.0'
            || !array_key_exists('id', $message) || $message['id'] !== $expectedId || array_key_exists('error', $message)
            || !is_array($message['result'] ?? null) || array_is_list($message['result'])) {
            if (is_array($message) && isset($message['method'])) {
                throw new ApiProblem('SAND_AI_MCP_SERVER_MESSAGE_REJECTED', 'MCP server sent an unsolicited request or notification');
            }
            $this->invalidResponse();
        }
        return $message['result'];
    }

    /** @param list<array<string,mixed>> $tools */
    private function assertCatalogBytes(array $tools): void
    {
        try {
            $encoded = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalidResponse();
        }
        if (strlen($encoded) > $this->maxSchemaBytes) throw new ApiProblem('SAND_AI_MCP_CATALOG_LIMIT_EXCEEDED', 'MCP tool catalog exceeds the managed byte limit');
    }

    private function invalidResponse(): never
    {
        throw new ApiProblem('SAND_AI_MCP_RESPONSE_INVALID', 'MCP server returned an invalid JSON-RPC response');
    }
}
