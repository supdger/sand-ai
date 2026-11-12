<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 上传自动排队解析：上传成功后由后端入队，浏览器关掉也会继续；
 * 入队失败落 parse_failed 可重试投影；原解析端点保留。不连数据库、不发网络。
 *
 * Usage: php tests/sandai-050-fix6-upload-autoparse-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Api/Support/ApiProblem.php';
require_once $root . '/app/Application/Trial/AdminTrialActions.php';

use app\Application\Trial\AdminTrialActions;

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

$controller = read($root . '/app/Api/Controller/FileController.php');

// 上传链路在返回 receipt 前自动入队解析

$uploadPos = strpos($controller, '(new FileGateway())->upload(');
$queuePos = strpos($controller, '$this->queueParse(');
$successPos = strpos($controller, 'return ApiResponder::success($result, $requestId)');
ok($uploadPos !== false && $queuePos !== false && $successPos !== false
    && $uploadPos < $queuePos && $queuePos < $successPos,
    'upload queues parse between committed upload and success receipt');
ok(str_contains($controller, 'FileParseAccessGuard'), 'upload uses the parse access guard');
ok(str_contains($controller, "(new FileParseGateway())->parse("), 'upload calls the parse gateway directly');
ok(str_contains($controller, "'sand_ai.document_parse'"), 'auto queue carries the document_parse action');
ok(str_contains($controller, "'uploaded'"), 'auto queue only fires for freshly uploaded files');
ok(str_contains($controller, 'markEnqueueFailed'), 'queue failure has a fallback projection');
ok(str_contains($controller, 'catch (Throwable'), 'queue failure never retracts the upload receipt');

// 入队失败的保底投影

$gateway = read($root . '/app/Application/File/FileParseGateway.php');
ok(str_contains($gateway, 'function markEnqueueFailed'), 'parse gateway owns the enqueue failure fallback');
ok(str_contains($gateway, "'parse_failed'"), 'fallback moves the file to parse_failed for retry');
ok(str_contains($gateway, 'TaskState::FAILED') && str_contains($gateway, 'SAND_AI_PARSE_QUEUE_FAILED'), 'fallback writes a failed parse row');
ok(str_contains($gateway, '提交处理失败，请点重试'), 'fallback summary is plain-language retry copy');
ok(str_contains($gateway, "state !== 'uploaded'"), 'fallback never clobbers a state another request already moved');

// parse_failed 可从原解析端点重试：parse() 只拒绝 deleting/deleted

ok(str_contains($gateway, "in_array((string) \$file->state, ['deleting', 'deleted'], true)"), 'parse endpoint only rejects deleting and deleted states');
ok(!str_contains($gateway, "['deleting', 'deleted', 'parse_failed']"), 'parse_failed stays retryable through the parse endpoint');

// 试用身份已覆盖解析动作，自动入队不会因缺动作被拒

ok(in_array('sand_ai.document_parse', AdminTrialActions::WHITELIST, true), 'trial whitelist already carries document_parse');
ok(in_array('sand_ai.file.write', AdminTrialActions::WHITELIST, true), 'trial whitelist still carries file.write');

// 原解析接口保留给重试

$route = read($root . '/plugin/sand-ai/config/route.php');
ok(str_contains($route, "Route::post('/api/sand-ai/v1/files/{fileId}/parse'"), 'manual parse endpoint stays for retry');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
