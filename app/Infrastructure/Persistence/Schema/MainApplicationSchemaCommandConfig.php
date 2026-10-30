<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Schema;

use PDO;
use RuntimeException;

final class MainApplicationSchemaCommandConfig
{
    /** @param array<string, string|false> $environment @return array{driver:string,host:string,port:int,database:string,username:string,password:string} */
    public static function fromEnvironment(array $environment): array
    {
        $required = ['DB_TYPE', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'];
        foreach ($required as $name) {
            if (!isset($environment[$name]) || !is_string($environment[$name]) || $environment[$name] === '') {
                throw new RuntimeException("SandAI main schema requires explicit {$name}");
            }
        }

        if ($environment['DB_TYPE'] !== 'pgsql') {
            throw new RuntimeException('SandAI main schema requires DB_TYPE=pgsql');
        }
        self::assertSafeHost($environment['DB_HOST']);
        self::assertSafeDatabaseName($environment['DB_NAME']);
        self::assertSafeUser($environment['DB_USER']);
        if (preg_match('/\A[1-9][0-9]{0,4}\z/', $environment['DB_PORT']) !== 1) {
            throw new RuntimeException('SandAI main schema requires a valid DB_PORT');
        }
        $port = filter_var($environment['DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($port)) {
            throw new RuntimeException('SandAI main schema requires a valid DB_PORT');
        }
        if (($environment['SAND_AI_SCHEMA_CONFIRM_DATABASE'] ?? false) !== $environment['DB_NAME']) {
            throw new RuntimeException('SandAI main schema database confirmation does not match DB_NAME');
        }

        $password = $environment['DB_PASSWORD'] ?? false;
        if (!is_string($password)) {
            throw new RuntimeException('SandAI main schema requires explicit DB_PASSWORD or SAND_AI_SCHEMA_ALLOW_EMPTY_PASSWORD=1');
        }
        if ($password === '' && ($environment['SAND_AI_SCHEMA_ALLOW_EMPTY_PASSWORD'] ?? false) !== '1') {
            throw new RuntimeException('SandAI main schema empty DB_PASSWORD requires SAND_AI_SCHEMA_ALLOW_EMPTY_PASSWORD=1');
        }

        return [
            'driver' => $environment['DB_TYPE'],
            'host' => $environment['DB_HOST'],
            'port' => $port,
            'database' => $environment['DB_NAME'],
            'username' => $environment['DB_USER'],
            'password' => $password,
        ];
    }

    public static function assertCurrentDatabase(PDO $connection, string $expectedDatabase): void
    {
        $statement = $connection->query('SELECT current_database()');
        $actualDatabase = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($actualDatabase)) {
            throw new RuntimeException('SandAI main schema connection database does not match DB_NAME');
        }

        self::assertCurrentDatabaseName($actualDatabase, $expectedDatabase);
    }

    public static function assertCurrentDatabaseName(string $actualDatabase, string $expectedDatabase): void
    {
        if (!hash_equals($expectedDatabase, $actualDatabase)) {
            throw new RuntimeException('SandAI main schema connection database does not match DB_NAME');
        }
    }

    private static function assertSafeHost(string $host): void
    {
        if (strlen($host) > 255 || preg_match('/\A[^\s;=\x00-\x1F\x7F]+\z/u', $host) !== 1) {
            throw new RuntimeException('SandAI main schema DB_HOST contains unsafe DSN characters');
        }
    }

    private static function assertSafeDatabaseName(string $database): void
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]{0,62}\z/', $database) !== 1) {
            throw new RuntimeException('SandAI main schema DB_NAME is invalid');
        }
    }

    private static function assertSafeUser(string $username): void
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,62}\z/', $username) !== 1) {
            throw new RuntimeException('SandAI main schema DB_USER is invalid');
        }
    }
}
