<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\middleware;

use app\Application\Tool\ToolConnectionRequestSecrets;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** Redacts the write-only connection credential before auth and errors run. */
final class ToolConnectionSensitiveRequestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            try {
                $payload = $this->payload($request);
                if ($payload === null || !is_array($payload['authorization'] ?? null)) {
                    ToolConnectionRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
                ToolConnectionRequestSecrets::store($request, $payload);
                if (!ToolConnectionRequestSecrets::redactRequest($request, $payload)) {
                    ToolConnectionRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
            } catch (Throwable) {
                ToolConnectionRequestSecrets::redactRawBody($request);
                return $this->failure();
            }

            try {
                return $handler($request);
            } catch (Throwable $exception) {
                ToolConnectionRequestSecrets::redactRawBody($request);
                throw $exception;
            }
        } finally {
            ToolConnectionRequestSecrets::clear($request);
        }
    }

    /** @return array<string, mixed>|null */
    private function payload(Request $request): ?array
    {
        $body = $request->rawBody();
        if ($body === '') {
            return null;
        }
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (str_contains($contentType, 'json')) {
            try {
                $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            return is_array($payload) && !array_is_list($payload) ? $payload : null;
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $payload);
            return is_array($payload) ? $payload : null;
        }

        return null;
    }

    private function failure(): Response
    {
        return json([
            'code' => 422,
            'message' => '外部资料连接未保存，请检查输入后重试',
            'data' => ['error_code' => 'SAND_AI_TOOL_CONNECTION_REQUEST_FAILED', 'retryable' => false],
        ])->withStatus(422);
    }
}
