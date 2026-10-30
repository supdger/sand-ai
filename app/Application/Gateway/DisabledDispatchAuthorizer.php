<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Application\Usage\UsageAttribution;

/** SandIAM revalidation adapter is deliberately not enabled in this slice. */
final class DisabledDispatchAuthorizer implements DispatchAuthorizer
{
    public function decide(InternalModelInvocationContext $context, UsageAttribution $attribution, Provider $provider, ModelDeployment $deployment, string $capabilityCode, ProviderDispatchContext $providerContext, array $routeSnapshot, ?int $priceRevisionId): DispatchAuthorizationDecision
    {
        return new DispatchAuthorizationDecision(false, DispatchAuthorizationDecision::contextDigest($context, $attribution, $capabilityCode, $providerContext, $routeSnapshot, $provider, $deployment, $priceRevisionId), 'disabled/v1', time() + 30);
    }
}
