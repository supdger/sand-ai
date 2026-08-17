<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\identity;

/**
 * Resolves co-installed SandIAM runtime ports by class name only.
 *
 * The package must not import SandIAM models or query host IAM tables.
 * Missing classes keep the caller on the fail-closed identity path.
 */
final class HostSandIamRuntime
{
    public static function identityContextProvider(): ?object
    {
        return self::instance('plugin\\SandIam\\app\\runtime\\IdentityContextProvider');
    }

    public static function environmentReferenceVerifier(): ?object
    {
        return self::instance('plugin\\SandIam\\app\\runtime\\EnvironmentReferenceVerifier');
    }

    private static function instance(string $className): ?object
    {
        if (!class_exists($className)) {
            return null;
        }

        $instance = new $className();

        return is_object($instance) ? $instance : null;
    }
}
