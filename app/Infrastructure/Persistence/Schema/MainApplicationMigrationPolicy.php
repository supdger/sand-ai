<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Schema;

/** Database-independent constants used by the migration runner and tests. */
final class MainApplicationMigrationPolicy
{
    public const LEDGER_TABLE = 'sand_ai_schema_migration';
    public const LOCK_NAME = 'sand_ai_main_schema_migration_v1';
    public const TRANSACTION_MODE = 'per_file_transaction';

    public static function lockSql(): string
    {
        return "SELECT pg_advisory_lock(hashtext('" . self::LOCK_NAME . "'))";
    }

    public static function unlockSql(): string
    {
        return "SELECT pg_advisory_unlock(hashtext('" . self::LOCK_NAME . "'))";
    }

    public static function qualifiedTable(string $schema, string $table): string
    {
        return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new \RuntimeException('PostgreSQL schema identifier is invalid');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
