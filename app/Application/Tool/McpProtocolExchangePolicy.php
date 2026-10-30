<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Separates durable HTTP accounting from method-specific MCP acceptance. */
final class McpProtocolExchangePolicy
{
    public static function assertRecordable(string $eventKind, ?int $httpStatus): void
    {
        if (($eventKind === 'dispatch_started' && $httpStatus === null)
            || ($eventKind === 'response_known' && $httpStatus !== null && $httpStatus >= 200 && $httpStatus <= 599)) return;
        throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request boundary is invalid', true);
    }
}
