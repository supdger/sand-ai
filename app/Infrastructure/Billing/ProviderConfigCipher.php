<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Application\Billing\BillingConfigCipher;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use app\Infrastructure\Provider\ProviderSecretEncryptor;

final class ProviderConfigCipher implements BillingConfigCipher
{
    public function decrypt(array $encryptedConfig): array
    {
        return (new ProviderSecretDecryptor())->decrypt($encryptedConfig);
    }

    public function encrypt(array $config): array
    {
        return (new ProviderSecretEncryptor())->encrypt($config);
    }
}
