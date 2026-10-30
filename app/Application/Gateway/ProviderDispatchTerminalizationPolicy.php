<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** One provider-result classification shared by ChatGateway and its finalizers. */
final class ProviderDispatchTerminalizationPolicy
{
    public static function succeeded(): ProviderDispatchTerminalization
    {
        return new ProviderDispatchTerminalization('succeeded', 'settle', 'succeeded');
    }

    public static function failed(ApiProblem $problem, ProviderSendBoundary $boundary): ProviderDispatchTerminalization
    {
        return self::forFailure($problem, $boundary->started());
    }

    public static function forFailure(ApiProblem $problem, bool $providerDispatchStarted): ProviderDispatchTerminalization
    {
        if (!$providerDispatchStarted) {
            return new ProviderDispatchTerminalization('not_dispatched', 'release', 'failed');
        }

        // An HTTP rejection confirms a response, not a zero-charge receipt.
        // This failure seam carries no measured usage or billing evidence, so
        // every already-started failure keeps the original reservation held.
        // Measured outcomes settle through their separate accounting path.
        // Existing persisted terminal outcomes are replayed without reclassification.
        return new ProviderDispatchTerminalization('ambiguous', 'hold', 'failed');
    }
}
