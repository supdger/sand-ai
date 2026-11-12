<?php

declare(strict_types=1);

/**
 * 停用知识库删除链路的静态契约校验，不需要数据库：指向被清理表的外键
 * 必须 CASCADE 或在对应 Gateway 里按依赖顺序显式删除；文件删除保留
 * SAND_AI_KNOWLEDGE_SOURCE_REFERENCED 与带知识库名的中文原因；前端为该码
 * 提供文案，未翻译码按系统故障带编号兜底。
 *
 * Usage: php tests/sandai-050-repair-knowledge-purge-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Knowledge/KnowledgeSourceReferenceGuard.php';

use app\Application\Knowledge\KnowledgeSourceReferenceGuard;

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

$root = dirname(__DIR__);

/** DELETE FROM 位置：表名后必须是非单词字符，避免 conversation 命中 conversation_turn。 */
function deletePos(string $source, string $table): int|false
{
    if (preg_match('/DELETE\s+FROM\s+' . $table . '\b/i', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return false;
    }
    return $match[0][1];
}

function repoFile(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException('无法读取仓库文件：' . $path);
    }
    return $contents;
}

/**
 * 收集所有指向目标表的外键：返回 [子表名 => ['cascade' => bool]]。
 * 同时覆盖 CREATE TABLE 内联 REFERENCES 和 ALTER TABLE … FOREIGN KEY 两种写法。
 *
 * @param list<string> $sqlTexts
 * @return array<string, array{cascade: bool}>
 */
function referencingTables(array $sqlTexts, string $parent): array
{
    $found = [];
    foreach ($sqlTexts as $sql) {
        if (preg_match_all(
            '/(CREATE TABLE|ALTER TABLE)\s+([a-z0-9_]+)[^;]*?REFERENCES\s+' . $parent . '\s*\(/is',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) < 1) {
            continue;
        }
        foreach ($matches[2] as $index => [$table, $offset]) {
            $statementStart = strrpos(substr($sql, 0, $offset), ';');
            $statement = substr($sql, $statementStart === false ? 0 : $statementStart + 1);
            $end = strpos($statement, ';');
            $statement = $end === false ? $statement : substr($statement, 0, $end);
            $cascade = stripos($statement, 'ON DELETE CASCADE') !== false;
            $found[$table] = ['cascade' => ($found[$table]['cascade'] ?? false) || $cascade];
        }
    }
    ksort($found);
    return $found;
}

$schemaTexts = [repoFile($root, 'install.sql'), repoFile($root, 'update.sql')];
foreach (glob($root . '/plugin/sand-ai/migrations/main-app/*.pgsql') ?: [] as $migration) {
    $schemaTexts[] = repoFile($root, 'plugin/sand-ai/migrations/main-app/' . basename($migration));
}

$gateway = repoFile($root, 'app/Application/Knowledge/KnowledgeEmbeddingIndexGateway.php');
$sourcePurge = repoFile($root, 'app/Application/Knowledge/KnowledgeSourcePurgeGateway.php');
$baseGateway = repoFile($root, 'app/Application/Knowledge/KnowledgeBaseGateway.php');
$fileGateway = repoFile($root, 'app/Application/File/FileGateway.php');
$guard = repoFile($root, 'app/Application/Knowledge/KnowledgeSourceReferenceGuard.php');

// 指向 sand_ai_knowledge_embedding_index 的外键必须全部有去处。
$indexRefs = referencingTables($schemaTexts, 'sand_ai_knowledge_embedding_index');
ok(isset($indexRefs['sand_ai_knowledge_embedding_index']) === false, 'no self reference on embedding_index');
$expected = ['sand_ai_knowledge_embedding', 'sand_ai_knowledge_embedding_active', 'sand_ai_knowledge_index_task'];
sort($expected);
ok(array_keys($indexRefs) === $expected, 'embedding_index referencers: ' . implode(',', array_keys($indexRefs)));
foreach ($indexRefs as $table => $info) {
    if ($info['cascade']) {
        ok(true, "{$table} cascades with embedding_index");
        continue;
    }
    $deletePos = deletePos($gateway, $table);
    ok($deletePos !== false, "{$table} deleted explicitly in KnowledgeEmbeddingIndexGateway");
}
$taskPos = deletePos($gateway, 'sand_ai_knowledge_index_task');
$activePos = deletePos($gateway, 'sand_ai_knowledge_embedding_active');
$indexPos = deletePos($gateway, 'sand_ai_knowledge_embedding_index');
ok($taskPos !== false && $activePos !== false && $indexPos !== false, 'vector purge deletes all three tables');
ok($taskPos !== false && $activePos !== false && $taskPos < $activePos, 'index_task deleted before embedding_active');
ok($activePos !== false && $indexPos !== false && $activePos < $indexPos, 'embedding_active deleted before embedding_index');

// 指向会话与来源表的外键覆盖（sources purge 删除的表）。
foreach (['sand_ai_knowledge_conversation_turn', 'sand_ai_knowledge_conversation', 'sand_ai_knowledge_base_source'] as $parent) {
    $refs = referencingTables($schemaTexts, $parent);
    foreach ($refs as $table => $info) {
        if ($info['cascade']) {
            ok(true, "{$table} cascades with {$parent}");
            continue;
        }
        ok(deletePos($sourcePurge, $table) !== false, "{$table} deleted explicitly before {$parent}");
    }
}
$turnPos = deletePos($sourcePurge, 'sand_ai_knowledge_conversation_turn');
$conversationPos = deletePos($sourcePurge, 'sand_ai_knowledge_conversation');
$sourcePos = deletePos($sourcePurge, 'sand_ai_knowledge_base_source');
ok($turnPos !== false && $conversationPos !== false && $turnPos < $conversationPos, 'turns deleted before conversations');
ok($conversationPos !== false && $sourcePos !== false && $conversationPos < $sourcePos, 'conversations deleted before source references');

// 三个删除入口都要有 PDO 兜底，不让原始数据库错误冒成 Server internal error。
foreach ([
    'KnowledgeEmbeddingIndexGateway' => $gateway,
    'KnowledgeSourcePurgeGateway' => $sourcePurge,
    'KnowledgeBaseGateway' => $baseGateway,
] as $name => $source) {
    ok(strpos($source, 'PDOException') !== false, "{$name} wraps database failures");
}

// 文件删除保留真实原因：不再改写成 SAND_AI_RESOURCE_CONFLICT。
ok(strpos($fileGateway, "SAND_AI_KNOWLEDGE_SOURCE_REFERENCED") === false
    || strpos($fileGateway, "catch (ApiProblem") === false,
    'FileGateway keeps the reference rejection code');
ok(strpos($fileGateway, 'File is referenced by a published knowledge base') === false,
    'FileGateway no longer relabels the reference rejection');
ok(strpos($guard, "SAND_AI_KNOWLEDGE_SOURCE_REFERENCED") !== false,
    'guard still reports the dedicated error code');

$message = KnowledgeSourceReferenceGuard::describeReference(['产品问答库', '客服知识库']);
ok($message === '这份资料正被已发布的知识库「产品问答库、客服知识库」使用，先从知识库里移除再删除',
    'rejection names the published bases joined by 顿号');
ok(KnowledgeSourceReferenceGuard::describeReference(['「坏名字」']) === '这份资料正被已发布的知识库「坏名字」使用，先从知识库里移除再删除',
    'embedded corner brackets in names are stripped');

// 前端：真实原因可显示，未翻译码按系统故障带编号。
$runtimeErrors = repoFile($root, 'sandadmin-artd/src/views/plugin/sand-ai/api/runtimeErrors.ts');
$errorText = repoFile($root, 'sandadmin-artd/src/views/plugin/sand-ai/api/errorText.ts');
ok(strpos($runtimeErrors, 'SAND_AI_KNOWLEDGE_SOURCE_REFERENCED') !== false,
    'runtimeErrors maps the reference rejection code');
ok(strpos($runtimeErrors, '错误编号') !== false && strpos($runtimeErrors, 'untranslatedErrorText') !== false,
    'runtimeErrors shows untranslated codes as system fault with code');
ok(strpos($errorText, 'untranslatedErrorText') !== false,
    'errorText falls back to system fault with code');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
