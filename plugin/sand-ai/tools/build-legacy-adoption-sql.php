<?php

declare(strict_types=1);

/**
 * Print the archive-only migration, or generate the public 0.1.0 upgrade plan.
 * This tool never opens a database connection or executes lifecycle SQL.
 */

if ($argc !== 1 && in_array('--public010', $argv, true)) {
    require_once __DIR__ . '/public010-upgrade/Public010Upgrade.php';
    exit(SandAiPublic010Upgrade::run(array_slice($argv, 1)));
}

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php build-legacy-adoption-sql.php > archive.sql\n"
        . "       php build-legacy-adoption-sql.php --public010 [--stage=plan]\n"
        . "       php build-legacy-adoption-sql.php --public010 --stage=prepare|bridge --backup-manifest=<private JSON> --output=<new private file>\n");
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
