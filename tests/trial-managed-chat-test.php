<?php

declare(strict_types=1);

/**
 * Isolated B2 administrator-trial tests: no database, no SandIAM plugin, no
 * network, no keys. Covers request normalization, the unavailable-IAM
 * degradation path, the per-admin rate limit and the service-catalog
 * declaration metadata. The SandIAM-backed grant/binding/projection paths are
 * covered by the fixture suites in the coordinated repositories.
 *
 * Usage: php tests/trial-managed-chat-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptions.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptionsPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialRateLimiter.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamRuntime.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialIamGateway.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialService.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamServiceCatalog.php';

use app\Api\Support\ApiProblem;
use app\Application\Trial\AdminTrialIamGateway;
use app\Application\Trial\AdminTrialRateLimiter;
use app\Application\Trial\AdminTrialService;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;

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

/** @return array{code:string,message:string}|null */
function problemOf(callable $run): ?array
{
    try {
        $run();
    } catch (ApiProblem $problem) {
        return ['code' => $problem->errorCode, 'message' => $problem->getMessage()];
    } catch (Throwable $exception) {
        return ['code' => get_class($exception), 'message' => $exception->getMessage()];
    }

    return null;
}

function hasCjk(string $value): bool
{
    return preg_match('/[\x{4e00}-\x{9fff}]/u', $value) === 1;
}

// --- normalizeChatInput -----------------------------------------------------
$normalized = AdminTrialService::normalizeChatInput([
    'application_id' => 7,
    'provider_id' => '12',
    'message' => '  你好  ',
]);
ok($normalized['application_id'] === 7
    && $normalized['provider_id'] === 12
    && $normalized['message'] === '你好'
    && $normalized['model_id'] === null
    && $normalized['max_output_tokens'] === AdminTrialService::DEFAULT_MAX_OUTPUT_TOKENS,
    '规范化：缺省 model_id 与 max_output_tokens 落到默认值');

$normalized = AdminTrialService::normalizeChatInput([
    'application_id' => 7,
    'provider_id' => 12,
    'message' => '你好',
    'model_id' => 34,
    'max_output_tokens' => 2048,
]);
ok($normalized['model_id'] === 34 && $normalized['max_output_tokens'] === 2048,
    '规范化：显式 model_id 与 max_output_tokens 保留');

foreach ([
    ['application_id' => 0, 'provider_id' => 1, 'message' => 'x'],
    ['application_id' => -1, 'provider_id' => 1, 'message' => 'x'],
    ['application_id' => 'abc', 'provider_id' => 1, 'message' => 'x'],
    ['application_id' => 1.5, 'provider_id' => 1, 'message' => 'x'],
    ['provider_id' => 1, 'message' => 'x'],
    ['application_id' => 1, 'provider_id' => 0, 'message' => 'x'],
    ['application_id' => 1, 'provider_id' => 'x', 'message' => 'x'],
] as $index => $bad) {
    $problem = problemOf(static fn () => AdminTrialService::normalizeChatInput($bad));
    ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR',
        '校验拒绝：非法标识字段 #' . $index);
}

foreach ([
    ['application_id' => 1, 'provider_id' => 1],
    ['application_id' => 1, 'provider_id' => 1, 'message' => ''],
    ['application_id' => 1, 'provider_id' => 1, 'message' => '   '],
    ['application_id' => 1, 'provider_id' => 1, 'message' => ['x']],
    ['application_id' => 1, 'provider_id' => 1, 'message' => str_repeat('字', 32001)],
] as $index => $bad) {
    $problem = problemOf(static fn () => AdminTrialService::normalizeChatInput($bad));
    ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_MESSAGE_INVALID',
        '校验拒绝：非法消息 #' . $index);
}

$problem = problemOf(static fn () => AdminTrialService::normalizeChatInput([
    'application_id' => 1, 'provider_id' => 1, 'message' => 'x', 'model_id' => 0,
]));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR',
    '校验拒绝：model_id 非正整数');

foreach ([0, -1, 131073, 'lots', 1.5] as $index => $badTokens) {
    $problem = problemOf(static fn () => AdminTrialService::normalizeChatInput([
        'application_id' => 1, 'provider_id' => 1, 'message' => 'x', 'max_output_tokens' => $badTokens,
    ]));
    ok($problem !== null, '校验拒绝：max_output_tokens 非法 #' . $index);
}

// --- SandIAM 缺失时的降级 -----------------------------------------------------
ok(AdminTrialIamGateway::available() === false, '环境无 SandIAM 插件时网关报告不可用');

$problem = problemOf(static fn () => (new AdminTrialIamGateway())->requireManageableApplication(1, 1, null));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_IAM_UNAVAILABLE',
    '无 SandIAM：应用解析返回依赖不可用而非 500');

$problem = problemOf(static fn () => (new AdminTrialService())->options(1, 1, null));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_IAM_UNAVAILABLE',
    '无 SandIAM：options 端点优雅降级为中文不可用提示');

// --- 每管理员限频 -------------------------------------------------------------
$adminId = 987654;
AdminTrialRateLimiter::reset();
$limited = false;
for ($i = 0; $i < AdminTrialRateLimiter::maxIssues() + 2; $i++) {
    $problem = problemOf(static fn () => AdminTrialRateLimiter::assertAllowed($adminId));
    if ($problem !== null) {
        $limited = $problem['code'] === 'SAND_AI_TRIAL_RATE_LIMITED';
        break;
    }
}
ok($limited, '限频：同一管理员超出窗口上限后拒绝');
AdminTrialRateLimiter::reset();

// --- 服务目录声明元数据 --------------------------------------------------------
$reflection = new ReflectionClass(HostSandIamServiceCatalog::class);
$service = HostSandIamServiceCatalog::serviceDeclaration();
ok(($service['code'] ?? null) === 'sand_ai'
    && ($service['name'] ?? null) === 'SandAI'
    && ($service['audience'] ?? null) === 'sand-ai'
    && hasCjk((string) ($service['group_name'] ?? '')),
    '目录声明：service 携带中文 group_name 与契约 audience');

$groups = ['ACTIONS', 'SKILL_ACTIONS', 'AUDIO_ACTIONS'];
$allCodes = [];
foreach ($groups as $group) {
    $constant = $reflection->getConstant($group);
    ok(is_array($constant) && $constant !== [], '目录声明：' . $group . ' 非空');
    foreach (array_keys($constant) as $code) {
        $allCodes[] = $code;
    }
}
$details = $reflection->getConstant('ACTION_DETAILS');
ok(is_array($details), '目录声明：ACTION_DETAILS 存在');
$missing = [];
foreach ($allCodes as $code) {
    $description = is_array($details) ? ($details[$code] ?? null) : null;
    if (!is_string($description) || !hasCjk($description)) {
        $missing[] = $code;
    }
}
ok($missing === [], '目录声明：全部动作具备中文 description' . ($missing === [] ? '' : '（缺 ' . implode(',', $missing) . '）'));

$declarations = HostSandIamServiceCatalog::actionDeclarations(['sand_ai.chat.complete' => 'AI 对话', 'sand_ai.custom' => '自定义']);
ok(($declarations['sand_ai.chat.complete']['description'] ?? null) === '和 AI 对话'
    && ($declarations['sand_ai.custom']['description'] ?? null) === '自定义'
    && ($declarations['sand_ai.chat.complete']['name'] ?? null) === 'AI 对话',
    '目录声明：actionDeclarations 输出 name+description 且缺说明回退名称');

// --- 生产 register/inspect 载荷形状 ------------------------------------------------
// RichServiceCatalogPortFake carries the private MAX_DESCRIPTION constant and
// optionalAudience() helper that only exist in the sand-iam >=0.9.7
// ServiceCatalog, so HostSandIamServiceCatalog detects it as a rich port.
trait ServiceCatalogPortFakeCalls
{
    /** @var list<array{service:array<string,mixed>,actions:array<string,mixed>}> */
    public array $inspections = [];
    /** @var list<array{service:array<string,mixed>,actions:array<string,mixed>}> */
    public array $registrations = [];

    public function inspectServiceActions(array $service, array $actions): array
    {
        $this->inspections[] = ['service' => $service, 'actions' => $actions];
        return [
            'service_code' => $service['code'],
            'service_status' => 'missing',
            'action_statuses' => array_fill_keys(array_keys($actions), 'missing'),
            'can_register' => true,
        ];
    }

    public function registerServiceActions(array $service, array $actions): void
    {
        $this->registrations[] = ['service' => $service, 'actions' => $actions];
    }
}

final class RichServiceCatalogPortFake
{
    use ServiceCatalogPortFakeCalls;

    private const MAX_DESCRIPTION = 512;

    private function optionalAudience(mixed $value): mixed
    {
        return $value;
    }
}

final class LegacyServiceCatalogPortFake
{
    use ServiceCatalogPortFakeCalls;
}

$richPort = new RichServiceCatalogPortFake();
$richCatalog = new HostSandIamServiceCatalog($richPort);
$preflight = $richCatalog->preflight();
ok($preflight['port_available'] === true && $preflight['can_register'] === true,
    '富端口：preflight 巡检可用且可注册');
$registered = $richCatalog->register();
ok($registered['registered'] === true && $registered['service_code'] === 'sand_ai',
    '富端口：register 返回已登记');

$richInspectionsRich = $richPort->inspections !== [];
foreach ($richPort->inspections as $call) {
    $chat = $call['actions']['sand_ai.chat.complete'] ?? null;
    if (($call['service']['group_name'] ?? null) !== 'AI 能力'
        || ($call['service']['audience'] ?? null) !== 'sand-ai'
        || !is_array($chat) || ($chat['description'] ?? null) !== '和 AI 对话') {
        $richInspectionsRich = false;
    }
}
ok($richInspectionsRich, '富端口：全部 inspect 载荷携带 group_name/audience 与动作 description');

$registerPayload = $richPort->registrations[0] ?? null;
$registerChat = is_array($registerPayload) ? ($registerPayload['actions']['sand_ai.chat.complete'] ?? null) : null;
ok(is_array($registerPayload)
    && ($registerPayload['service']['group_name'] ?? null) === 'AI 能力'
    && ($registerPayload['service']['audience'] ?? null) === 'sand-ai'
    && is_array($registerChat)
    && ($registerChat['name'] ?? null) === 'AI 对话'
    && ($registerChat['description'] ?? null) === '和 AI 对话',
    '富端口：register 载荷携带富 service 声明与动作 name+description');

$legacyPort = new LegacyServiceCatalogPortFake();
$legacyCatalog = new HostSandIamServiceCatalog($legacyPort);
$legacyResult = $legacyCatalog->register();
$legacyPayload = $legacyPort->registrations[0] ?? null;
ok($legacyResult['registered'] === true
    && is_array($legacyPayload)
    && ($legacyPayload['service'] ?? null) === ['code' => 'sand_ai', 'name' => 'SandAI']
    && ($legacyPayload['actions']['sand_ai.chat.complete'] ?? null) === 'AI 对话',
    '旧端口：register 载荷保持 code/name 与 code=>name 旧形状');
$legacyInspectRich = false;
foreach ($legacyPort->inspections as $call) {
    if (($call['service']['audience'] ?? null) === 'sand-ai'
        || is_array($call['actions']['sand_ai.chat.complete'] ?? null)) {
        $legacyInspectRich = true;
    }
}
ok($legacyInspectRich === false, '旧端口：inspect 载荷不携带富声明字段');

echo "----\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
