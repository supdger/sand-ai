<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Application\Onboarding\OnboardingRequestSecrets;

/** Holds the original write-only request outside Webman's serializable body. */
final class FeishuCredentialRequestSecrets
{
    /** @var \WeakMap<object,array<string,mixed>>|null */
    private static ?\WeakMap $payloads = null;

    /** @param array<string,mixed> $payload */
    public static function store(object $request, array $payload): void
    {
        self::$payloads ??= new \WeakMap();
        self::$payloads[$request] = $payload;
    }

    /** @return array<string,mixed>|null */
    public static function take(object $request): ?array
    {
        return self::$payloads[$request] ?? null;
    }

    public static function clear(object $request): void
    {
        if (self::$payloads !== null) unset(self::$payloads[$request]);
    }

    /** @param array<string,mixed> $safe */
    public static function redact(object $request, array $safe): bool
    {
        return OnboardingRequestSecrets::replacePost($request, $safe)
            && OnboardingRequestSecrets::replaceRawBody($request, $safe);
    }

    public static function redactRawBody(object $request): void
    {
        OnboardingRequestSecrets::redactRawBody($request);
    }
}
