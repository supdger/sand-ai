<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Pure decision for an expired dispatched run whose provider receipt is absent. */
final class ToolNoReceiptRecoveryPolicy
{
    /**
     * @param array{request_count:int,tools_call_dispatched:bool,tools_call_response_known:bool,tools_call_http_status:?int}|null $protocol
     * @return array{quantity:int,materialize_usage:bool,terminal_error_code:string}
     */
    public static function decide(string $adapter, ?int $sealedQuantity, ?array $protocol): array
    {
        if (!in_array($adapter, ['standard_readonly_http', 'mcp_streamable_http'], true)) self::pending();
        if ($adapter === 'standard_readonly_http') {
            if ($sealedQuantity !== 1 || $protocol !== null) self::pending();
            return ['quantity' => 1, 'materialize_usage' => false, 'terminal_error_code' => 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS'];
        }
        if ($protocol === null) self::pending();
        $quantity = $protocol['request_count'] ?? 0;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 3
            || !is_bool($protocol['tools_call_dispatched'] ?? null)
            || !is_bool($protocol['tools_call_response_known'] ?? null)
            || (!is_int($protocol['tools_call_http_status'] ?? null) && ($protocol['tools_call_http_status'] ?? null) !== null)
            || ($sealedQuantity !== null && $sealedQuantity !== $quantity)) self::pending();
        if (!$protocol['tools_call_dispatched']) {
            $code = 'SAND_AI_MCP_TOOL_NOT_DISPATCHED';
        } elseif ($protocol['tools_call_response_known'] && (int) $protocol['tools_call_http_status'] >= 300) {
            $code = 'SAND_AI_MCP_UPSTREAM_REJECTED';
        } else {
            $code = 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS';
        }
        return ['quantity' => $quantity, 'materialize_usage' => $sealedQuantity === null, 'terminal_error_code' => $code];
    }

    private static function pending(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request recovery evidence is incomplete', true);
    }
}
