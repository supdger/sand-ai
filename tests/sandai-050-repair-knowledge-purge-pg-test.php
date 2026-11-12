<?php

declare(strict_types=1);

/**
 * 向量清理的真实 PostgreSQL 校验：临时 schema 里按真实外键建表播种
 * （停用库→版本→索引任务→embedding_index→active 投影→向量明细），原样执行
 * purgeIndexRows 的三条 DELETE，验证删除顺序成立且子表清空。
 * 环境变量：SAND_AI_TEST_PG_DSN / _USER / _PASSWORD，缺省时跳过不失败。
 *
 * Usage: php tests/sandai-050-repair-knowledge-purge-pg-test.php
 */

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

$dsn = getenv('SAND_AI_TEST_PG_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "SKIP SAND_AI_TEST_PG_DSN not set\n";
    echo "passed=0 failed=0 skipped=1\n";
    exit(0);
}

$root = dirname(__DIR__);
$gatewaySource = file_get_contents($root . '/app/Application/Knowledge/KnowledgeEmbeddingIndexGateway.php');
if (!is_string($gatewaySource)) {
    fwrite(STDERR, "FAIL cannot read gateway source\n");
    exit(1);
}

/** 从 purgeIndexRows 源码里原样取出单引号 DELETE，保持与实现一致。 */
function purgeStatements(string $source): array
{
    $bodyStart = strpos($source, 'function purgeIndexRows');
    if ($bodyStart === false) {
        throw new RuntimeException('purgeIndexRows not found in gateway');
    }
    $body = substr($source, $bodyStart);
    $nextFunction = strpos($body, "\n    private function", 20);
    if ($nextFunction === false) {
        $nextFunction = strpos($body, "\n    public function", 20);
    }
    if ($nextFunction !== false) {
        $body = substr($body, 0, $nextFunction);
    }
    if (preg_match_all("/execute\(\s*'([^']+)'/s", $body, $matches) < 1) {
        throw new RuntimeException('no DELETE statements found in purgeIndexRows');
    }
    $deletes = array_values(array_filter(
        $matches[1],
        static fn (string $sql): bool => preg_match('/^\s*DELETE\s+FROM/i', $sql) === 1,
    ));
    if (count($deletes) !== 3) {
        throw new RuntimeException('expected exactly three DELETE statements in purgeIndexRows');
    }
    return $deletes;
}

$statements = purgeStatements($gatewaySource);
ok(preg_match('/DELETE FROM sand_ai_knowledge_index_task/', $statements[0]) === 1, 'first delete is index_task');
ok(preg_match('/DELETE FROM sand_ai_knowledge_embedding_active/', $statements[1]) === 1, 'second delete is embedding_active');
ok(preg_match('/DELETE FROM sand_ai_knowledge_embedding_index/', $statements[2]) === 1, 'third delete is embedding_index');

$pdo = new PDO(
    $dsn,
    (string) getenv('SAND_AI_TEST_PG_USER'),
    (string) getenv('SAND_AI_TEST_PG_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$schema = 'purge_test_' . getmypid();

try {
    $pdo->exec("CREATE SCHEMA {$schema}");
    $pdo->exec("SET search_path TO {$schema}");

    // 与生产表同构的外键关系，列裁剪到测试所需。
    $pdo->exec(
        "CREATE TABLE sand_ai_knowledge_base (
            id bigint PRIMARY KEY,
            environment_id bigint NOT NULL,
            name varchar(64) NOT NULL,
            state varchar(16) NOT NULL,
            status integer NOT NULL,
            delete_time timestamp(0) NULL
        );
        CREATE TABLE sand_ai_knowledge_base_version (
            id bigint PRIMARY KEY,
            knowledge_base_id bigint NOT NULL REFERENCES sand_ai_knowledge_base(id),
            version_no integer NOT NULL,
            state varchar(16) NOT NULL
        );
        CREATE TABLE sand_ai_knowledge_embedding_index (
            id bigint PRIMARY KEY,
            environment_id bigint NOT NULL,
            knowledge_base_version_id bigint NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
            state varchar(16) NOT NULL
        );
        CREATE TABLE sand_ai_knowledge_embedding (
            id bigint PRIMARY KEY,
            index_id bigint NOT NULL REFERENCES sand_ai_knowledge_embedding_index(id) ON DELETE CASCADE,
            chunk_no integer NOT NULL
        );
        CREATE TABLE sand_ai_knowledge_embedding_active (
            index_id bigint PRIMARY KEY REFERENCES sand_ai_knowledge_embedding_index(id)
        );
        CREATE TABLE sand_ai_knowledge_index_task (
            id bigint PRIMARY KEY,
            environment_id bigint NOT NULL,
            knowledge_base_id bigint NOT NULL,
            knowledge_base_version_id bigint NOT NULL REFERENCES sand_ai_knowledge_base_version(id),
            index_id bigint NULL REFERENCES sand_ai_knowledge_embedding_index(id),
            state varchar(16) NOT NULL DEFAULT 'queued'
        );",
    );

    // 一个已停用知识库：两个版本（已发布+草稿），已发布版本带索引任务、
    // 索引、active 投影和向量明细；草稿版本还有一个还没建索引的排队任务。
    $pdo->exec(
        "INSERT INTO sand_ai_knowledge_base (id,environment_id,name,state,status) VALUES (1,7,'停用库','disabled',2);
        INSERT INTO sand_ai_knowledge_base_version (id,knowledge_base_id,version_no,state) VALUES
            (11,1,1,'published'),(12,1,2,'draft');
        INSERT INTO sand_ai_knowledge_embedding_index (id,environment_id,knowledge_base_version_id,state)
            VALUES (21,7,11,'ready');
        INSERT INTO sand_ai_knowledge_embedding (id,index_id,chunk_no) VALUES (31,21,1),(32,21,2);
        INSERT INTO sand_ai_knowledge_embedding_active (index_id) VALUES (21);
        INSERT INTO sand_ai_knowledge_index_task
            (id,environment_id,knowledge_base_id,knowledge_base_version_id,index_id,state) VALUES
            (41,7,1,11,21,'ready'),(42,7,1,12,NULL,'queued');",
    );

    $pdo->beginTransaction();
    foreach ($statements as $sql) {
        $pdo->prepare($sql)->execute([1]);
    }
    $pdo->commit();

    $count = static function (string $table) use ($pdo): int {
        return (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
    };
    ok($count('sand_ai_knowledge_index_task') === 0, 'index_task rows removed');
    ok($count('sand_ai_knowledge_embedding_active') === 0, 'embedding_active rows removed');
    ok($count('sand_ai_knowledge_embedding_index') === 0, 'embedding_index rows removed');
    ok($count('sand_ai_knowledge_embedding') === 0, 'embedding rows removed via cascade');
    ok($count('sand_ai_knowledge_base') === 1, 'knowledge base row kept');
    ok($count('sand_ai_knowledge_base_version') === 2, 'version rows kept');

    // 反向校验：顺序确实必要——只删 embedding_index 会先撞外键。
    $pdo->exec("INSERT INTO sand_ai_knowledge_embedding_index (id,environment_id,knowledge_base_version_id,state)
        VALUES (22,7,11,'ready');
        INSERT INTO sand_ai_knowledge_index_task
            (id,environment_id,knowledge_base_id,knowledge_base_version_id,index_id,state)
            VALUES (43,7,1,11,22,'building');");
    $violated = false;
    try {
        $pdo->exec("DELETE FROM sand_ai_knowledge_embedding_index WHERE id=22");
    } catch (PDOException $ignored) {
        $violated = true;
    }
    ok($violated, 'deleting index alone still violates index_task FK');
} finally {
    $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
}

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
