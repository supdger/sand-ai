<?php

declare(strict_types=1);

namespace app\Api\Support;

use support\Response;

final class ApiResponder
{
    /** @param array<string, mixed> $data */
    public static function success(array $data, string $requestId): Response
    {
        return json(['code' => 200, 'message' => 'success', 'data' => $data, 'request_id' => $requestId]);
    }

    public static function error(ApiProblem $problem, string $requestId): Response
    {
        $status = match ($problem->errorCode) {
            'SAND_AI_SKILL_SOURCE_CONTENT_PURGED' => 410,
            'SAND_AI_FILE_UPLOAD_KEY_CONFLICT' => 409,
            default => 400,
        };
        return json([
            'code' => $status,
            'message' => $problem->getMessage(),
            'data' => ['error_code' => $problem->errorCode, 'retryable' => $problem->retryable],
            'request_id' => $requestId,
        ])->withStatus($status);
    }
}
