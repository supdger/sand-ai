<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\support;

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
        return json([
            'code' => 400,
            'message' => $problem->getMessage(),
            'data' => ['error_code' => $problem->errorCode, 'retryable' => $problem->retryable],
            'request_id' => $requestId,
        ], 400);
    }
}
