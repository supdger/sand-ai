<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// UX P2B B-1 / G-10 / G-9 源码契约检查（不需要 PostgreSQL）：
// B-1 文件页可直接查看「解析出的文字」（GET /files/{id}/source-blocks 已有 preview），
//     「看任务」「查看原任务与来源」不再把已解析文件导向凭证隔离下必空的任务页；
// G-10 *_FORBIDDEN 统一中文兜底，error_code/request_id 不上屏（只进 console）；
// G-9 「近 30 天合计」卡片置顶到费用页首屏。

$root = dirname(__DIR__);
$frontend = dirname($root) . '/sandadmin-artd/src/views/plugin/sand-ai';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P2B B-1 source-view check failed: ' . $label);
    }
    ++$checks;
};

// ==== B-1: 解析内容抽屉组件存在且走统一中文错误 ====

$drawerPath = $frontend . '/components/FileParsedTextDrawer.vue';
$assert(is_file($drawerPath), 'FileParsedTextDrawer.vue exists');
$drawer = (string) file_get_contents($drawerPath);
$assert(str_contains($drawer, '解析出的文字'), 'drawer has non-technical title');
$assert(str_contains($drawer, 'readSourceBlocks'), 'drawer reads /files/{id}/source-blocks');
$assert(str_contains($drawer, 'block.preview'), 'drawer renders block preview text');
$assert(str_contains($drawer, 'errorDisplayText'), 'drawer errors go through unified Chinese text');
$assert(str_contains($drawer, '该文件没有可显示的解析文字'), 'drawer has Chinese empty state');
$assert(str_contains($drawer, '继续读取更多内容'), 'drawer paginates remaining blocks');
$assert(!str_contains($drawer, 'error_code') && !str_contains($drawer, 'request_id'),
    'drawer never surfaces raw error codes or request ids');

// ==== B-1: 文件页接入点 ====

$file = (string) file_get_contents($frontend . '/file/index.vue');
$assert(str_contains($file, "import FileParsedTextDrawer from '../components/FileParsedTextDrawer.vue'"),
    'file page imports the drawer');
$assert(str_contains($file, 'parsedTextTargetForRow'), 'file page computes parse-text target per row');
$assert(str_contains($file, '查看解析出的文字'), 'file page offers parsed-text entry');
$assert(str_contains($file, '查看解析内容'), 'file row action opens parsed text instead of task link');
$assert(str_contains($file, 'recoveredParseTextTarget'), 'recovery flow resolves parsed-text target');
$assert(str_contains($file, 'knownParseTextTarget'), 'read-only query resolves parsed-text target');
$assert(substr_count($file, "router.push({ path: '/sand-ai/task'") >= 1,
    'task link retained only for non-succeeded states');
// 已解析文件的三处入口均不再无条件下跳任务页
$assert(str_contains($file, "if (target) {\n      const file = recentFiles.value.find(item => item.id === target.fileId)"),
    'recovered-parse view prefers parsed text over task page');
$assert(str_contains($file, "lastError.code === 'SAND_AI_CAPABILITY_UNSUPPORTED'"),
    'capability link branches on sanitized code field, not raw title');

// ==== B-1: 任务空列表中文说明 + 直达文件页 ====

$task = (string) file_get_contents($frontend . '/task/index.vue');
$assert(str_contains($task, '任务记录按调用身份隔离'), 'task page explains credential-isolated empty list');
$assert(str_contains($task, "router.push('/sand-ai/file')"), 'task page links to file page for parsed text');
$assert(str_contains($task, 'useRouter'), 'task page imports useRouter');
$assert(str_contains($task, '当前调用身份下暂无可显示的任务记录'), 'task table empty text is honest');

// ==== G-10: FORBIDDEN 中文兜底 + error_code/request_id 不上屏 ====

$runtimeErrors = (string) file_get_contents($frontend . '/api/runtimeErrors.ts');
$assert(str_contains($runtimeErrors, '暂无权限查看该内容'), 'FORBIDDEN has friendly Chinese hint');
$assert(str_contains($runtimeErrors, '/_FORBIDDEN$/'), 'generic *_FORBIDDEN fallback exists');
$assert(str_contains($runtimeErrors, 'code?: string'), 'RuntimeUiError exposes code only for branching');
$assert(str_contains($runtimeErrors, 'console.error'), 'raw details kept in console');
$assert(!str_contains($runtimeErrors, 'error_code=${'), 'error_code not interpolated into UI text');
$assert(!str_contains($runtimeErrors, 'request_id=${'), 'request_id not interpolated into UI text');
$assert(str_contains($runtimeErrors, "title: '请求未完成'"), 'alert title no longer a raw error code');

$notice = (string) file_get_contents($frontend . '/api/runtimeIdentityNotice.ts');
$assert(str_contains($notice, '暂无权限查看该内容'), 'identity refusal FORBIDDEN text is user-friendly');

// 全部 sand-ai 前端面不得再出现 error_code=/request_id= 上屏格式
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontend, FilesystemIterator::SKIP_DOTS));
$rawCodeLeaks = 0;
foreach ($iterator as $entry) {
    if (!$entry->isFile() || !in_array($entry->getExtension(), ['vue', 'ts'], true)) {
        continue;
    }
    $body = (string) file_get_contents($entry->getPathname());
    if (str_contains($body, 'error_code=') || str_contains($body, 'request_id=')) {
        ++$rawCodeLeaks;
    }
}
$assert($rawCodeLeaks === 0, 'no error_code=/request_id= raw display remains in sand-ai views');

// ==== G-9: 近 30 天合计置顶 ====

$usage = (string) file_get_contents($frontend . '/usage/modules/usage-cost-panel.vue');
$assert(str_contains($usage, 'summary-top'), 'top summary card exists');
$assert(str_contains($usage, 'id="usage-cost-summary"'), 'summary card has scroll anchor');
$assert(str_contains($usage, 'scrollToSummary'), 'jump-to-details handler exists');
$assert(strpos($usage, 'class="summary-top"') < strpos($usage, 'BudgetPolicyPanel'),
    'recent-30-day total renders before the tall panels');

fwrite(STDOUT, "ux_p2b_b1_source_view_non_pg_test: {$checks} checks passed\n");
