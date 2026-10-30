<?php

declare(strict_types=1);

namespace app\Mcp;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\RequestAuditId;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use support\Request;
use support\Response;

/** In-process adapter into the existing public Controllers; never sends an HTTP request. */
final class McpBusinessBridge
{
    public function __construct(private readonly Request $incoming)
    {
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $query */
    public function request(string $method, string $path, array $body = [], array $query = [], ?string $idempotencyKey = null): Request
    {
        if (!in_array($method, ['GET', 'POST'], true)
            || !str_starts_with($path, '/api/sand-ai/v1/')
            || preg_match('/[\r\n?#]/', $path) === 1) {
            throw new ApiProblem('SAND_AI_MCP_TOOL_INVALID', 'MCP business target is invalid');
        }
        $authorization = (string) $this->incoming->header('authorization', '');
        if (preg_match('/[\r\n]/', $authorization) === 1
            || ($idempotencyKey !== null && preg_match('/^[!-~]{1,512}$/D', $idempotencyKey) !== 1)) {
            throw new ApiProblem('SAND_AI_MCP_TOOL_INVALID', 'MCP business request is invalid');
        }
        $target = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $method === 'POST' ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '';
        $headers = [
            'Host: sand-ai.internal',
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $authorization,
            'X-Request-Id: ' . RequestAuditId::ensure($this->incoming),
            'Content-Length: ' . strlen($payload),
        ];
        if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        $request = new Request($method . ' ' . $target . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $payload);
        // Current SandIAM ports derive source IP from this real connection, not caller tool arguments.
        $request->connection = $this->incoming->connection;
        return $request;
    }

    public function result(Response $response): CallToolResult
    {
        $body = $response->rawBody();
        $maximum = (int) config('sand_ai_mcp_server.max_response_bytes', 2097152);
        if ($maximum < 1024 || strlen($body) > $maximum) {
            return self::failure('SAND_AI_MCP_RESULT_UNAVAILABLE', true);
        }
        try {
            $envelope = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::failure('SAND_AI_MCP_RESULT_UNAVAILABLE', true);
        }
        if (!is_array($envelope) || array_is_list($envelope) || !is_int($envelope['code'] ?? null)
            || !is_array($envelope['data'] ?? null) || !is_string($envelope['request_id'] ?? null)
            || !RequestAuditId::isValid($envelope['request_id'])) {
            return self::failure('SAND_AI_MCP_RESULT_UNAVAILABLE', true);
        }
        if ($response->getStatusCode() >= 400 || $envelope['code'] !== 200) {
            $error = $envelope['data']['error_code'] ?? null;
            $code = is_string($error) && preg_match('/^SAND_AI_[A-Z0-9_]+$/D', $error) === 1
                ? $error : 'SAND_AI_MCP_RESULT_UNAVAILABLE';
            return self::failure($code, ($envelope['data']['retryable'] ?? false) === true, $envelope['request_id']);
        }
        $safe = ['code' => 200, 'data' => $envelope['data'], 'request_id' => $envelope['request_id']];
        return new CallToolResult(
            [new TextContent(json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],
            structuredContent: $safe,
        );
    }

    public static function failure(string $code, bool $retryable, ?string $requestId = null): CallToolResult
    {
        $safe = ['error_code' => $code, 'retryable' => $retryable];
        if ($requestId !== null) $safe['request_id'] = $requestId;
        return new CallToolResult(
            [new TextContent(json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],
            isError: true,
            structuredContent: $safe,
        );
    }

    public function __debugInfo(): array
    {
        return ['in_process' => true];
    }

    public function __serialize(): array
    {
        throw new \LogicException('MCP business request cannot be serialized');
    }
}
