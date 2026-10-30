<?php

declare(strict_types=1);

namespace app\Application\Billing;

/** Transactional persistence boundary; also makes concurrency semantics executable in tests. */
interface BillingImportStore
{
    public const MAX_CANDIDATES_PER_LINE = 20;
    public const MAX_CANDIDATES_PER_BATCH = 1000;
    public function transaction(callable $callback): mixed;
    public function lockIdempotency(int $providerId, string $idempotencyKey): void;
    public function findImport(int $providerId, string $idempotencyKey): ?array;
    public function providerExists(int $providerId): bool;
    /** @return array{id:int,created:bool,fingerprint:string} Atomic INSERT ... ON CONFLICT claim result. */
    public function claimImport(array $data, string $fingerprint): array;
    /** @return list<int> Strict tuple candidates, ordered by id. */
    public function candidateIds(int $providerId, array $line): array;
    public function lockUsageEvent(int $usageEventId): void;
    /** @return array{id:int,meter_code:string,quantity:?string,amount:?string}|null */
    public function availableCandidate(int $usageEventId, int $providerId, array $line): ?array;
    /** @return array{id:int,created:bool} */
    public function createLine(int $billingImportId, array $line): array;
    public function createReconciliation(int $billingLineId, array $match, array $line): void;
    public function createAudit(int $adminId, int $billingImportId, int $lineCount, BillingImportAuditContext $audit): void;
    /** @return array<string,mixed> */
    public function read(int $id): array;
    /** @return list<array<string,mixed>> */
    public function index(?int $providerId): array;
}
