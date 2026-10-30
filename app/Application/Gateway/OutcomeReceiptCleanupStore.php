<?php

declare(strict_types=1);

namespace app\Application\Gateway;

interface OutcomeReceiptCleanupStore
{
    /** @return list<array{id:int,environment_id:int,invocation_id:int,fence_version:int}> */
    public function claimExpired(string $now, string $terminalGraceBefore, string $staleBefore, string $owner, int $limit): array;

    public function destroyClaimedWithAudit(int $receiptId, string $owner, int $fenceVersion, string $destroyedAt): bool;
}
