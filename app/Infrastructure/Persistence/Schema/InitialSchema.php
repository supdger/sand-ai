<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Schema;

use PDO;
use RuntimeException;

/** Main-application schema entry point for the unified SandAI installation. */
final class InitialSchema
{
    public static function install(PDO $connection): void
    {
        self::migrate($connection);
    }

    /** Apply migrations owned by the SandAI main application to an existing schema. */
    public static function migrate(PDO $connection): void
    {
        MainApplicationMigrations::migrate($connection);
    }
}
