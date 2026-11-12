<?php

declare(strict_types=1);

/**
 * 审计试用签发按人和当地日期合成，句子里不带内部词。不连数据库。
 *
 *   php tests/audit-rows-test.php
 */

require_once dirname(__DIR__) . '/app/Application/Audit/AuditRows.php';

use app\Application\Audit\AuditRows;

$failed = 0;

$assert = static function (bool $ok, string $name) use (&$failed): void {
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
};

$assert(AuditRows::localDate('2026-11-12 07:30:00', -480) === '2026-11-11', '西八区把协调世界时的早上算到前一天');
$assert(AuditRows::trialSentence('2026-11-12', '2026-11-12', 3) === '今天签发试用身份 3 次', '当天的签发说今天');
$assert(AuditRows::trialSentence('2026-11-11', '2026-11-12', 2) === '11-11 签发试用身份 2 次', '其他日期写月和日');
$assert(AuditRows::resultLabel('file.upload') === '成功', '普通操作算成功');
$assert(AuditRows::resultLabel('provider.test.failed') === '失败', '动作名里带失败算失败');
$assert(AuditRows::personName('祭道之上', 'admin') === '祭道之上', '有姓名时用姓名');
$assert(AuditRows::personName('', 'admin') === '管理员', '登录名是 admin 且没有姓名时写管理员');
$assert(AuditRows::plainSentence('已重建知识向量索引') === '整理好了资料', '整理资料的句子不用内部词');
$assert(!str_contains(AuditRows::plainSentence('价格版本已更新'), '版本'), '句子去掉版本二字');

$rows = [
    [
        'id' => 20,
        'create_time' => '2026-11-12 16:00:00',
        'actor_type' => 'admin',
        'actor_ref' => '1',
        'action' => 'admin_trial.context.issue',
        'resource_type' => 'workload_client',
        'resource_id' => 3,
        'summary' => '签发管理员试用身份',
    ],
    [
        'id' => 19,
        'create_time' => '2026-11-12 15:00:00',
        'actor_type' => 'admin',
        'actor_ref' => '1',
        'action' => 'admin_trial.context.issue',
        'resource_type' => 'workload_client',
        'resource_id' => 3,
        'summary' => '签发管理员试用身份',
    ],
    [
        'id' => 18,
        'create_time' => '2026-11-12 14:00:00',
        'actor_type' => 'admin',
        'actor_ref' => '1',
        'action' => 'file.upload',
        'resource_type' => 'file',
        'resource_id' => 9,
        'summary' => '上传文件',
    ],
];
$merged = AuditRows::mergeTrialIssues($rows, 0, '2026-11-12');
$assert(count($merged) === 2, '同一人同一天的两次签发合成一行');
$assert((int) $merged[0]['issue_count'] === 2, '合成行记下次数');
$assert($merged[0]['sentence_key'] === '今天签发试用身份 2 次', '合成行用今天的说法');
$assert((string) $merged[0]['create_time'] === '2026-11-12 16:00:00', '合成行保留最新一条的时间');
$assert((string) $merged[1]['action'] === 'file.upload', '其他操作仍单独成行');

echo "audit-rows failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
