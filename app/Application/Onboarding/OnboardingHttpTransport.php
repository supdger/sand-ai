<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

interface OnboardingHttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, VerifiedEndpoint $endpoint, string $path, array $headers, ?array $json, int $timeoutMs): OnboardingHttpResponse;
}
