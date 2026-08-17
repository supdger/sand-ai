<?php

declare(strict_types=1);

namespace plugin\SandAi\api;

use RuntimeException;
use support\Db;

/** SaiPackage lifecycle entry point for the independently installable package. */
final class Install
{
    private const CONNECTION = 'pgsql';

    public static function install(string $version): void
    {
        self::assertPostgreSql();
        self::importSql(__DIR__ . '/../install.sql');
    }

    public static function update(string $fromVersion, string $toVersion, mixed $context = null): void
    {
        self::assertPostgreSql();
        self::importSql(__DIR__ . '/../update.sql');
    }

    public static function uninstall(string $version): void
    {
        self::assertPostgreSql();
        self::importSql(__DIR__ . '/../uninstall.sql');
    }

    private static function assertPostgreSql(): void
    {
        $config = config('database.connections.' . self::CONNECTION, []);
        if (($config['type'] ?? '') !== 'pgsql') {
            throw new RuntimeException('SandAI full plugin requires the pgsql connection');
        }
    }

    private static function importSql(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("SandAI PostgreSQL schema file does not exist: {$file}");
        }

        foreach (explode(';', (string) file_get_contents($file)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                Db::connection(self::CONNECTION)->statement($statement);
            }
        }
    }
}
