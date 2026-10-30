<?php

declare(strict_types=1);

namespace app\Application\Billing;

interface BillingReviewStore
{
    public function transaction(callable $callback): mixed;
    public function lockKey(int $adminId, int $lineId, string $key): void;
    /** @return array<string,mixed>|null */
    public function replay(int $adminId, int $lineId, string $key): ?array;
    /** @return array<string,mixed> */
    public function lockLine(int $id): array;
    /** @return array<string,mixed> */
    public function lockEvidence(int $id): array;
    /** @return array<string,mixed> */
    public function lockEvent(int $id, int $environmentId): array;
    public function hasFinalReview(int $lineId): bool;
    public function eventAllocated(int $eventId): bool;
    /** @param array<string,mixed> $data @param array<string,mixed> $result @param array<string,mixed>|null $event */
    public function insert(array $data, string $fingerprint, int $adminId, array $result, ?array $event): int;
    /** @return array<string,mixed> */
    public function read(int $id): array;
    /** @return list<array<string,mixed>> */
    public function index(int $lineId): array;
    /** @return array<string,mixed>|null */
    public function forEvent(int $eventId): ?array;
}
