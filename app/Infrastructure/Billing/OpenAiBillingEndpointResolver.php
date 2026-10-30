<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

interface OpenAiBillingEndpointResolver
{
    public function origin(): string;
}
