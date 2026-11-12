<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 半成品知识库：无版本历史库照常出现在列表、可补资料、
 * 无版本/来源/开放/索引时可直接删除。源码级断言，不连数据库。
 *
 * Usage: php tests/sandai-050-fix6-versionless-knowledge-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Application/Knowledge/KnowledgeBaseGateway.php';

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

$source = @file_get_contents($root . '/app/Application/Knowledge/KnowledgeBaseGateway.php');
if ($source === false) {
    fwrite(STDERR, "MISSING KnowledgeBaseGateway.php\n");
    exit(1);
}

// 列表不丢下无版本库：版本联表必须是 LEFT JOIN 且有空值兜底

$indexMethod = '';
foreach (explode("\n", $source) as $index => $line) {
    if (str_contains($line, 'public function index(')) {
        $indexMethod = implode("\n", array_slice(explode("\n", $source), $index, 80));
        break;
    }
}
ok($indexMethod !== '', 'index method located');
ok(str_contains($indexMethod, 'LEFT JOIN'), 'index keeps bases without any version row');
ok(str_contains($indexMethod, "'unavailable'") || str_contains($indexMethod, '?? null'), 'index falls back for missing version fields');
ok(str_contains($indexMethod, 'version_id'), 'index still projects the active version when present');

// createDraft 不拿“已有版本”当硬门槛：唯一的状态闸门是 disabled

ok(str_contains($source, "(string) \$base->state === 'disabled'"), 'createDraft only rejects a disabled base');
ok(!str_contains($source, 'active_version_id') || !str_contains($source, "active_version_id === null"), 'createDraft never requires a prior active version');

// retire：先探版本数，无版本走简化分支，有版本照旧要求 disabled

$versionCountPos = strpos($source, "FROM sand_ai_knowledge_base_version WHERE knowledge_base_id=?");
$disabledPos = strpos($source, "'先停用知识库，再删除'");
ok($versionCountPos !== false && $disabledPos !== false && $versionCountPos < $disabledPos,
    'versionless check runs before the disabled gate');
ok(str_contains($source, "VALUES (?, ?, ?, ?, ?, NULL)"), 'versionless retire writes a NULL version ledger row');
ok(str_contains($source, "sand_ai_knowledge_publication WHERE knowledge_base_id=? AND state='published'"),
    'versionless retire still blocks open publications');
ok(substr_count($source, "'knowledge_base.retire'") >= 1, 'versionless retire keeps the audit event');
ok(str_contains($source, 'idempotent_replay'), 'retire keeps the replay envelope on both branches');

// 有版本的分支语义未变：仍要求 disabled + 无开放 + 无索引任务

ok(str_contains($source, "state'] !== 'disabled' || (int) \$rows[0]['status'] !== 2"), 'versioned retire still requires the disabled lifecycle');
ok(str_contains($source, 'sand_ai_knowledge_embedding_index'), 'versioned retire still checks embedding indexes');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
