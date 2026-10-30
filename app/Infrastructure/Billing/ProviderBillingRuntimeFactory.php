<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Application\Billing\ProviderBillingConnector;
use app\Application\Billing\ProviderBillingConnectorRegistry;

/** Production composition root; controlled acceptance may replace it before autoload. */
final class ProviderBillingRuntimeFactory
{
    public function connectorFor(int $providerId): ProviderBillingConnector
    {
        return (new ProviderBillingConnectorRegistry(
            new ProviderBillingProviderResolver(),
            static function (array $provider): ProviderBillingConnector {
                if ((new \app\Application\Billing\ProviderBillingEligibilityPolicy())->driver($provider) === 'openrouter_generation') {
                    return new OpenRouterGenerationConnector((int) $provider['id'], new OpenRouterBillingCurlTransport());
                }
                return new OpenAiOrganizationCostsConnector(
                    new ProviderBillingAdminCredentialProvider(new ProviderModelBillingCredentialStore(), new ProviderConfigCipher()),
                    new OpenAiBillingCurlTransport(),
                );
            },
        ))->resolve($providerId);
    }
}
