<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final class DispatchFailurePolicy
{
    public static function ledgerState(ApiProblem $problem, bool $providerDispatchStarted = false): string
    {
        return ProviderDispatchTerminalizationPolicy::forFailure($problem, $providerDispatchStarted)->ledgerState;
    }

    public static function releaseBudget(ApiProblem $problem, bool $providerDispatchStarted = false): bool
    {
        return ProviderDispatchTerminalizationPolicy::forFailure($problem, $providerDispatchStarted)->releasesBudget();
    }
}
