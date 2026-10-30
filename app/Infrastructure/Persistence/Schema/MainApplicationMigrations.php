<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Schema;

use PDO;
use RuntimeException;

final class MainApplicationMigrations
{
    /** @return list<array{version:string,checksum:string,state:string}> */
    public static function status(PDO $connection, ?string $migrationDirectory = null): array
    {
        self::assertPostgres($connection);
        $plan = MainApplicationMigrationPlan::fromDirectory(self::migrationDirectory($migrationDirectory));
        $connection->exec(MainApplicationMigrationPolicy::lockSql());
        try {
            $schema = self::currentSchema($connection);
            $ledgerTable = self::ledgerTable($schema);
            if (!self::ledgerExists($connection, $ledgerTable)) {
                if (self::legacySandAiObjectsExist($connection, $schema)) {
                    throw new RuntimeException('Existing SandAI schema has no migration ledger; explicit adoption is required before migration');
                }
                self::assertNoGenericFunctionConflict($connection, $schema);

                return array_map(static fn (array $entry): array => [
                    'version' => $entry['version'],
                    'checksum' => $entry['checksum'],
                    'state' => 'pending',
                ], $plan->entries());
            }

            $applied = [];
            foreach ($connection->query('SELECT version, checksum FROM ' . $ledgerTable) as $row) {
                $applied[(string) $row['version']] = (string) $row['checksum'];
            }
            self::assertNoOrphanedLedgerVersions($applied, $plan->entries());

            return array_map(static function (array $entry) use ($applied): array {
                $actual = $applied[$entry['version']] ?? null;
                return [
                    'version' => $entry['version'],
                    'checksum' => $entry['checksum'],
                    'state' => $actual === null ? 'pending' : (hash_equals($entry['checksum'], $actual) ? 'applied' : 'checksum_mismatch'),
                ];
            }, $plan->entries());
        } finally {
            $connection->exec(MainApplicationMigrationPolicy::unlockSql());
        }
    }

    public static function migrate(PDO $connection, ?string $migrationDirectory = null): void
    {
        if ($connection->inTransaction()) {
            throw new RuntimeException('SandAI main application migrations require a non-transactional PDO connection');
        }
        self::assertPostgres($connection);
        $plan = MainApplicationMigrationPlan::fromDirectory(self::migrationDirectory($migrationDirectory));

        $connection->exec(MainApplicationMigrationPolicy::lockSql());
        try {
            $schema = self::currentSchema($connection);
            $ledgerTable = self::ledgerTable($schema);
            $ledgerExisted = self::ledgerExists($connection, $ledgerTable);
            if (!$ledgerExisted && self::legacySandAiObjectsExist($connection, $schema)) {
                throw new RuntimeException('Existing SandAI schema has no migration ledger; explicit adoption is required before migration');
            }
            if (!$ledgerExisted) {
                self::assertNoGenericFunctionConflict($connection, $schema);
            }
            self::createLedger($connection, $ledgerTable);

            $existingVersions = [];
            foreach ($connection->query('SELECT version, checksum FROM ' . $ledgerTable) as $row) {
                $existingVersions[(string) $row['version']] = (string) $row['checksum'];
            }
            self::assertNoOrphanedLedgerVersions($existingVersions, $plan->entries());

            foreach ($plan->entries() as $entry) {
                $statement = $connection->prepare('SELECT checksum FROM ' . $ledgerTable . ' WHERE version = :version');
                $statement->execute(['version' => $entry['version']]);
                $applied = $statement->fetchColumn();
                if ($applied !== false) {
                    if (!is_string($applied) || !hash_equals($entry['checksum'], $applied)) {
                        throw new RuntimeException("SandAI migration checksum mismatch: {$entry['version']}");
                    }
                    continue;
                }

                $connection->beginTransaction();
                try {
                    $connection->exec($entry['sql']);
                    $insert = $connection->prepare('INSERT INTO ' . $ledgerTable . ' (version, checksum, applied_time) VALUES (:version, :checksum, CURRENT_TIMESTAMP(0))');
                    $insert->execute(['version' => $entry['version'], 'checksum' => $entry['checksum']]);
                    $connection->commit();
                } catch (\Throwable $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    throw $exception;
                }
            }
        } finally {
            $connection->exec(MainApplicationMigrationPolicy::unlockSql());
        }
    }

    private static function assertPostgres(PDO $connection): void
    {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('SandAI main application migrations require PostgreSQL');
        }
    }

    private static function migrationDirectory(?string $migrationDirectory): string
    {
        if ($migrationDirectory !== null) {
            return $migrationDirectory;
        }

        $source = base_path('database/migrations');
        if (is_dir($source)) {
            return $source;
        }

        return base_path('plugin/sand-ai/migrations/main-app');
    }

    private static function currentSchema(PDO $connection): string
    {
        $statement = $connection->query('SELECT current_schema()');
        $schema = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            throw new RuntimeException('SandAI main application migrations require a current PostgreSQL schema');
        }

        return $schema;
    }

    private static function ledgerTable(string $schema): string
    {
        return MainApplicationMigrationPolicy::qualifiedTable($schema, MainApplicationMigrationPolicy::LEDGER_TABLE);
    }

    private static function ledgerExists(PDO $connection, string $ledgerTable): bool
    {
        $statement = $connection->prepare('SELECT to_regclass(:ledger_table) IS NOT NULL');
        $statement->execute(['ledger_table' => $ledgerTable]);
        return $statement !== false && filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL);
    }

    private static function legacySandAiObjectsExist(PDO $connection, string $schema): bool
    {
        $statement = $connection->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name LIKE 'sand_ai_%')");
        $statement->execute(['schema' => $schema]);
        return $statement !== false && filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL);
    }

    private static function assertNoGenericFunctionConflict(PDO $connection, string $schema): void
    {
        foreach (['sand_ai_provider_identity_binding_v153','sand_ai_provider_identity_immutable_v153'] as $routine) {
            $statement = $connection->prepare("SELECT to_regprocedure(format('%I.%I()', :schema, :routine)) IS NOT NULL");
            $statement->execute(['schema' => $schema, 'routine' => $routine]);
            if ($statement !== false && filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL)) {
                throw new RuntimeException('Existing SandAI provider identifier routine requires explicit migration ownership');
            }
        }
        $statement = $connection->prepare("SELECT to_regprocedure(format('%I.table_msg(text)', :schema)) IS NOT NULL");
        $statement->execute(['schema' => $schema]);
        if ($statement !== false && filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Existing table_msg(text) function conflicts with the SandAI baseline');
        }
    }

    private static function createLedger(PDO $connection, string $ledgerTable): void
    {
        $connection->exec('CREATE TABLE IF NOT EXISTS ' . $ledgerTable . ' (
            version varchar(128) PRIMARY KEY,
            checksum char(64) NOT NULL,
            applied_time timestamp(0) without time zone NOT NULL
        )');
    }

    /** @param array<string, string> $applied @param list<array{version:string}> $plan */
    private static function assertNoOrphanedLedgerVersions(array $applied, array $plan): void
    {
        $planned = array_fill_keys(array_column($plan, 'version'), true);
        foreach (array_keys($applied) as $version) {
            if (!isset($planned[$version])) {
                throw new RuntimeException("SandAI migration ledger contains an unknown or orphaned version: {$version}");
            }
        }
    }
}
