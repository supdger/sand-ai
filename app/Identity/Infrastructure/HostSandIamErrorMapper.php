<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use Throwable;

final class HostSandIamErrorMapper
{
    public static function toIdentityException(Throwable $exception): IdentityContextException
    {
        $message = $exception->getMessage();
        if (preg_match('/\A(SAND_(?:IAM|AI)_[A-Z0-9_]+)(?::|\z)/', $message, $matches) !== 1) {
            return new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
        }

        $code = [
            'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE' => 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE',
            'SAND_IAM_CONTEXT_EXPIRED' => 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
            'SAND_IAM_CREDENTIAL_REVOKED' => 'SAND_AI_CREDENTIAL_REVOKED',
            'SAND_IAM_AUTHENTICATION_FAILED' => 'SAND_AI_AUTHENTICATION_FAILED',
            'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH' => 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            'SAND_IAM_SERVICE_ACTION_FORBIDDEN' => 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            'SAND_IAM_INVOCATION_SCOPE_FORBIDDEN' => 'SAND_AI_INVOCATION_SCOPE_FORBIDDEN',
            'SAND_IAM_INVOCATION_FACTS_UNVERIFIED' => 'SAND_AI_INVOCATION_FACTS_UNVERIFIED',
            'SAND_IAM_SERVICE_NETWORK_FORBIDDEN' => 'SAND_AI_SERVICE_NETWORK_FORBIDDEN',
            'SAND_IAM_DATA_CLASS_FORBIDDEN' => 'SAND_AI_DATA_CLASS_FORBIDDEN',
            'SAND_IAM_SERVICE_QUOTA_EXCEEDED' => 'SAND_AI_SERVICE_QUOTA_EXCEEDED',
            'SAND_IAM_IDEMPOTENCY_CONFLICT' => 'SAND_AI_IDEMPOTENCY_CONFLICT',
            'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID' => 'SAND_AI_SERVICE_GRANT_CONSTRAINT_INVALID',
        ][$matches[1]] ?? 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE';

        return new IdentityContextException($code);
    }
}
