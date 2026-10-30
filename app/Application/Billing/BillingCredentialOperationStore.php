<?php

declare(strict_types=1);

namespace app\Application\Billing;

interface BillingCredentialOperationStore extends ProviderBillingCredentialStore
{
    public function lockOriginalSave(string $lock): void;

    /** @return array<string,mixed>|null */
    public function originalSave(int $adminId, int $providerId, string $lock): ?array;

    /** @param array<string,mixed> $context */
    public function createOriginalSave(int $adminId, int $providerId, array $context): void;
}
