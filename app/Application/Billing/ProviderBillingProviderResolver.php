<?php

declare(strict_types=1);

namespace app\Application\Billing;

interface ProviderBillingProviderResolver
{
    /** @return array{id:int,status:int,code:string,adapter:string,base_url:?string}|null */
    public function find(int $id): ?array;
}
