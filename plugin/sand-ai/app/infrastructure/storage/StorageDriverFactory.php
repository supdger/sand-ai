<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\storage;

final class StorageDriverFactory
{
    /** @param array<string, mixed>|null $config */
    public static function create(?string $driver = null, ?array $config = null): StorageDriver
    {
        $config = $config ?? (array) config('plugin.sand-ai.storage', []);
        $driver = $driver ?? (string) ($config['default'] ?? 'local-private');
        $drivers = (array) ($config['drivers'] ?? []);

        return match ($driver) {
            'local-private' => new LocalPrivateStorageDriver(
                (string) (($drivers['local-private']['root'] ?? '') ?: runtime_path('sand-ai/private')),
            ),
            'oss-private' => new OssPrivateStorageDriver((array) ($drivers['oss-private'] ?? [])),
            default => throw new StorageException(sprintf('SandAI storage driver %s is not supported', $driver)),
        };
    }
}
