<?php

declare(strict_types=1);

namespace app\Infrastructure\Support;

/**
 * Single probe for the Neuron AI SDK runtime.
 *
 * SandAI declares `neuron-core/neuron-ai` through the host composer manifest,
 * and SandPackage installs it *after* the host service is already running.
 * webman workers are forked from a long-lived master process, so a graceful
 * reload only replaces the workers; it never rebuilds the master's already
 * initialised `Composer\Autoload\ClassLoader`. When the dependency command
 * rewrites `vendor/composer/autoload_psr4.php` after the master started, every
 * forked worker keeps a loader whose `prefixDirsPsr4` lacks the `NeuronAI\`
 * mapping -- `class_exists()` then fails permanently (the failure is even
 * cached in the loader's `missingClasses` set) while
 * `InstalledVersions::isInstalled()` keeps reporting the package, because it
 * reads `installed.php` at call time rather than through the stale map.
 *
 * `available()` therefore does not trust `class_exists()` alone: when the
 * manifest reports the package as installed yet its classes cannot be found,
 * it re-declares the package's *own* `composer.json` autoload prefixes on a
 * dedicated ClassLoader so the installed SDK becomes loadable in the current
 * worker. The fallback is read-only, uses only paths shipped inside the
 * installed package, and is attempted at most once per process.
 */
final class NeuronRuntime
{
    public const PACKAGE = 'neuron-core/neuron-ai';
    public const MINIMUM_VERSION = '4.0.2';
    private const DEFAULT_MARKER_CLASS = 'NeuronAI\\Providers\\OpenAI\\OpenAI';

    private static bool $autoloadRegistrationAttempted = false;

    /**
     * Reports whether the installed Neuron SDK is usable in this process.
     * `$markerClass` lets callers pin the specific SDK class they rely on; it
     * defaults to the OpenAI provider class every Neuron transport needs.
     */
    public static function available(?string $markerClass = null): bool
    {
        if (!self::installedAtMinimumVersion()) {
            return false;
        }
        $marker = is_string($markerClass) && $markerClass !== ''
            ? $markerClass
            : self::DEFAULT_MARKER_CLASS;
        if (class_exists($marker)) {
            return true;
        }
        self::ensurePackageAutoloadRegistered();

        return class_exists($marker);
    }

    private static function installedAtMinimumVersion(): bool
    {
        return \Composer\InstalledVersions::isInstalled(self::PACKAGE)
            && version_compare(
                (string) \Composer\InstalledVersions::getVersion(self::PACKAGE),
                self::MINIMUM_VERSION,
                '>=',
            );
    }

    /**
     * Re-declares the installed package's declared PSR-4/PSR-0 prefixes when
     * the host loader predates the dependency install. The dedicated loader
     * only serves prefixes from the package's own manifest, so it can never
     * shadow unrelated host classes; loaders that already resolve the class
     * keep answering first only because their lookup never runs.
     */
    private static function ensurePackageAutoloadRegistered(): void
    {
        if (self::$autoloadRegistrationAttempted) {
            return;
        }
        self::$autoloadRegistrationAttempted = true;
        try {
            $installPath = \Composer\InstalledVersions::getInstallPath(self::PACKAGE);
            if (!is_string($installPath) || $installPath === '') {
                return;
            }
            $base = rtrim($installPath, '/\\');
            $manifest = json_decode(
                (string) @file_get_contents($base . DIRECTORY_SEPARATOR . 'composer.json'),
                true,
            );
            $autoload = is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
            $loader = new \Composer\Autoload\ClassLoader();
            $registered = false;
            $mappingsPsr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
            foreach ($mappingsPsr4 as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $path = realpath($base . DIRECTORY_SEPARATOR . (string) $directory);
                    if (is_string($path) && is_dir($path)) {
                        $loader->addPsr4((string) $prefix, $path);
                        $registered = true;
                    }
                }
            }
            $mappingsPsr0 = is_array($autoload['psr-0'] ?? null) ? $autoload['psr-0'] : [];
            foreach ($mappingsPsr0 as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $path = realpath($base . DIRECTORY_SEPARATOR . (string) $directory);
                    if (is_string($path) && is_dir($path)) {
                        $loader->addPsr0((string) $prefix, $path);
                        $registered = true;
                    }
                }
            }
            if ($registered) {
                $loader->register();
            }
        } catch (\Throwable) {
            // A best-effort fallback must never break the caller's own guard.
        }
    }
}
