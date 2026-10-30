<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Validates the immutable per-request MCP ledger before crash recovery seals Usage. */
final class McpProtocolDispatchEvidence
{
    /**
     * @param list<array{tool_run_id:int,sequence_no:int,request_kind:string,event_kind:string,http_status:?int}> $events
     * @return array{request_count:int,tools_call_dispatched:bool,tools_call_response_known:bool,tools_call_http_status:?int}
     */
    public static function analyze(int $runId, array $events): array
    {
        if ($runId < 1 || $events === []) self::reject();
        $requests = [];
        foreach ($events as $event) {
            $sequence = $event['sequence_no'] ?? 0;
            $kind = $event['request_kind'] ?? '';
            $eventKind = $event['event_kind'] ?? '';
            $status = $event['http_status'] ?? null;
            $expectedKind = [1 => 'initialize', 2 => 'initialized', 3 => 'tools_call'][$sequence] ?? null;
            if (($event['tool_run_id'] ?? 0) !== $runId || !is_int($sequence) || $expectedKind === null
                || !is_string($kind) || $kind !== $expectedKind
                || !is_string($eventKind) || !in_array($eventKind, ['dispatch_started', 'response_known'], true)
                || ($eventKind === 'dispatch_started' && $status !== null)
                || ($eventKind === 'response_known' && (!is_int($status) || !self::recordableResponseStatus($status)))) self::reject();
            if (isset($requests[$sequence][$eventKind])) self::reject();
            if (isset($requests[$sequence]['kind']) && $requests[$sequence]['kind'] !== $kind) self::reject();
            $requests[$sequence]['kind'] = $kind;
            $requests[$sequence][$eventKind] = $eventKind === 'response_known' ? $status : true;
        }
        ksort($requests, SORT_NUMERIC);
        $expected = 1;
        foreach ($requests as $sequence => $request) {
            if ($sequence !== $expected || !isset($request['dispatch_started'])) self::reject();
            if ($sequence < count($requests) && !array_key_exists('response_known', $request)) self::reject();
            $expected++;
        }
        if (isset($requests[2]) && (!isset($requests[1]['response_known']) || !self::success((int) $requests[1]['response_known']))) self::reject();
        if (isset($requests[3]) && (($requests[2]['response_known'] ?? null) !== 202)) self::reject();
        return [
            'request_count' => count($requests),
            'tools_call_dispatched' => isset($requests[3]['dispatch_started']),
            'tools_call_response_known' => array_key_exists('response_known', $requests[3] ?? []),
            'tools_call_http_status' => isset($requests[3]['response_known']) ? (int) $requests[3]['response_known'] : null,
        ];
    }

    public static function recordableResponseStatus(int $status): bool
    {
        return $status >= 200 && $status <= 599;
    }

    private static function success(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private static function reject(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request recovery evidence is incomplete', true);
    }
}
