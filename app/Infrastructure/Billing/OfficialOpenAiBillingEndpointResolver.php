<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

final class OfficialOpenAiBillingEndpointResolver implements OpenAiBillingEndpointResolver
{
    public function origin(): string
    {
        return 'https://api.openai.com';
    }
}
