<?php

declare(strict_types=1);

/**
 * 调用记录给人看的名字，以及当地日期换回库存时间。不连数据库。
 *
 *   php tests/invocation-rows-test.php
 */

require_once dirname(__DIR__) . '/app/Application/Invocation/InvocationRows.php';

use app\Application\Invocation\InvocationRows;

$failed = 0;

$assert = static function (bool $ok, string $name) use (&$failed): void {
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
};

$assert(InvocationRows::callerName('管理员试用', '默认应用') === '管理员', '试用身份显示成管理员');
$assert(InvocationRows::callerName('托管调用身份', '默认应用') === '托管调用身份', '保留调用身份的名字');
$assert(InvocationRows::callerName('', '默认应用') === '默认应用', '没有调用身份时用应用名');
$assert(InvocationRows::callerName(null, null) === '未知调用方', '两边都空时标明未知');
$assert(InvocationRows::purposeLabel('embedding', false) === '整理资料', '向量调用是整理资料');
$assert(InvocationRows::purposeLabel('chat', false) === '对话', '聊天调用是对话');
$assert(InvocationRows::purposeLabel('chat', true) === '查资料', '带知识库的调用是查资料');
$assert(InvocationRows::purposeLabel('other', false) === '其他', '不认识的类型归其他');
$assert(InvocationRows::utcStamp('2026-11-12', '00:00:00', -480) === '2026-11-12 08:00:00', '西八区当天起点换回协调世界时');
$assert(InvocationRows::utcStamp('2026-11-12', '23:59:59', -480) === '2026-11-13 07:59:59', '西八区当天终点换回协调世界时');
$assert(InvocationRows::utcStamp('11-12', '00:00:00', 0) === null, '不完整的日期不参与筛选');
$assert(InvocationRows::clampOffset(900) === 840, '偏移不超过十四小时');

echo "invocation-rows failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
