<?php

declare(strict_types=1);

/**
 * 0.5.0 fix5 管理端界面断言：删除确认框「再想想」改灰、删除正文句号统一、
 * 知识库失败行操作列直接露出「重试」。
 * 不连数据库、不发网络，只做源码断言。
 *
 * Usage: php tests/sandai-050-fix5-admin-ux-test.php
 */

$root = dirname(__DIR__);
$view = $root . '/sandadmin-artd/src/views/plugin/sand-ai';

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

// 删除确认框：取消按钮不带主色

$confirm = read($view . '/composables/confirmDeletion.ts');
ok(str_contains($confirm, "DELETION_CANCEL_CLASS = 'sand-ai-confirm-cancel'"), 'shared confirm exports gray cancel class');
ok(str_contains($confirm, 'cancelButtonClass: DELETION_CANCEL_CLASS'), 'shared confirm applies gray cancel class');
ok(str_contains($confirm, 'cancelButtonClass') && !str_contains($confirm, 'el-button--primary'), 'cancel button carries no primary class');

$css = read($view . '/components/sandAiPage.css');
ok(str_contains($css, '.el-button.sand-ai-confirm-cancel'), 'page css defines gray cancel button');
ok(str_contains($css, '--el-border-color'), 'cancel border uses neutral border color');
ok(!str_contains($css, 'confirm-cancel') || !str_contains($css, '--el-color-primary'), 'cancel style avoids primary color');

// 删除正文句号统一

$file = read($view . '/file/index.vue');
ok(str_contains($file, '删除后不能恢复。用过的费用记录会保留。'), 'file delete copy ends with periods');
ok(!str_contains($file, '删除后不能恢复，用过的费用记录会保留'), 'file delete copy no comma variant');

$provider = read($view . '/provider/index.vue');
ok(str_contains($provider, '删除后不能恢复。用过的费用记录会保留。'), 'provider delete copy ends with periods');

$model = read($view . '/model/index.vue');
ok(str_contains($model, '删除后不能恢复。用过的费用记录会保留。'), 'model delete copy ends with periods');

$knowledge = read($view . '/knowledge/modules/knowledge-tab.vue');
ok(str_contains($knowledge, '删除后不能恢复。用过的费用记录会保留。'), 'knowledge delete copy ends with periods');

// 全部删除确认框走统一灰取消

$agentPanel = read($view . '/agent/modules/AgentDefinitionPanel.vue');
ok(str_contains($agentPanel, 'confirmDeletion'), 'agent delete uses shared confirm');

$channelPanel = read($view . '/agent/modules/ChannelBindingPanel.vue');
ok(str_contains($channelPanel, 'confirmDeletion'), 'channel delete uses shared confirm');

$workflowPanel = read($view . '/agent/modules/CompositionWorkflowPanel.vue');
ok(str_contains($workflowPanel, 'confirmDeletion'), 'composition delete uses shared confirm');

$basePanel = read($view . '/knowledge/modules/knowledge-base-panel.vue');
ok(str_contains($basePanel, 'cancelButtonClass: DELETION_CANCEL_CLASS'), 'knowledge base delete confirm uses gray cancel');

// 知识库失败行操作列直接露出「重试」

$hasRowRetry = (bool) preg_match(
    '/v-if="rowStatus\(row\) === \'failed\'"[^>]*>重试<\/ElButton>/s',
    $knowledge
);
ok($hasRowRetry, 'failed row exposes inline 重试 link button');
ok(substr_count($knowledge, 'command="retry"') >= 1, 'dropdown keeps 重试 menu item');
ok(str_contains($knowledge, '@click="retryIndex(row)"'), 'inline retry calls same retryIndex');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
