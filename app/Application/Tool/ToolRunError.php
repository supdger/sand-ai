<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

final class ToolRunError
{
    /** @return array{code:string,http_status:int,retryable:bool,public_detail:string} */
    public static function fromCode(string $code): array
    {
        $map = [
            'SAND_AI_BUDGET_UNPRICED' => [409, false, 'A matching budget blocks this external request because its price is not frozen'],
            'SAND_AI_BUDGET_SCOPE_UNVERIFIED' => [409, false, 'The trusted identity for budget checking is unavailable'],
            'SAND_AI_SERVICE_ACTION_FORBIDDEN' => [403, false, 'The caller is not granted this SandAI service action'],
            'SAND_AI_INVOCATION_SCOPE_FORBIDDEN' => [403, false, 'The external tool run is outside the workload scope'],
            'SAND_AI_INVOCATION_FACTS_UNVERIFIED' => [403, false, 'The external tool run cannot be verified'],
            'SAND_AI_DATA_CLASS_FORBIDDEN' => [403, false, 'The workload grant does not allow this data class'],
            'SAND_AI_CREDENTIAL_REVOKED' => [403, false, 'The workload credential is no longer valid'],
            'SAND_AI_IDENTITY_CONTEXT_EXPIRED' => [401, false, 'Workload identity context has expired'],
            'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE' => [503, true, 'SandIAM workload context is unavailable'],
            'SAND_AI_TOOL_RUN_IN_PROGRESS' => [409, true, 'External tool run is still in progress'],
            'SAND_AI_TOOL_RUN_STATE_INVALID' => [409, false, 'External tool run state is invalid'],
            'SAND_AI_TOOL_FINALIZATION_PENDING' => [503, true, 'External tool result is awaiting local recovery'],
            'SAND_AI_TOOL_DISPATCH_AMBIGUOUS' => [409, false, 'External tool dispatch result is uncertain and will not be retried automatically'],
            'SAND_AI_TOOL_TIMEOUT' => [504, false, 'External tool request timed out'],
            'SAND_AI_TOOL_RATE_LIMITED' => [429, false, 'External tool rejected the request due to its rate limit'],
            'SAND_AI_TOOL_UPSTREAM_UNAVAILABLE' => [502, false, 'External tool service is unavailable'],
            'SAND_AI_TOOL_UPSTREAM_REJECTED' => [502, false, 'External tool rejected the request'],
            'SAND_AI_TOOL_RESPONSE_TOO_LARGE' => [502, false, 'External tool response exceeded its published limit'],
            'SAND_AI_TOOL_RESPONSE_INVALID' => [502, false, 'External tool response was not valid standard JSON'],
            'SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID' => [422, false, 'External tool response did not match its published schema'],
            'SAND_AI_MCP_PROTOCOL_MISMATCH' => [502, false, 'MCP server did not complete the required stable protocol'],
            'SAND_AI_MCP_SESSION_INVALID' => [502, false, 'MCP server returned an invalid session identifier'],
            'SAND_AI_MCP_SSE_UNSUPPORTED' => [502, false, 'MCP server returned an unsupported streaming response'],
            'SAND_AI_MCP_RESPONSE_INVALID' => [502, false, 'MCP server returned an invalid protocol response'],
            'SAND_AI_MCP_DUPLICATE_JSON_MEMBER' => [502, false, 'MCP server returned duplicate JSON object members'],
            'SAND_AI_MCP_RESPONSE_LIMIT_EXCEEDED' => [502, false, 'MCP server response exceeded managed JSON safety limits'],
            'SAND_AI_MCP_SERVER_MESSAGE_REJECTED' => [502, false, 'MCP server sent an unsupported proactive message'],
            'SAND_AI_MCP_CURSOR_INVALID' => [502, false, 'MCP server pagination was invalid'],
            'SAND_AI_MCP_CATALOG_LIMIT_EXCEEDED' => [502, false, 'MCP server catalog exceeded managed safety limits'],
            'SAND_AI_MCP_SCHEMA_DRIFTED' => [409, false, 'MCP tool schema or description changed after publication'],
            'SAND_AI_MCP_REGISTRY_DRIFTED' => [409, false, 'Managed MCP read-only registration changed after publication'],
            'SAND_AI_MCP_STRUCTURED_RESULT_REQUIRED' => [422, false, 'MCP tool did not return the required structured result'],
            'SAND_AI_MCP_TOOL_NOT_DISPATCHED' => [502, false, 'MCP business tool was not dispatched before protocol recovery'],
            'SAND_AI_MCP_UPSTREAM_REJECTED' => [502, false, 'MCP server rejected the protocol request'],
        ];
        [$status, $retryable, $detail] = $map[$code] ?? [409, false, 'External tool run failed'];
        return ['code' => $code, 'http_status' => $status, 'retryable' => $retryable, 'public_detail' => $detail];
    }

    public static function problem(string $code): ApiProblem
    {
        $error = self::fromCode($code);
        return new ApiProblem($error['code'], $error['public_detail'], $error['retryable']);
    }

    /** @param array<string,mixed> $error */
    public static function persisted(array $error): ApiProblem
    {
        $keys = array_keys($error); sort($keys, SORT_STRING);
        if ($keys !== ['code', 'http_status', 'public_detail', 'retryable'] || !is_string($error['code'])
            || !is_int($error['http_status']) || !is_bool($error['retryable']) || !is_string($error['public_detail']) || $error['public_detail'] === '') {
            throw new ApiProblem('SAND_AI_TOOL_RUN_STATE_INVALID', 'Persisted external tool error is invalid');
        }
        return new ApiProblem($error['code'], $error['public_detail'], $error['retryable']);
    }
}
