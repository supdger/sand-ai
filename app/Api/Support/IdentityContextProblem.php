<?php

declare(strict_types=1);

namespace app\Api\Support;

use app\Identity\Contract\IdentityContextException;

/** Stable, non-sensitive local API errors for SandIAM workload refusals. */
final class IdentityContextProblem
{
    public static function from(IdentityContextException $exception): ApiProblem
    {
        return new ApiProblem($exception->errorCode, match ($exception->errorCode) {
            'SAND_AI_SERVICE_ACTION_FORBIDDEN' => 'The caller is not granted this SandAI service action',
            'SAND_AI_AUTHENTICATION_FAILED' => 'Workload identity context is invalid',
            'SAND_AI_IDENTITY_CONTEXT_EXPIRED' => 'Workload identity context has expired',
            'SAND_AI_CREDENTIAL_REVOKED' => 'Workload credential is no longer valid',
            'SAND_AI_INVOCATION_SCOPE_FORBIDDEN' => 'The requested resource is outside the workload scope',
            'SAND_AI_INVOCATION_FACTS_UNVERIFIED' => 'The requested resource cannot be verified',
            'SAND_AI_SERVICE_NETWORK_FORBIDDEN' => 'The workload network is not allowed',
            'SAND_AI_DATA_CLASS_FORBIDDEN' => 'The workload grant does not allow this data class',
            'SAND_AI_SERVICE_QUOTA_EXCEEDED' => 'The workload service quota is exhausted',
            'SAND_AI_IDEMPOTENCY_CONFLICT' => 'The operation ID belongs to another invocation',
            'SAND_AI_SERVICE_GRANT_CONSTRAINT_INVALID' => 'The workload grant constraints are invalid',
            default => 'SandIAM workload context is unavailable',
        });
    }
}
