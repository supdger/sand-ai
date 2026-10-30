<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Application\Provider\ProviderEncryptedConfigStore;

interface ProviderBillingCredentialStore extends ProviderEncryptedConfigStore
{
    public function createAudit(int $adminId, int $providerId): void;
}
