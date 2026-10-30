<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$install = file_get_contents($root . '/install.sql');
$uninstall = file_get_contents($root . '/uninstall.sql');
if (!is_string($install) || !is_string($uninstall)) throw new RuntimeException('Lifecycle SQL is missing');
$files = glob($root . '/plugin/sand-ai/migrations/main-app/*.pgsql');
if ($files === false || $files === []) throw new RuntimeException('Migration sources are missing');

preg_match_all('/^-- Migration ([a-z0-9_]+) \(sha256 ([a-f0-9]{64})\)$/m', $install, $matches);
$declared = array_combine($matches[1], $matches[2]);
if ($declared === false || count($declared) !== count($files)) throw new RuntimeException('Install migration count differs from source');
$checked = 0;
foreach ($files as $file) {
    $version = basename($file, '.pgsql');
    $checksum = hash_file('sha256', $file);
    if (($declared[$version] ?? null) !== $checksum
        || !str_contains($install, "VALUES ('$version', '$checksum', CURRENT_TIMESTAMP(0));")) {
        throw new RuntimeException("Missing or drifted install migration: $version");
    }
    if (strcmp($version, '202609240036_file_owner_scope') >= 0) {
        $start = "-- Migration $version (sha256 $checksum)\n";
        $end = "INSERT INTO sand_ai_schema_migration (version, checksum, applied_time) VALUES ('$version', '$checksum', CURRENT_TIMESTAMP(0));";
        $body = explode($end, explode($start, $install, 2)[1] ?? '', 2)[0];
        $source = file_get_contents($file);
        if (!is_string($source) || rtrim($body) !== rtrim($source)) {
            throw new RuntimeException("Install SQL body differs from source: $version");
        }
    }
    ++$checked;
}

preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(sand_ai_[a-z0-9_]+)/i', $install, $tableMatches);
preg_match_all("/'(sand_ai_[a-z0-9_]+)'/", $uninstall, $allowedMatches);
preg_match_all('/^\s+(sand_ai_[a-z0-9_]+)[,;]?\s*$/m', $uninstall, $droppedMatches);
$tables = array_unique($tableMatches[1]);
$missingAllow = array_diff($tables, $allowedMatches[1]);
$missingDrop = array_diff($tables, $droppedMatches[1]);
if ($missingAllow !== [] || $missingDrop !== []) {
    throw new RuntimeException('Uninstall omits installed tables: ' . implode(', ', array_unique([...$missingAllow, ...$missingDrop])));
}
echo "install-sql-migration-coverage $checked/$checked tables=" . count($tables) . PHP_EOL;
