<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final readonly class ProviderDispatchContext
{
    public function __construct(public string $logicalExecutionId, public string $bodyDigest, public ?string $providerKey = null)
    {
        if (preg_match('/\A[a-f0-9]{48}\z/', $logicalExecutionId) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $bodyDigest) !== 1
            || ($providerKey !== null && preg_match('/\A[a-f0-9]{64}\z/', $providerKey) !== 1)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Provider dispatch context is invalid');
        }
    }
}
