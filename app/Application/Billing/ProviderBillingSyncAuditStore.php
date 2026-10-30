<?php

declare(strict_types=1);

namespace app\Application\Billing;

interface ProviderBillingSyncAuditStore
{
    /** @param array<string,mixed> $request */
    public function recordNoData(int $adminId, array $request): void;
}
