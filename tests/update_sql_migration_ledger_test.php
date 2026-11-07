<?php

declare(strict_types=1);

/**
 * Pin update.sql to the migration sources it claims to apply.
 *
 * install.sql is a generated artifact (tools/build-install-sql.php), but
 * update.sql cannot be: its first section is a hand-written adoption bridge for
 * the fixed published plugin-only 0.1.0 package (a frozen pg_temp reference
 * schema plus that package's ZIP digest), and its preflight carries
 * hand-authored object-existence guards that name individual migrations. Those
 * parts are reviewed risk logic, not a function of the migration directory.
 *
 * What is a function of the migration directory is the migration-fact surface:
 * the preflight expected-ledger lists, the per-migration upgrade blocks, the
 * embedded migration bodies, and the ledger rows they write. This test asserts
 * that surface equals plugin/sand-ai/migrations/main-app/*.pgsql and their
 * sha256 digests, so adding or editing a migration cannot silently skip
 * update.sql the way it would have before. It reads only committed files and
 * never touches a database.
 */

/**
 * Read a required file as a string.
 */
function sandAiUpdateRead(string $path): string
{
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('缺少校验目标文件：' . $path);
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('无法读取校验目标文件：' . $path);
    }

    return $contents;
}

/**
 * Map every migration version to its own sha256 digest, ascending by version.
 *
 * @return array<string, string>
 */
function sandAiUpdateMigrationDigests(string $root): array
{
    $directory = $root . '/plugin/sand-ai/migrations/main-app';
    $matches = glob($directory . '/*.pgsql');
    if ($matches === false || $matches === []) {
        throw new RuntimeException('迁移源目录为空：' . $directory);
    }
    sort($matches, SORT_STRING);
    $digests = [];
    foreach ($matches as $path) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('迁移源必须是普通文件：' . $path);
        }
        $version = basename($path, '.pgsql');
        if (preg_match('/\A[0-9]{12}_[a-z0-9_]+\z/D', $version) !== 1) {
            throw new RuntimeException('迁移文件名不符合 <12位时间戳>_<小写标识> 约定：' . $path);
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('无法计算迁移摘要：' . $path);
        }
        $digests[$version] = $digest;
    }

    return $digests;
}

/**
 * Extract the body a dollar-quoted tag encloses, starting at an offset.
 */
function sandAiUpdateQuoted(string $sql, string $tag, int $from, string $what): string
{
    $open = strpos($sql, $tag, $from);
    if ($open === false) {
        throw new RuntimeException('未找到起始标签 ' . $tag . '：' . $what);
    }
    $start = $open + strlen($tag);
    $close = strpos($sql, $tag, $start);
    if ($close === false) {
        throw new RuntimeException('美元引用未闭合 ' . $tag . '：' . $what);
    }

    return substr($sql, $start, $close - $start);
}

/**
 * Collect the preflight block body.
 */
function sandAiUpdatePreflight(string $sql): string
{
    return sandAiUpdateQuoted($sql, '$sand_ai_upgrade_preflight$', 0, 'preflight 预检段');
}

/**
 * Parse one `('<version>','<checksum>')` expected-ledger list.
 *
 * @return array<string, string> version to checksum, in declared order
 */
function sandAiUpdateExpectedList(string $segment): array
{
    $count = preg_match_all(
        "/\\('([0-9]{12}_[a-z0-9_]+)' *, *'([a-f0-9]{64})'\\)/",
        $segment,
        $found,
        PREG_SET_ORDER
    );
    if ($count === false || $count === 0) {
        throw new RuntimeException('预检期望账本清单为空');
    }
    $list = [];
    foreach ($found as $entry) {
        if (isset($list[$entry[1]])) {
            throw new RuntimeException('预检期望账本清单重复声明迁移：' . $entry[1]);
        }
        $list[$entry[1]] = $entry[2];
    }

    return $list;
}

/**
 * Compare a declared expected-ledger list with the migration digests.
 *
 * Both preflight lists feed set-valued predicates (a LEFT JOIN on version and
 * an EXISTS/NOT EXISTS pair), so only membership and checksum are semantic;
 * declaration order is not asserted here.
 *
 * @param array<string, string> $list
 * @param array<string, string> $digests
 * @return list<string> human-readable findings
 */
function sandAiUpdateListFindings(string $label, array $list, array $digests): array
{
    $findings = [];
    foreach ($list as $version => $checksum) {
        if (!isset($digests[$version])) {
            $findings[] = $label . ' 声明了不存在的迁移：' . $version;
            continue;
        }
        if ($digests[$version] !== $checksum) {
            $findings[] = $label . ' 校验和漂移：' . $version
                . ' 声明=' . $checksum . ' 实际=' . $digests[$version];
        }
    }

    return $findings;
}

/**
 * Sort declared versions so a list can be compared as a set.
 *
 * @param array<string, string> $list
 * @return list<string>
 */
function sandAiUpdateListVersions(array $list): array
{
    $versions = array_keys($list);
    sort($versions, SORT_STRING);

    return $versions;
}

/**
 * Parse every per-migration upgrade block.
 *
 * @return list<array{version: string, checksum: string, body: string}>
 */
function sandAiUpdateBlocks(string $sql): array
{
    $count = preg_match_all(
        '/DO \$(sand_ai_upgrade_[0-9a-z_]+)\$/',
        $sql,
        $found,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER
    );
    if ($count === false || $count === 0) {
        throw new RuntimeException('update.sql 未声明任何 DO $sand_ai_upgrade_*$ 升级段');
    }
    $blocks = [];
    foreach ($found as $entry) {
        $name = (string) $entry[1][0];
        if ($name === 'sand_ai_upgrade_preflight') {
            continue;
        }
        $tag = '$' . $name . '$';
        if (substr_count($sql, $tag) !== 2) {
            throw new RuntimeException('升级段标签必须全文出现两次（开闭各一次）：' . $tag);
        }
        $opened = (int) $entry[0][1] + strlen((string) $entry[0][0]);
        $closed = strpos($sql, $tag, $opened);
        if ($closed === false) {
            throw new RuntimeException('升级段未闭合：' . $tag);
        }
        $inner = substr($sql, $opened, $closed - $opened);
        if (preg_match("/WHERE version='([0-9]{12}_[a-z0-9_]+)'/", $inner, $guard) !== 1) {
            throw new RuntimeException('升级段缺少按 version 的账本守卫：' . $tag);
        }
        $version = $guard[1];
        $ledger = "/INSERT INTO sand_ai_schema_migration *\\(version, ?checksum, ?applied_time\\)"
            . " *VALUES ?\\('" . preg_quote($version, '/') . "', ?'([a-f0-9]{64})', ?CURRENT_TIMESTAMP\\(0\\)\\);/";
        if (preg_match($ledger, $inner, $row) !== 1) {
            throw new RuntimeException('升级段缺少与守卫同版本的账本写入：' . $version);
        }
        if (preg_match('/EXECUTE \$(sand_ai_migration_[0-9a-z_]+)\$/', $inner, $embed) !== 1) {
            throw new RuntimeException('升级段未以 EXECUTE $sand_ai_migration_*$ 原文嵌入迁移：' . $version);
        }
        $blocks[] = [
            'version' => $version,
            'checksum' => $row[1],
            'body' => sandAiUpdateQuoted($inner, '$' . $embed[1] . '$', 0, '升级段 ' . $version),
        ];
    }
    if ($blocks === []) {
        throw new RuntimeException('update.sql 未声明任何按迁移的升级段');
    }

    return $blocks;
}

$root = dirname(__DIR__);
$sql = sandAiUpdateRead($root . '/update.sql');
$digests = sandAiUpdateMigrationDigests($root);
$versions = array_keys($digests);
$baseline = $versions[0];
$findings = [];

if (!str_contains($sql, "\nBEGIN;\n") || !str_ends_with($sql, "\nCOMMIT;\n")) {
    $findings[] = 'update.sql 必须整体持有单一事务（BEGIN; ... COMMIT;）';
}
if (!str_contains($sql, "SELECT pg_advisory_xact_lock(hashtext('sand_ai_main_schema_migration_v1'));")) {
    $findings[] = 'update.sql 必须先取统一产物迁移咨询锁';
}

// The preflight must assert the baseline by its real digest: the baseline
// migration is the only source without an upgrade block, because an upgrade
// path presupposes it instead of applying it.
$preflight = sandAiUpdatePreflight($sql);
$guardCount = preg_match_all(
    "/version='([0-9]{12}_[a-z0-9_]+)'\\s+AND checksum='([a-f0-9]{64})'/",
    $preflight,
    $guards,
    PREG_SET_ORDER
);
if ($guardCount === false || $guardCount === 0) {
    $findings[] = '预检段未按 version+checksum 断言任何迁移';
}
$guarded = [];
foreach ($guards as $guard) {
    $guarded[$guard[1]] = true;
    if (($digests[$guard[1]] ?? null) !== $guard[2]) {
        $findings[] = '预检段对象守卫引用了漂移的迁移摘要：' . $guard[1]
            . ' 声明=' . $guard[2] . ' 实际=' . ($digests[$guard[1]] ?? '<无此迁移>');
    }
}
if (!isset($guarded[$baseline])) {
    $findings[] = '预检段未按 version+checksum 断言基线迁移：' . $baseline;
}

// Two expected-ledger lists: the first rejects any unknown or drifted applied
// row, the second rejects a hole below an already-applied later migration.
$segments = preg_split('/\) AS expected\(version, ?checksum\)/', $preflight);
if (!is_array($segments) || count($segments) !== 3) {
    throw new RuntimeException('预检段必须恰好声明两份 expected(version,checksum) 清单');
}
$mismatchList = sandAiUpdateExpectedList($segments[0]);
$gapList = sandAiUpdateExpectedList($segments[1]);

// The mismatch list is the authoritative shipped ledger: it must be the exact
// migration set, or an upgraded database would be accepted with an unknown or
// stale row.
foreach (sandAiUpdateListFindings('预检校验和清单', $mismatchList, $digests) as $finding) {
    $findings[] = $finding;
}
$missingFromMismatch = array_diff($versions, array_keys($mismatchList));
if ($missingFromMismatch !== []) {
    $findings[] = '预检校验和清单漏登记迁移：' . implode(', ', $missingFromMismatch);
}

// The gap list is the ledger an upgrade candidate must already carry, so it
// legitimately stops before this release's new migrations. It must still cover
// a contiguous prefix of the migration set with exact digests: a hole inside it
// would silently disable gap detection for everything below that hole.
foreach (sandAiUpdateListFindings('预检缺口清单', $gapList, $digests) as $finding) {
    $findings[] = $finding;
}
$gapCount = count($gapList);
$prefix = array_slice($versions, 0, $gapCount);
if (sandAiUpdateListVersions($gapList) !== $prefix) {
    $findings[] = '预检缺口清单不是迁移集合的连续前缀，缺口检测会对其下方迁移失效；'
        . '缺口清单外的迁移应恰好是本次发行新增的尾部';
}

// Every non-baseline migration needs exactly one upgrade block that embeds its
// source verbatim and registers its own digest.
$blocks = sandAiUpdateBlocks($sql);
$applied = [];
foreach ($blocks as $block) {
    $version = $block['version'];
    if (isset($applied[$version])) {
        $findings[] = '同一迁移被重复声明升级段：' . $version;
        continue;
    }
    $applied[$version] = true;
    if (!isset($digests[$version])) {
        $findings[] = '升级段引用了不存在的迁移：' . $version;
        continue;
    }
    if ($version === $baseline) {
        $findings[] = '基线迁移不得作为升级段应用，只能由预检断言：' . $version;
    }
    if ($digests[$version] !== $block['checksum']) {
        $findings[] = '升级段账本校验和漂移：' . $version
            . ' 声明=' . $block['checksum'] . ' 实际=' . $digests[$version];
    }
    $source = sandAiUpdateRead($root . '/plugin/sand-ai/migrations/main-app/' . $version . '.pgsql');
    if (trim($block['body']) !== trim($source)) {
        $findings[] = '升级段嵌入内容与迁移源不一致：' . $version;
    }
}
$appliedOrder = array_keys($applied);
$expectedOrder = array_values(array_diff($versions, [$baseline]));
if ($appliedOrder !== $expectedOrder) {
    $missing = array_diff($expectedOrder, $appliedOrder);
    if ($missing !== []) {
        $findings[] = 'update.sql 缺少迁移升级段：' . implode(', ', $missing);
    }
    foreach ($appliedOrder as $index => $version) {
        if (($expectedOrder[$index] ?? null) !== $version) {
            $findings[] = '升级段未按版本升序排列，第 ' . ($index + 1) . ' 段为 ' . $version
                . '，应为 ' . ($expectedOrder[$index] ?? '<已超出迁移集合>');
            break;
        }
    }
}

// Catch-all: no stale digest may survive anywhere in update.sql, including the
// hand-written guards this test does not otherwise model.
$pairCount = preg_match_all(
    "/'([0-9]{12}_[a-z0-9_]+)'[^;]{0,120}?'([a-f0-9]{64})'/s",
    $sql,
    $pairs,
    PREG_SET_ORDER
);
if ($pairCount === false) {
    throw new RuntimeException('无法扫描 update.sql 的版本与摘要字面量');
}
$stale = [];
foreach ($pairs as $pair) {
    if (($digests[$pair[1]] ?? null) !== $pair[2]) {
        $stale[$pair[1] . '=' . $pair[2]] = true;
    }
}
if ($stale !== []) {
    $findings[] = 'update.sql 残留与迁移源不符的版本/摘要字面量：' . implode(', ', array_keys($stale));
}

if ($findings !== []) {
    throw new RuntimeException("update.sql 与迁移源不一致：\n  - " . implode("\n  - ", $findings));
}

echo 'update-sql-migration-ledger migrations=' . count($digests)
    . ' blocks=' . count($blocks) . '/' . count($expectedOrder)
    . ' expected-list=' . count($mismatchList)
    . ' gap-list=' . $gapCount . '+' . (count($versions) - $gapCount) . '(new)'
    . ' guards=' . count($guarded) . PHP_EOL;
