<?php

declare(strict_types=1);

namespace app\Application\Billing;

final class ProviderBillingConnectorRegistry
{
    public function __construct(
        private ProviderBillingProviderResolver $resolver,
        private $factory,
        private ?ProviderBillingEligibilityPolicy $eligibility = null,
    ) {
        $this->eligibility ??= new ProviderBillingEligibilityPolicy();
    }

    public function resolve(int $id): mixed
    {
        $provider = $this->resolver->find($id);
        $this->eligibility->assertEligible($provider);

        return ($this->factory)($provider);
    }
}
