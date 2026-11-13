<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 一步建库：单事务完成 base/version/source/publication/index
 * task/操作账本/审计，任一失败整体回滚。源码级与策略级断言，不连数据库。
 *
 * Usage: php tests/sandai-050-fix6-knowledge-create-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Api/Support/ApiProblem.php';
require_once $root . '/app/Application/Knowledge/KnowledgeBasePolicy.php';
require_once $root . '/app/Application/Knowledge/KnowledgeBaseGateway.php';

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeBaseGateway;
use app\Application\Knowledge\KnowledgeBasePolicy;

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

function read(string $path): string
{
    $text = @file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "MISSING {$path}\n");
        return '';
    }
    return $text;
}

// create() 存在且签名符合契约

$method = new ReflectionMethod(KnowledgeBaseGateway::class, 'create');
$params = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());
ok($params === ['environmentId', 'input', 'idempotencyKey', 'actorRef'], 'create signature mirrors createDraft');

$source = read($root . '/app/Application/Knowledge/KnowledgeBaseGateway.php');
$createSource = '';
foreach (explode("\n", $source) as $index => $line) {
    if (str_contains($line, 'public function create(int $environmentId')) {
        $createSource = implode("\n", array_slice(explode("\n", $source), $index, 90));
        break;
    }
}
ok($createSource !== '', 'create method source located');
ok(str_contains($createSource, 'Db::connect(\'pgsql\')->transaction'), 'create runs inside one PostgreSQL transaction');
ok(str_contains($createSource, "'kb-' . bin2hex(random_bytes(8))"), 'blank code gets a kb- server identifier');
ok(str_contains($createSource, 'normalizeDraft'), 'create reuses the draft-aligned input contract');
ok(str_contains($createSource, 'freezeSources'), 'create freezes sources in the same transaction');
ok(str_contains($createSource, 'IndexTaskGateway') && str_contains($createSource, '->ensure('), 'create ensures the index task inside the transaction');
ok(substr_count($createSource, "'create_draft'") >= 1 && substr_count($createSource, "'publish'") >= 1, 'create writes both ledger rows for replay parity');
ok(str_contains($createSource, 'pg_advisory_xact_lock'), 'create serializes on the idempotency key and code');
ok(str_contains($createSource, 'knowledge_base.draft') && str_contains($createSource, 'knowledge_base.publish'), 'create audits both steps');

// 空 files 被契约拒绝

$rejected = false;
try {
    KnowledgeBasePolicy::normalizeDraft(['code' => 'kb-x', 'name' => 'x', 'files' => []]);
} catch (ApiProblem $problem) {
    $rejected = $problem->getMessage() !== '';
}
ok($rejected, 'empty files rejected by the shared draft contract');

// 文件形态 {id, sha256}

$badFiles = false;
try {
    KnowledgeBasePolicy::normalizeDraft(['code' => 'kb-x', 'name' => 'x', 'files' => [['id' => 'x', 'sha256' => str_repeat('0', 64)]]]);
} catch (ApiProblem) {
    $badFiles = true;
}
ok($badFiles, 'non-integer file id rejected');
$good = KnowledgeBasePolicy::normalizeDraft(['code' => 'kb-x', 'name' => 'x', 'files' => [['id' => 3, 'sha256' => str_repeat('a', 64)]]]);
ok($good['files'] === [['id' => 3, 'sha256' => str_repeat('a', 64)]], 'valid file rows normalize intact');

// 一步建库必须校验文件归属与解析状态（freezeSources 内部断言存在）

ok(str_contains($source, 'KnowledgeSourceReferenceGuard'), 'source freezing still runs the reference guard');
ok(str_contains($source, 'KnowledgeSourceAttribution::fromFile'), 'source freezing still proves file ownership attribution');
ok(str_contains($source, "'parsed'"), 'only parsed files can be frozen');

// 控制器与路由

$controller = read($root . '/plugin/sand-ai/app/admin/controller/KnowledgeBaseController.php');
ok(str_contains($controller, 'public function create(Request $request)'), 'admin controller exposes create');
ok(str_contains($controller, "Permission('SandAI 一步创建知识库', 'sand_ai:knowledge:create')"), 'create reuses the knowledge create permission');
ok(str_contains($controller, 'KnowledgeBaseGateway())->create('), 'controller delegates to the gateway');

$route = read($root . '/plugin/sand-ai/config/route.php');
ok(str_contains($route, "Route::post('/create', [AdminKnowledgeBaseController::class, 'create'])"), 'one-shot create route registered');

// 回滚语义：异常抛出即整体回滚，方法内不得吞掉 ApiProblem

$catchCount = substr_count($createSource, 'catch (ApiProblem');
ok($catchCount === 0 || str_contains($createSource, 'throw'), 'create never swallows ApiProblem inside the transaction');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
