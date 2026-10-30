<?php

declare(strict_types=1);

/**
 * Print the reviewed archive-only migration for the existing sandai database.
 * It does not connect to PostgreSQL or install the new SandAI schema.
 *
 * Usage: php plugin/sand-ai/tools/build-legacy-adoption-sql.php > archive.sql
 */

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php build-legacy-adoption-sql.php > archive.sql\n");
    exit(2);
}

$sql = file_get_contents(__DIR__ . '/archive-legacy-sandai.sql');
if ($sql === false || !str_contains($sql, "current_database() <> 'sandai'")
    || !str_contains($sql, 'CREATE SCHEMA sand_ai_legacy_20260925;')
    || !str_ends_with(trim($sql), 'COMMIT;')) {
    fwrite(STDERR, "The reviewed sandai archive SQL is unavailable or invalid.\n");
    exit(1);
}

fwrite(STDOUT, $sql);
