<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Narrow persistence seam for API 1.47 not-dispatched evidence. */
interface PreDispatchRejectionPersistence
{
    /** @template T @param callable():T $write @return T */
    public function transaction(callable $write): mixed;

    public function lockRequest(string $requestId): void;

    /** @return array<string,mixed>|null */
    public function findRejection(string $trustedScope, string $requestId): ?array;

    /** @return array<string,mixed>|null */
    public function findInvocation(string $requestId): ?array;

    /** @param array<string,int|string|null> $row */
    public function createRejection(array $row): void;
}
