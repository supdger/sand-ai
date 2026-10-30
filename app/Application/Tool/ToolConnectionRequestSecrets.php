<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Application\Onboarding\OnboardingRequestSecrets;

/** Keeps the original connection request outside Webman's serializable body. */
final class ToolConnectionRequestSecrets
{
    /** @var \WeakMap<object, array<string, mixed>>|null */
    private static ?\WeakMap $payloads = null;

    /** @param array<string, mixed> $payload */
    public static function store(object $request, array $payload): void
    {
        self::$payloads ??= new \WeakMap();
        self::$payloads[$request] = $payload;
    }

    /** @return array<string, mixed>|null */
    public static function take(object $request): ?array
    {
        return self::$payloads[$request] ?? null;
    }

    public static function clear(object $request): void
    {
        if (self::$payloads !== null) {
            unset(self::$payloads[$request]);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function redactRequest(object $request, array $payload): bool
    {
        return OnboardingRequestSecrets::replacePost($request, $payload)
            && OnboardingRequestSecrets::replaceRawBody($request, $payload);
    }

    public static function redactRawBody(object $request): bool
    {
        return OnboardingRequestSecrets::redactRawBody($request);
    }
}
