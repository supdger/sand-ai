<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 排队身份上下文过期：密封的 SandIAM 上下文到期后 open() 拒绝
 * （SAND_AI_IDENTITY_CONTEXT_EXPIRED）；assertExpiredAuthentic 是内部清理证明，
 * 对过期信封仍应认证通过；AAD 中 expires_at 被篡改时认证失败。
 * 依赖 ORM 的 present() 行为只做源码断言，动态验证归 QA。
 *
 * Usage: php tests/sandai-050-fix6-iam-context-expiry-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Api/Support/ApiProblem.php';
require_once $root . '/app/Identity/Infrastructure/QueuedSandIamContextAad.php';
require_once $root . '/app/Identity/Infrastructure/QueuedSandIamContextBinding.php';
require_once $root . '/app/Identity/Infrastructure/QueuedSandIamContextCipher.php';
require_once $root . '/app/Application/Task/TaskAuthorizationContinuation.php';

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskAuthorizationContinuation;
use app\Identity\Infrastructure\QueuedSandIamContextBinding;
use app\Identity\Infrastructure\QueuedSandIamContextCipher;

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

$cipher = new QueuedSandIamContextCipher([
    'current_key_id' => 'testkey1',
    'current_key' => base64_encode(str_repeat('k', 32)),
    'previous_keys_json' => '',
]);
$context = '{"sub":"worker-context","scope":"test"}';

// seal + open 正常往返。
$binding = new QueuedSandIamContextBinding(1, 7, '203.0.113.10', 'req-fix6-expiry', time() + 60);
$envelope = $cipher->seal($context, $binding);
ok($cipher->open($envelope, $binding) === $context, 'open returns the sealed context');

// binding 的 expires_at 跨过当前时间后：open 拒绝为 EXPIRED，assertExpiredAuthentic 仍认证通过。
$shortLived = new QueuedSandIamContextBinding(1, 7, '203.0.113.10', 'req-fix6-short', time() + 1);
$shortEnvelope = $cipher->seal($context, $shortLived);
sleep(2);
$expiredCode = null;
try {
    $cipher->open($shortEnvelope, $shortLived);
} catch (ApiProblem $problem) {
    $expiredCode = $problem->errorCode;
}
ok($expiredCode === 'SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'expired binding opens as SAND_AI_IDENTITY_CONTEXT_EXPIRED');

$authentic = true;
try {
    $cipher->assertExpiredAuthentic($shortEnvelope, $shortLived);
} catch (ApiProblem) {
    $authentic = false;
}
ok($authentic, 'assertExpiredAuthentic authenticates the expired envelope');

// 篡改 expires_at 的 AAD（有效期不同）→ 认证失败 UNAVAILABLE。
$tampered = new QueuedSandIamContextBinding(1, 7, '203.0.113.10', 'req-fix6-short', time() + 120);
$tamperedCode = null;
try {
    $cipher->open($shortEnvelope, $tampered);
} catch (ApiProblem $problem) {
    $tamperedCode = $problem->errorCode;
}
ok($tamperedCode === 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE',
    'tampered expires_at AAD fails as SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');

// 授权失败清单覆盖上下文过期与凭证吊销。
ok(TaskAuthorizationContinuation::authorizationFailure('SAND_AI_IDENTITY_CONTEXT_EXPIRED'),
    'authorizationFailure includes context expiry');
ok(TaskAuthorizationContinuation::authorizationFailure('SAND_AI_CREDENTIAL_REVOKED'),
    'authorizationFailure includes credential revocation');
ok(!TaskAuthorizationContinuation::authorizationFailure('SAND_AI_PARSE_FAILED'),
    'authorizationFailure rejects non-authorization codes');

// present() 的 $paused 窗口须包含 FAILED：这两类终止态任务才允许授权续期。
$source = file_get_contents($root . '/app/Application/Task/TaskAuthorizationContinuation.php');
ok($source !== false
    && str_contains($source, 'TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING, TaskState::FAILED, TaskState::RECOVERY_PENDING'),
    'present() paused window includes FAILED');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
