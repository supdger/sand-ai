<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\identity;

use plugin\SandAi\app\contract\IdentityContextException;
use Throwable;

/**
 * Maps host SandIAM runtime refusals onto SandAI identity error codes.
 *
 * SandAI never forwards SandIAM message text, table names, or payload fields.
 * Unknown host failures stay fail-closed as unavailable context.
 */
final class HostSandIamErrorMapper
{
    public static function toIdentityException(Throwable $exception): IdentityContextException
    {
        $message = $exception->getMessage();
        foreach ([
            'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE' => 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE',
            'SAND_IAM_CONTEXT_EXPIRED' => 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
            'SAND_IAM_CREDENTIAL_REVOKED' => 'SAND_AI_CREDENTIAL_REVOKED',
            'SAND_IAM_AUTHENTICATION_FAILED' => 'SAND_AI_AUTHENTICATION_FAILED',
            'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH' => 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            'SAND_IAM_SERVICE_ACTION_FORBIDDEN' => 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
        ] as $hostCode => $sandAiCode) {
            if (str_contains($message, $hostCode)) {
                return new IdentityContextException($sandAiCode);
            }
        }

        return new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    }
}
