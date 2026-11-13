<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 管理端前端断言：文件页走后端分页、状态只露三态词、
 * 失败行行内「重试」、新建知识库一步抽屉与关闭确认。
 * 不连数据库、不发网络，只做源码断言。
 *
 * Usage: php tests/sandai-050-fix6-frontend-status-test.php
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

// 文件状态三态映射

$status = read($view . '/api/fileStatus.ts');
ok(str_contains($status, '处理中'), 'status map carries 处理中 label');
ok(str_contains($status, '能用了'), 'status map carries 能用了 label');
ok(str_contains($status, '处理失败'), 'status map carries 处理失败 label');
ok(str_contains($status, "'parsed'"), 'status map maps parsed to usable');
ok(str_contains($status, "'parse_failed'") && str_contains($status, "'parse_canceled'"), 'status map maps failed states');
ok(!str_contains($status, '已上传') && !str_contains($status, '已解析'), 'status map avoids legacy state words');

// 文件页走后端列表、三态词、重试

$file = read($view . '/file/index.vue');
ok(str_contains($file, 'fileApi.list('), 'file page calls admin files list');
ok(str_contains($file, 'environmentId'), 'file page scopes list by environment');
ok(!str_contains($file, 'sand-ai-file-recent:v1'), 'file page drops recent-files sessionStorage key');
ok(!str_contains($file, 'rememberRecent') && !str_contains($file, 'reloadRecent'), 'file page drops recent-files helpers');
ok(!str_contains($file, '已上传') && !str_contains($file, '已解析') && !str_contains($file, '提交解析'), 'file page avoids legacy state words and parse submit action');
ok(!str_contains($file, '继续原解析任务') && !str_contains($file, '核对取消后重新解析'), 'file page drops legacy parse action labels');
ok(str_contains($file, 'fileStatusView') && str_contains($file, 'fileStatusText'), 'file page renders three-state words');
ok(str_contains($file, 'ElTooltip') && str_contains($file, 'failure_text'), 'file page shows failure_text tooltip');
ok(str_contains($file, "label: '重试'"), 'file page failed row exposes inline 重试');
ok(str_contains($file, 'retryParse(') && str_contains($file, 'fileApi.parse('), 'inline retry calls fileApi.parse');
ok(str_contains($file, 'ElPagination') && str_contains($file, 'listPage'), 'file page keeps backend pagination');
ok(str_contains($file, 'listKeyword') && str_contains($file, 'listStateFilter'), 'file page keeps search and state filter');
ok(str_contains($file, 'listPollTimer'), 'file page polls processing rows');

// 一步建库抽屉

$tab = read($view . '/knowledge/modules/knowledge-tab.vue');
ok(str_contains($tab, ':before-close="confirmCreateClose"'), 'create drawer guards close');
ok(str_contains($tab, '还没创建，确定不要了？'), 'create drawer confirm copy');
ok(str_contains($tab, "confirmButtonText: '不要了'") && str_contains($tab, "cancelButtonText: '再想想'"), 'create drawer confirm buttons');
ok(str_contains($tab, 'cancelButtonClass: DELETION_CANCEL_CLASS'), 'create drawer cancel uses gray class');
ok(str_contains($tab, '至少选一份资料'), 'create drawer shows pick-one hint');
ok(str_contains($tab, '还没有能用的文件，先去'), 'create drawer empty-files copy');
ok(str_contains($tab, '/sand-ai/file') && str_contains($tab, 'RouterLink'), 'empty-files copy links to file page');
ok(str_contains($tab, 'knowledgeApi.create('), 'create drawer calls one-shot create');
ok(str_contains($tab, 'crypto.randomUUID()'), 'create drawer sends fresh idempotency key');
ok(!str_contains($tab, 'pendingKnowledgeBase') && !str_contains($tab, 'pendingDetail'), 'knowledge tab drops half-created branches');
ok(!str_contains($tab, '还没建好，先选几份资料'), 'knowledge tab drops half-created alert');
ok(str_contains($tab, 'openFilePicker') && str_contains($tab, 'confirmAddMaterials'), 'detail drawer keeps 加资料 flow');
ok(str_contains($tab, 'createDraft(') && str_contains($tab, 'knowledgeApi.publish('), 'add-material keeps draft+publish flow');
ok(!str_contains($tab, '已解析'), 'knowledge tab avoids legacy parse word');

// API 层契约

$api = read($view . '/api/knowledgeBase.ts');
ok(str_contains($api, '/create'), 'create endpoint wired');
ok(str_contains($api, "'Idempotency-Key': key"), 'create sends Idempotency-Key header');

$fileApi = read($view . '/api/file.ts');
ok(str_contains($fileApi, 'ADMIN_API_PREFIX') && str_contains($fileApi, '/files/index'), 'fileApi.list uses admin prefix');
ok(str_contains($fileApi, 'environment_id'), 'fileApi.list passes environment_id');
ok(str_contains($fileApi, 'FileListRow') && str_contains($fileApi, 'SandAiPage'), 'fileApi.list typed with page envelope');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
