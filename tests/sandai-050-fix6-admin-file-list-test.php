<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 管理端文件列表：分页、文件名搜索、状态筛选、归属过滤与
 * 白话失败原因的源码级断言。不连数据库、不发网络。
 *
 * Usage: php tests/sandai-050-fix6-admin-file-list-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Application/File/FileGateway.php';

use app\Application\File\FileGateway;

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

// 可见状态与单读一致：不含 upload_pending

$expected = ['uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled', 'deleting', 'delete_failed'];
ok(FileGateway::LIST_STATES === $expected, 'list states equal file.read whitelist');
ok(!in_array('upload_pending', FileGateway::LIST_STATES, true), 'upload_pending stays invisible like single read');

// 列表方法与签名

$method = new ReflectionMethod(FileGateway::class, 'index');
$params = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());
ok($params === ['environmentId', 'page', 'limit', 'keyword', 'states'], 'index signature carries paging keyword states');

$source = read($root . '/app/Application/File/FileGateway.php');
ok(str_contains($source, 'ILIKE ? ESCAPE'), 'keyword searches file name with escaped LIKE');
ok(str_contains($source, 'FileInvocationFacts::fromPersisted'), 'ownership check reuses the read-time facts resolver');
ok(str_contains($source, 'HostSandIamEnvironmentFacts'), 'environment owner facts resolved before filtering');
ok(str_contains($source, "'sand_ai.file.read'"), 'ownership check uses the file.read action surface');
ok(str_contains($source, 'LIMIT ? OFFSET ?'), 'chunked scan stays bounded');
ok(substr_count($source, "'current_page'") >= 1 && str_contains($source, "'per_page'") && str_contains($source, "'data'"), 'page envelope follows admin list shape');

// 返回字段与脱敏

foreach (['original_name', 'size_bytes', 'media_type', 'extension', 'sha256', 'state', 'failure_text', 'create_time'] as $field) {
    ok(str_contains($source, "'" . $field . "'"), 'list item carries ' . $field);
}
ok(!str_contains($source, "'error_summary'") || !str_contains($source, "'error_summary' =>"), 'list payload never returns raw error_summary');
ok(str_contains($source, "format('Y-m-d\\TH:i:sP')"), 'create_time carries timezone offset');
ok(str_contains($source, 'DISTINCT ON (file_id)'), 'failure text reads the latest parse row only');

// 白话失败原因映射

ok(FileGateway::failureText(null) === '处理失败，请重试', 'null error code maps to generic retry text');
ok(FileGateway::failureText('SAND_AI_PARSE_UNSUPPORTED') === '当前文件类型没有可用解析驱动', 'unsupported maps to driver copy');
ok(FileGateway::failureText('SAND_AI_PARSE_QUEUE_FAILED') === '提交处理失败，请点重试', 'queue failure maps to retry copy');
ok(FileGateway::failureText('SAND_AI_OCR_UNAVAILABLE') === '图片或扫描件识别不可用，请检查 OCR 配置后重试', 'ocr maps to ocr copy');
ok(FileGateway::failureText('SAND_AI_BUDGET_SCOPE_UNVERIFIED') === 'AI 预算不足或已用完，请到费用页处理后重试', 'budget maps to budget copy');
ok(FileGateway::failureText('SAND_AI_SOMETHING_ELSE') === '处理失败，请稍后重试', 'unknown code falls back safely');

// 时间偏移投影

$offset = new ReflectionMethod(FileGateway::class, 'offsetTime');
$offset->setAccessible(true);
$rendered = $offset->invoke(null, '2026-11-12 08:30:00');
ok(is_string($rendered) && preg_match('/\A2026-11-12T08:30:00[+-][0-9]{2}:[0-9]{2}\z/', $rendered) === 1, 'offsetTime renders ISO8601 with offset');
ok($offset->invoke(null, null) === null, 'offsetTime accepts null');
ok($offset->invoke(null, new DateTimeImmutable('2026-11-12 08:30:00', new DateTimeZone('UTC'))) !== null, 'offsetTime accepts DateTime values');

// 控制器、路由与权限种子

$controller = read($root . '/plugin/sand-ai/app/admin/controller/FileController.php');
ok(str_contains($controller, "'sand_ai:file:index'"), 'admin file list declares its permission slug');
ok(str_contains($controller, 'environment_id'), 'admin file list requires environment_id');
ok(str_contains($controller, "'keyword'") && str_contains($controller, "'state'"), 'admin file list accepts keyword and state filters');

$route = read($root . '/plugin/sand-ai/config/route.php');
ok(str_contains($route, "Route::group('/app/sand-ai/admin/files'"), 'admin files route group registered');
ok(str_contains($route, "Route::get('/index', [AdminFileController::class, 'index'])"), 'file index route bound');
ok(preg_match('/admin\/files.*CheckLogin::class, CheckAuth::class/s', $route) === 1, 'file list stays behind login and auth middleware');

$migration = read($root . '/plugin/sand-ai/migrations/main-app/202611120177_file_index_permission.pgsql');
ok(str_contains($migration, "'sand_ai:file:index'"), 'permission seed declares file index slug');
ok(str_contains($migration, 'SandAIFileCenter'), 'permission seed attaches to the file center page');
ok(str_contains($migration, 'sand_ai_configuration_navigation_ownership'), 'permission seed registers navigation ownership');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
