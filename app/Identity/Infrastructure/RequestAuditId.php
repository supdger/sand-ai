<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use support\Request;

/** One audit request ID per HTTP request, shared by response and SandIAM ports. */
final class RequestAuditId
{
    public static function isValid(string $requestId): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) === 1;
    }

    public static function ensure(Request $request): string
    {
        $requestId = trim((string) $request->header('x-request-id', ''));
        if (self::isValid($requestId)) {
            return $requestId;
        }

        $requestId = 'req_' . bin2hex(random_bytes(16));
        $request->setHeader('x-request-id', $requestId);
        return $requestId;
    }
}
