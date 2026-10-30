<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\middleware;

use app\Application\Channel\WecomCredentialRequestSecrets;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** Removes credentials from request data before authentication, logging or error handling. */
final class WecomCredentialSensitiveRequestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            try {
                $payload = $this->payload($request);
                if ($payload === null) {
                    WecomCredentialRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
                WecomCredentialRequestSecrets::store($request, $payload);
                $safe = array_intersect_key($payload, array_flip(['environment_id','channel_binding_id']));
                if (!WecomCredentialRequestSecrets::redact($request, $safe)) {
                    WecomCredentialRequestSecrets::redactRawBody($request);
                    return $this->failure();
                }
            } catch (Throwable) {
                WecomCredentialRequestSecrets::redactRawBody($request);
                return $this->failure();
            }
            try { return $handler($request); }
            catch (Throwable $exception) {
                WecomCredentialRequestSecrets::redactRawBody($request);
                throw $exception;
            }
        } finally {
            WecomCredentialRequestSecrets::clear($request);
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
            'message' => '企业微信配置未保存，请检查输入后重试',
            'data' => ['error_code' => 'SAND_AI_CHANNEL_REQUEST_FAILED', 'retryable' => false],
        ])->withStatus(422);
    }
}
