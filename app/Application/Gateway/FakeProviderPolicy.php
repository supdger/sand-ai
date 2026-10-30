<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final class FakeProviderPolicy
{
    public static function assertExecutable(bool $debug, bool $explicitlyAllowed): void
    {
        if (!$debug || !$explicitlyAllowed) {
            throw new ApiProblem('SAND_AI_FAKE_PROVIDER_FORBIDDEN', 'Fake provider is disabled outside development');
        }
    }
}
