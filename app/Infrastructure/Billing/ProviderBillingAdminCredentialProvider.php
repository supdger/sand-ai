<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingAdminCredentialProvider;
use app\Application\Billing\BillingAdminKeyPolicy;
use app\Application\Billing\BillingConfigCipher;
use app\Application\Billing\ProviderBillingCredentialStore;
use app\Application\Billing\ProviderBillingEligibilityPolicy;

/** Billing key is an explicitly named encrypted config value; inference api_key is never read here. */
final class ProviderBillingAdminCredentialProvider implements BillingAdminCredentialProvider
{
    private ProviderBillingEligibilityPolicy $eligibility;

    public function __construct(
        private ProviderBillingCredentialStore $store,
        private BillingConfigCipher $cipher,
        ?ProviderBillingEligibilityPolicy $eligibility = null,
        private ?BillingAdminKeyPolicy $keyPolicy = null,
    ) {
        $this->eligibility = $eligibility ?? new ProviderBillingEligibilityPolicy();
        $this->keyPolicy ??= new BillingAdminKeyPolicy();
    }

    public function secretFor(int $providerId, string $purpose): string
    {
        if ($purpose !== 'billing_admin') {
            throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'billing credential purpose invalid');
        }
        $provider = $this->store->find($providerId);
        $this->eligibility->assertIdentity($provider);
        $config = $this->cipher->decrypt($provider['encrypted_config']);
        $eligibleProvider = $provider;
        $eligibleProvider['base_url'] = $config['base_url'] ?? null;
        $this->eligibility->assertEligible($eligibleProvider);
        $key = $config['billing_admin_key'] ?? null;
        if ($key === null) {
            throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'dedicated billing_admin credential unavailable');
        }

        return $this->keyPolicy->normalize($key, 'SAND_AI_BILLING_CONFIG_INVALID');
    }

    public function organizationFor(int $providerId): string
    {
        $provider = $this->store->find($providerId);
        $this->eligibility->assertIdentity($provider);
        $config = $this->cipher->decrypt($provider['encrypted_config']);
        $organization = $config['billing_organization_id'] ?? null;
        if (!is_string($organization) || preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $organization) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'A billing organization is required');
        }
        return $organization;
    }
}
