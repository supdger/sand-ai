<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Authorizes a client before returning environment model metadata. */
final class ModelCatalogAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    public function authorize(Request $request, IdentityContext $identity, string $requestId): void
    {
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, 'sand_ai.model.read', 'sand_iam.workload_client',
            $identity->workloadClientId, 'model-catalog:' . bin2hex(random_bytes(16)), $requestId,
        );
    }
}
