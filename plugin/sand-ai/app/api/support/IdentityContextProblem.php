<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\support;

use plugin\SandAi\app\contract\IdentityContextException;

/**
 * Stable local-API identity refusals. Messages never include object keys,
 * source text, storage coordinates, or host SandIAM payload fields.
 */
final class IdentityContextProblem
{
    public static function from(IdentityContextException $exception): ApiProblem
    {
        return new ApiProblem($exception->errorCode, match ($exception->errorCode) {
            'SAND_AI_SERVICE_ACTION_FORBIDDEN' => 'The caller is not granted this SandAI service action',
            'SAND_AI_AUTHENTICATION_FAILED' => 'Workload identity context is invalid',
            'SAND_AI_IDENTITY_CONTEXT_EXPIRED' => 'Workload identity context has expired',
            'SAND_AI_CREDENTIAL_REVOKED' => 'Workload credential is no longer valid',
            default => 'SandIAM workload context is unavailable',
        });
    }
}
