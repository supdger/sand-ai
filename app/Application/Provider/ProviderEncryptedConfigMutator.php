<?php

declare(strict_types=1);

namespace app\Application\Provider;

final class ProviderEncryptedConfigMutator
{
    public function __construct(
        private ProviderEncryptedConfigStore $store,
        private ProviderEncryptedConfigCipher $cipher,
    ) {
    }

    /**
     * @param array<string,mixed> $changes
     * @param callable(array{id:int,status:int,code:string,adapter:string,encrypted_config:array<string,mixed>}|null):void $assertProvider
     */
    public function merge(int $providerId, array $changes, callable $assertProvider): void
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('provider encrypted config changes cannot be empty');
        }
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('provider encrypted config keys must be non-empty strings');
            }
        }

        $this->store->transaction(function () use ($providerId, $changes, $assertProvider): void {
            $provider = $this->store->lock($providerId);
            $assertProvider($provider);
            if ($provider === null) {
                throw new \LogicException('provider guard accepted a missing provider');
            }

            $config = $this->cipher->decrypt($provider['encrypted_config']);
            foreach ($changes as $key => $value) {
                $config[$key] = $value;
            }
            $this->store->update($providerId, $this->cipher->encrypt($config));
        });
    }
}
