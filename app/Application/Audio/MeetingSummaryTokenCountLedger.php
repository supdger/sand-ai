<?php

declare(strict_types=1);

namespace app\Application\Audio;

/**
 * Required append-only external-call accounting boundary.
 * Implementations must bind trusted workload, environment, parent request/task,
 * frozen route and message digest; cache reuse must verify that same scope.
 * begin() commits a unique started external_request event with quantity "1",
 * pricing_state=unpriced and null money before returning its ID and request ID.
 * finish() appends an outcome; it must never update the started event. Unknown
 * transport outcomes remain ambiguous and cannot authorize automatic resend.
 * prompt_tokens measures capacity and must not become billed generation tokens.
 */
interface MeetingSummaryTokenCountLedger
{
    /** A verified successful receipt or null; an unknown started call must throw. */
    public function find(string $inputSha256): ?array;

    /** @param array<string,mixed> $facts @return array{started_event_id:int,request_id:string}|array{cached_receipt:array<string,mixed>} */
    public function begin(array $facts): array;

    /** @param array<string,mixed> $outcome */
    public function finish(int $startedEventId, array $outcome): void;

    /** Counts persisted starts for this task, including failed and unknown calls. */
    public function countRequests(): int;
}
