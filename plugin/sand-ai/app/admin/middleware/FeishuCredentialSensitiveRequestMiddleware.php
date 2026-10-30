<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\middleware;

use app\Application\Channel\FeishuCredentialRequestSecrets;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** Removes credentials from request data before authentication, logging or error handling. */
final class FeishuCredentialSensitiveRequestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            try {
                $payload = $this->payload($request);
                if ($payload === null) {
                    FeishuCredentialRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
                FeishuCredentialRequestSecrets::store($request, $payload);
                $safe = array_intersect_key($payload, array_flip(['environment_id','channel_binding_id']));
                if (!FeishuCredentialRequestSecrets::redact($request, $safe)) {
                    FeishuCredentialRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
            } catch (Throwable) {
                FeishuCredentialRequestSecrets::redactRawBody($request);
                return $this->failure();
            }
            try { return $handler($request); }
            catch (Throwable $exception) {
                FeishuCredentialRequestSecrets::redactRawBody($request);
                throw $exception;
            }
        } finally {
            FeishuCredentialRequestSecrets::clear($request);
        }
    }

    /** @return array<string,mixed>|null */
    private function payload(Request $request): ?array
    {
        $body = $request->rawBody();
        if ($body === '') return null;
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (str_contains($contentType, 'json')) {
            try { $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return null; }
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
            'message' => '飞书配置未保存，请检查输入后重试',
            'data' => ['error_code' => 'SAND_AI_CHANNEL_REQUEST_FAILED', 'retryable' => false],
        ])->withStatus(422);
    }
}
