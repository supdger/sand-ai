<?php

declare(strict_types=1);

/**
 * 0.5.0 fix4 管理端界面整改断言：普通管理员可读性、N-2 上传恢复、
 * N-4 时区归一、删除确认统一、费用核对按权限开放。
 * 不连数据库、不发网络，只做源码断言。
 *
 * Usage: php tests/sandai-050-fix4-admin-ux-test.php
 */

$root = dirname(__DIR__);
$view = $root . '/sandadmin-artd/src/views/plugin/sand-ai';
$adminSrc = dirname($root) . '/sandadmin/sandadmin-artd/src';

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

// N-4 / N-2：时区归一

$localTime = read($view . '/api/localTime.ts');
ok(str_contains($localTime, 'export function serverTimePoint'), 'localTime exports serverTimePoint');
ok(str_contains($localTime, "SERVER_OFFSET = '+08:00'"), 'server wall clock interpreted as +08:00');
ok(str_contains($localTime, 'export function formatServerMinute'), 'localTime exports formatServerMinute');
ok(str_contains($localTime, 'getFullYear() === now.getFullYear()'), 'same-year rows omit year');

$bearer = read($view . '/composables/useAdminTrialBearer.ts');
ok(str_contains($bearer, 'serverTimePoint'), 'trial expiry parsed via serverTimePoint (N-2 root cause)');
ok(!str_contains($bearer, "Date.parse(value.replace(' ', 'T'))"), 'no naive local-time Date.parse on expiry');

$upload = read($view . '/components/PrivateFileUpload.vue');
ok(str_contains($upload, 'scheduleRetry'), 'upload init schedules bounded retry');
ok(str_contains($upload, 'autoRetries >= 2'), 'auto retry is bounded at 2 attempts');
ok(str_contains($upload, '暂时不能上传，请稍后再试'), 'plain upload failure copy for normal admins');

// 文件页整改

$file = read($view . '/file/index.vue');
ok(!str_contains($file, '文件中心'), 'file page no longer titled 文件中心');
ok(str_contains($file, '上传文档或录音，AI 帮你整理成文字'), 'file page plain description');
ok(str_contains($file, '还没有文件'), 'file empty state title');
ok(str_contains($file, '上传一份文档或录音试试'), 'file empty state hint');
ok(str_contains($file, '上传文件'), 'solid 上传文件 button');
ok(!str_contains($file, '选择并上传材料'), 'old upload entry copy removed from file page');
ok(str_contains($file, '已删除「'), 'delete success toast uses file name');
ok(str_contains($file, '删除后不能恢复，用过的费用记录会保留。'), 'delete confirm plain consequence');
ok(str_contains($file, "route.query.dev === '1'"), 'dev-only blocks gated by dev=1 query');

// 知识库列表整改

$knowledge = read($view . '/knowledge/modules/knowledge-tab.vue');
ok(str_contains($knowledge, '整理失败'), 'failed status shows only 整理失败');
ok(str_contains($knowledge, 'index_error'), 'failure reason kept for hover text');
ok(str_contains($knowledge, 'formatServerMinute'), 'update time uses shared local formatter');
ok(str_contains($knowledge, 'confirmDeletion'), 'knowledge delete uses shared confirm');

// 删除确认统一

$confirm = read($view . '/composables/confirmDeletion.ts');
ok(str_contains($confirm, '再想想'), 'shared confirm uses 再想想 cancel');
ok(str_contains($confirm, "'删除'") || str_contains($confirm, '"删除"'), 'shared confirm uses 删除 confirm text');
ok(str_contains($confirm, 'danger'), 'shared confirm styles delete as danger');

$provider = read($view . '/provider/index.vue');
ok(str_contains($provider, 'confirmDeletion'), 'provider delete uses shared confirm');
$model = read($view . '/model/index.vue');
ok(str_contains($model, 'confirmDeletion'), 'model delete uses shared confirm');

// 费用核对按权限开放

$usage = read($view . '/usage/index.vue');
ok(!str_contains($usage, 'devSurfaceOpen'), 'usage review entry no longer gated by dev flag');
ok(str_contains($usage, 'canReview'), 'usage review entry still gated by permission');

$devSurface = read($adminSrc . '/utils/devSurface.ts');
ok(!str_contains($devSurface, 'usage-review'), 'usage-review removed from dev-only path list');
ok(!str_contains($devSurface, '费用核对'), '费用核对 removed from dev-only titles');

// 时间列应用面

ok(str_contains(read($view . '/dashboard/index.vue'), 'formatServerMinute'), 'dashboard times localized');
ok(str_contains(read($view . '/invocation/index.vue'), 'formatServerMinute'), 'invocation request time localized');
ok(str_contains(read($view . '/audit/index.vue'), 'formatServerMinute'), 'audit time localized');
ok(str_contains(read($view . '/usage/modules/usage-cost-panel.vue'), 'formatUtcDateTime'), 'usage event time (UTC) localized');
ok(str_contains(read($view . '/usage/modules/invocation-cost-details.vue'), 'formatServerDateTime'), 'identity received time localized');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
