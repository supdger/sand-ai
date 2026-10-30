<?php

declare(strict_types=1);

namespace app\Infrastructure\Storage;

final class StorageDriverFactory
{
    public static function create(?string $driver = null): StorageDriver
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('sand_ai_storage', []);
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
