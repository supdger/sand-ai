<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Application\Billing\ProviderBillingProviderResolver as ProviderBillingProviderResolverContract;
use app\Application\Billing\ProviderBillingEligibilityPolicy;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

final class ProviderBillingProviderResolver implements ProviderBillingProviderResolverContract
{
    private ProviderBillingEligibilityPolicy $eligibility;

    public function __construct(?ProviderBillingEligibilityPolicy $eligibility = null)
    {
        $this->eligibility = $eligibility ?? new ProviderBillingEligibilityPolicy();
    }

    public function find(int $id): ?array
    {
        $provider = Provider::where('id', $id)->find();
        if ($provider === null) {
            return null;
        }
        $metadata = [
            'id' => (int) $provider->id,
            'status' => (int) $provider->status,
            'code' => (string) $provider->code,
            'adapter' => (string) $provider->adapter,
        ];
        $this->eligibility->assertIdentity($metadata);
        $encryptedConfig = is_array($provider->encrypted_config) ? $provider->encrypted_config : [];
        $config = (new ProviderSecretDecryptor())->decrypt($encryptedConfig);

        return $metadata + [
            'base_url' => is_string($config['base_url'] ?? null) ? $config['base_url'] : null,
        ];
    }
}
