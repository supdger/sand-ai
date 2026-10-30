<?php

declare(strict_types=1);

namespace app\Application\Provider;

interface ProviderEncryptedConfigStore
{
    public function transaction(callable $callback): mixed;

    /** @return array{id:int,status:int,code:string,adapter:string,encrypted_config:array<string,mixed>}|null */
    public function find(int $id): ?array;

    /** @return array{id:int,status:int,code:string,adapter:string,encrypted_config:array<string,mixed>}|null */
    public function lock(int $id): ?array;

    /** @param array<string,mixed> $encryptedConfig */
    public function update(int $id, array $encryptedConfig): void;
}
