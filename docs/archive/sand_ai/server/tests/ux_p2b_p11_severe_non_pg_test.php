<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// UX P2B P11 三条严重项的源码契约检查（不需要 PostgreSQL）：
// S-1 首页读接口失败不得渲染成「没有应用环境」假向导态；
// S-2 英文原始报错不进界面（统一入口 errorText.ts + displayableRuntimeMessage）；
// S-3 一键开通默认档覆盖 document_parse，旧版仅 chat 默认档幂等回放不静默改档。

$root = dirname(__DIR__);
$frontend = dirname($root) . '/sandadmin-artd/src/views/plugin/sand-ai';
$backend = $root . '/app/Application/Onboarding/OnboardingPublicationService.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P2B P11 severe check failed: ' . $label);
    }
    ++$checks;
};

// ==== S-1: home/index.vue 失败态与空态分离 ====

$home = (string) file_get_contents($frontend . '/home/index.vue');
$assert(str_contains($home, 'environmentLoadFailed'), 'home tracks environment load failure separately');
$assert(str_contains($home, '应用环境列表读取失败'), 'env load failure shows real failure text');
$assert(str_contains($home, '读取失败不代表没有环境'), 'env failure text disclaims false empty claim');
$assert(str_contains($home, 'v-else-if="!intent && applications.length && !environmentId"'),
    'empty-env guidance only renders when the read succeeded');
$assert(substr_count($home, 'environmentLoadFailed.value = true') >= 2,
    'both initial load and app-switch env failures set the flag');
$assert(str_contains($home, "apps = await iamApi.activeApplications()\n        if (!live"),
    'post-ensure application re-read propagates failures to loadError');

// ==== S-2: 统一中文错误文案入口 ====

$errorText = (string) file_get_contents($frontend . '/api/errorText.ts');
$assert(str_contains($errorText, 'export function errorDisplayText'), 'unified errorDisplayText entry exists');
$assert(str_contains($errorText, 'hintForRuntimeErrorCode'), 'stable-code mapping reuses frozen hint table');
$assert(str_contains($errorText, 'displayableRuntimeMessage'), 'raw messages pass the CJK gate');
$assert(str_contains($errorText, 'console.error'), 'raw details kept in console, not UI');
$assert(str_contains($errorText, '服务暂时异常'), '5xx gets generic Chinese text');

$runtimeErrors = (string) file_get_contents($frontend . '/api/runtimeErrors.ts');
$assert(str_contains($runtimeErrors, 'export function displayableRuntimeMessage'), 'CJK gate helper exists');
$assert(str_contains($runtimeErrors, 'hint ?? displayableRuntimeMessage(error.message) ?? '),
    'toRuntimeUiError no longer passthroughs raw English messages');
$assert(!str_contains($runtimeErrors, "error.message,\n      error.errorCode"),
    'raw error.message removed from UI description parts');
$assert(str_contains($runtimeErrors, '能力配置'), 'CAPABILITY_UNSUPPORTED hint points to capability config');

// 透传点改为统一入口
$task = (string) file_get_contents($frontend . '/task/index.vue');
$assert(str_contains($task, "summaryModelsError.value = errorDisplayText(error, '无法读取模型目录')"),
    'task page model-catalog failure uses unified text');
$file = (string) file_get_contents($frontend . '/file/index.vue');
$assert(str_contains($file, 'errorDisplayText(error)'), 'file page parse failure uses unified text');
$assert(str_contains($file, "router.push('/sand-ai/capability')"), 'file page offers direct capability-config link');
$trial = (string) file_get_contents($frontend . '/composables/useAdminTrialBearer.ts');
$assert(str_contains($trial, 'errorDisplayText'), 'trial-bearer failure text is sanitized');

foreach (['budget-policy-panel.vue', 'budget-history-browser.vue', 'usage-cost-panel.vue',
    'invocation-cost-panel.vue', 'cost-comparison-panel.vue'] as $panel) {
    $body = (string) file_get_contents($frontend . '/usage/modules/' . $panel);
    $assert(!str_contains($body, '.message : '), "$panel no longer shows raw error.message");
    $assert(str_contains($body, 'errorDisplayText'), "$panel uses unified error text");
}
foreach (['capability/index.vue', 'invocation/index.vue'] as $page) {
    $body = (string) file_get_contents($frontend . '/' . $page);
    $assert(!str_contains($body, '.message : '), "$page no longer shows raw error.message");
}

// 插件读调用压住宿主原文 toast（页面均有内联中文错误）
foreach (['iam.ts', 'budget.ts', 'usage.ts', 'capability.ts', 'onboarding.ts'] as $apiFile) {
    $body = (string) file_get_contents($frontend . '/api/' . $apiFile);
    $assert(str_contains($body, 'showErrorMessage: false'), "$apiFile suppresses raw server-message toasts");
}
$budget = (string) file_get_contents($frontend . '/api/budget.ts');
$assert(substr_count($budget, 'showErrorMessage: false') >= 10, 'all budget calls suppress raw toast');
$iam = (string) file_get_contents($frontend . '/api/iam.ts');
$assert(substr_count($iam, 'showErrorMessage: false') >= 5, 'all iam calls suppress raw toast');

// ==== S-3: 一键开通默认档含 document_parse；旧档回放 ====

$service = (string) file_get_contents($backend);
$assert(str_contains($service, "'capability_code' => 'document_parse'"), 'default profile covers document_parse');
$assert(str_contains($service, "'driver_code' => 'native_document_parse'"), 'document_parse uses local native driver');
$assert(str_contains($service, '$legacyDesiredConfiguration'), 'legacy chat-only default is recognized');
$assert(str_contains($service, '|| $existingConfiguration === $legacyDesiredConfiguration'),
    'legacy default profile replays idempotently without silent mutation');
$assert(str_contains($service, 'foreach ($desiredRoutes as $route)'), 'all desired routes are persisted');
$assert(str_contains($service, "'routes' => \$existingNormalized"),
    'replay returns actual persisted routes, not the new desired shape');

// 驱动注册表确认 native_document_parse 是 local_only（无外发）
$registry = (string) file_get_contents($root . '/app/Infrastructure/Capability/CapabilityDriverRegistry.php');
$assert(str_contains($registry, "'native_document_parse' => [\n                        'data_egress' => 'local_only'"),
    'native_document_parse stays local_only');

// 契约文档：bind-default-chat 语义（默认档能力集）如有描述须一致——检查契约不含「仅 chat」表述
$contract = (string) file_get_contents($root . '/plugin/sand-ai/docs/development/sand-ai-api-contract.md');
$assert(!str_contains($contract, '仅 chat'), 'contract does not pin chat-only default');

fwrite(STDOUT, "ux_p2b_p11_severe_non_pg_test: {$checks} checks passed\n");
