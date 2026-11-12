<?php

declare(strict_types=1);

/**
 * 模型部署、材料、任务、账单在后端拒绝或结果不明时要能解锁。
 *
 * Usage: php tests/deployment-rejection-unlock-test.php
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

$root = dirname(__DIR__) . '/sandadmin-artd/src/views/plugin/sand-ai';
$read = static function (string $path) use ($root): string {
    $text = file_get_contents($root . '/' . $path);
    if ($text === false) {
        fwrite(STDERR, "FAIL read {$path}\n");
        exit(1);
    }
    return $text;
};

$ocr = $read('composables/useOcrDeploymentRecovery.ts');
$audio = $read('composables/useAudioDeploymentRecovery.ts');
ok(str_contains($ocr, 'function abandonLocalJournal'), 'ocr can abandon a local journal');
ok(str_contains($audio, 'function abandonLocalJournal'), 'audio can abandon a local journal');
ok(str_contains($ocr, 'isWriteRejected(error)'), 'ocr unlocks a rejected write');
ok(str_contains($audio, 'isWriteRejected(error)'), 'audio unlocks a rejected write');

// OCR 首装失败页按验收文案用「放弃这次改动」，其余页沿用「放弃此次变更」。
ok(str_contains($read('components/OcrDeploymentSetup.vue'), '放弃这次改动'), 'components/OcrDeploymentSetup.vue shows the abandon action');
$pages = [
    'components/SpeechDeploymentSetup.vue',
    'components/TranscriptionDeploymentSetup.vue',
    'components/EmbeddingDeploymentSetup.vue',
    'components/RerankDeploymentSetup.vue',
    'components/SuccessorDeploymentSetup.vue',
    'components/MaterialCapabilitySetup.vue',
    'task/index.vue',
    'usage/modules/billing-sync-form.vue',
    'usage/modules/billing-import-form.vue',
];
foreach ($pages as $page) {
    ok(str_contains($read($page), '放弃此次变更'), $page . ' shows the abandon action');
}

$billing = $read('api/billing-recovery.ts');
ok(str_contains($billing, 'function dropStuck'), 'billing journal can drop a stuck log');

$capability = $read('capability/index.vue');
ok(substr_count($capability, '拒绝已结清后恢复可编辑') === 3, 'capability restores editing after a rejected write');

$budget = $read('usage/modules/budget-policy-panel.vue');
ok(str_contains($budget, 'persistPendingSaves(pendingSaves.value.filter(item => item.requestKey !== operation.requestKey), actorId)'), 'budget draft drops its log after rejection');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
