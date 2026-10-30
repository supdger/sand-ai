<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Schema;

use RuntimeException;

/**
 * Immutable, filesystem-only view of the main-application migration set.
 *
 * Keeping discovery and hashing outside the database runner lets status and
 * migration commands agree on the exact ordered input before a connection is
 * changed.
 */
final class MainApplicationMigrationPlan
{
    /** @var list<array{version:string,path:string,checksum:string,sql:string}> */
    private array $entries;

    /** @param list<array{version:string,path:string,checksum:string,sql:string}> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function fromDirectory(string $directory): self
    {
        $paths = glob(rtrim($directory, '/') . '/*.pgsql');
        if ($paths === false) {
            throw new RuntimeException('Unable to enumerate SandAI main application migrations');
        }
        sort($paths, SORT_STRING);

        $entries = [];
        foreach ($paths as $path) {
            $version = basename($path, '.pgsql');
            if (preg_match('/\A\d{12}_[a-z0-9_]+\z/', $version) !== 1) {
                throw new RuntimeException("SandAI migration filename is invalid: {$version}");
            }
            $sql = file_get_contents($path);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("Unable to read SandAI migration: {$path}");
            }
            if (preg_match('/^\s*(?:BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK)\s*;/mi', $sql) === 1) {
                throw new RuntimeException("SandAI migration manages its own transaction: {$version}");
            }
            $entries[] = [
                'version' => $version,
                'path' => $path,
                'checksum' => hash('sha256', $sql),
                'sql' => $sql,
            ];
        }

        if ($entries === []) {
            throw new RuntimeException('SandAI main application migration set is empty');
        }

        return new self($entries);
    }

    /** @return list<array{version:string,path:string,checksum:string,sql:string}> */
    public function entries(): array
    {
        return $this->entries;
    }
}
