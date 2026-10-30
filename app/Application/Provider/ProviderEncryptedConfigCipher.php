<?php

declare(strict_types=1);

namespace app\Application\Provider;

interface ProviderEncryptedConfigCipher
{
    /** @param array<string,mixed> $encryptedConfig @return array<string,mixed> */
    public function decrypt(array $encryptedConfig): array;

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function encrypt(array $config): array;
}
