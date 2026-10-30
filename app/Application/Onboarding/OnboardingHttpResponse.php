<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

final class OnboardingHttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }
}
