<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Application\Usage\UsageAttribution;

/** Rechecks trusted worker dispatch immediately before a provider call. */
interface DispatchAuthorizer
{
    /** @param array<string,mixed> $routeSnapshot */
    public function decide(InternalModelInvocationContext $context, UsageAttribution $attribution, Provider $provider, ModelDeployment $deployment, string $capabilityCode, ProviderDispatchContext $providerContext, array $routeSnapshot, ?int $priceRevisionId): DispatchAuthorizationDecision;
}
