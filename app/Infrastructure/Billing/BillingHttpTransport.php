<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

interface BillingHttpTransport
{
    /** @return array{status:int,body:string,organization?:string|null} */
    public function get(string $path, array $query, string $secret, ?string $organization = null): array;
}
