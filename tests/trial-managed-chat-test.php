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
require_once dirname(__DIR__) . '/app/Api/Support/IdentityContextProblem.php';
require_once dirname(__DIR__) . '/app/Identity/Contract/IdentityContext.php';
require_once dirname(__DIR__) . '/app/Identity/Contract/IdentityContextException.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptions.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptionsPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/RequestIdempotencyKey.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialRateLimiter.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamRuntime.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamErrorMapper.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HttpStreamAuthorizationScope.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamInvocationAuthorizer.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/ChatInvocationAccessGuard.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/ChatInvocationFacts.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/InvocationReadFacts.php';
require_once dirname(__DIR__) . '/app/Application/Usage/UsageAttribution.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialIamGateway.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialService.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamServiceCatalog.php';
require_once __DIR__ . '/stubs/SupportRequest.php';

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Trial\AdminTrialIamGateway;
use app\Application\Trial\AdminTrialRateLimiter;
use app\Application\Trial\AdminTrialService;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use app\Identity\Infrastructure\ChatInvocationFacts;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\InvocationReadFacts;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;
use support\Request as SupportRequest;

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

// --- D-C3-01：试用授权显式使用签发 context，不读 HTTP 头 -------------------
// 录制型 host authorizer：HostSandIamInvocationAuthorizer 对 host 端口做
// method_exists 鸭子类型探测，注入假实现即可在无 SandIAM/DB 下断言
// 「哪个签名上下文、哪个动作、哪个资源键」真正进入授权链。
final class RecordingHostInvocationAuthorizer
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct(private readonly IdentityContext $identity)
    {
    }

    /** @return array<string,mixed> */
    private function decision(string $actionCode, string $audience, string $operationId): array
    {
        return [
            'authorization_id' => 101,
            'grant_id' => 55,
            'context_id' => 'ctx-recording-1',
            'replayed' => false,
            'data_class' => null,
            'operation_id' => $operationId,
            'service_code' => 'sand_ai',
            'audience' => $audience,
            'action_code' => $actionCode,
            'organization_id' => (int) $this->identity->organizationId,
            'application_id' => (int) $this->identity->applicationId,
            'environment_id' => $this->identity->environmentId,
            'workload_client_id' => (int) $this->identity->workloadClientId,
        ];
    }

    /** @return array<string,mixed> */
    public function authorizeInvocation(
        string $signedContext, string $serviceCode, string $audience, string $actionCode,
        string $resolverCode, string $resourceKey, string $sourceIp, string $operationId, string $auditRequestId,
    ): array {
        $this->calls[] = [
            'kind' => 'authorize',
            'signed_context' => $signedContext,
            'service_code' => $serviceCode,
            'audience' => $audience,
            'action_code' => $actionCode,
            'resolver_code' => $resolverCode,
            'resource_key' => $resourceKey,
            'source_ip' => $sourceIp,
            'operation_id' => $operationId,
        ];
        return $this->decision($actionCode, $audience, $operationId);
    }

    /** @return array<string,mixed> */
    public function revalidateInvocation(
        int $authorizationId, string $signedContext, string $serviceCode, string $audience,
        string $actionCode, string $resolverCode, string $resourceKey, string $sourceIp, string $auditRequestId,
    ): array {
        $this->calls[] = [
            'kind' => 'revalidate',
            'authorization_id' => $authorizationId,
            'signed_context' => $signedContext,
        ];
        return $this->decision($actionCode, $audience, 'chat-invocation:revalidate');
    }
}

$trialIdentity = new IdentityContext('7', '9', 3, '11', 'sand-ai', ['sand_ai.chat.complete'], time() + 300);
$trialRequestId = 'atrial-chat:1:0123456789abcdef';
$trialHost = new RecordingHostInvocationAuthorizer($trialIdentity);
$trialGuard = new ChatInvocationAccessGuard(new HostSandIamInvocationAuthorizer($trialHost));

// 试用链路：trialAuthorizeCallback 产出的派发前回调必须把签发的 workload
// context（而非管理员会话头）交给 SandIAM authorizeInvocation 全链复核。
$authorizeTrial = AdminTrialService::trialAuthorizeCallback(
    $trialGuard, 'Bearer issued.workload.context', '203.0.113.10', $trialIdentity, $trialRequestId,
);
$authorizeTrial(42);
ok(count($trialHost->calls) === 1
    && $trialHost->calls[0]['kind'] === 'authorize'
    && $trialHost->calls[0]['signed_context'] === 'issued.workload.context'
    && $trialHost->calls[0]['action_code'] === 'sand_ai.chat.complete'
    && $trialHost->calls[0]['resolver_code'] === 'sand_ai.chat_invocation'
    && $trialHost->calls[0]['resource_key'] === '42'
    && $trialHost->calls[0]['source_ip'] === '203.0.113.10',
    'D-C3-01：试用回调把签发 context 显式交给 SandIAM 授权（resource=invocation id）');

$problem = problemOf(static function () use ($trialGuard, $trialIdentity, $trialRequestId): void {
    $trialGuard->authorizeSigned('issued-no-bearer-prefix', '203.0.113.10', $trialIdentity, 43, $trialRequestId);
});
ok($problem !== null && $problem['code'] === 'SAND_AI_AUTHENTICATION_FAILED',
    'D-C3-01：缺 Bearer 前缀的 context 在授权层被拒绝');

// 公开 API 路径不变：authorize() 仍从 HTTP Authorization 头取上下文，并保留
// x-request-id 与服务端 requestId 的一致性约束。
$publicRequest = new SupportRequest([
    'authorization' => 'Bearer public.workload.context',
    'x-request-id' => $trialRequestId,
]);
$trialGuard->authorize($publicRequest, $trialIdentity, 44, $trialRequestId);
ok(count($trialHost->calls) === 2
    && $trialHost->calls[1]['signed_context'] === 'public.workload.context'
    && $trialHost->calls[1]['resource_key'] === '44',
    '公开路径：authorize() 仍从 HTTP 头取签名上下文');

$mismatchedRequest = new SupportRequest([
    'authorization' => 'Bearer public.workload.context',
    'x-request-id' => 'otherrequestid01',
]);
$problem = problemOf(static fn () => $trialGuard->authorize($mismatchedRequest, $trialIdentity, 45, $trialRequestId));
ok($problem !== null && $problem['code'] === 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE',
    '公开路径：x-request-id 与服务端 requestId 不一致仍被拒绝');

// --- D-C3-02：options 模型名投影收集模型 id 而非位置序号 ---------------------
$projection = new ReflectionMethod(AdminTrialService::class, 'projectedModelIds');
$projection->setAccessible(true);
$ids = $projection->invoke(null, [
    1 => ['models' => [10 => 1, 42 => 2]],
    2 => ['models' => [7 => 1]],
]);
ok($ids === [10, 42, 7], 'D-C3-02：多服务商多模型投影返回真实模型 id（非位置序号）');
$ids = $projection->invoke(null, [5 => ['models' => [99 => 1]]]);
ok($ids === [99], 'D-C3-02：单服务商单模型投影返回模型 id');
$ids = $projection->invoke(null, [3 => ['models' => []], 4 => ['models' => [8 => 1]]]);
ok($ids === [8], 'D-C3-02：空模型集服务商不产生伪 id');
$ids = $projection->invoke(null, []);
ok($ids === [], 'D-C3-02：空投影返回空集');

// --- D-C4-01：试用 source_kind 与调用事实白名单一致 -------------------------
// 真实宿主实测：dispatch 落库 source_kind='admin_trial' 而
// ChatInvocationFacts 只接受 'chat'，导致 SAND_AI_INVOCATION_FACTS_UNVERIFIED。
// 这里用同一归因对象驱动 facts 校验，证明「服务写入的 source_kind」与
// 「facts 接受的 source_kind」一致。
$trialAttribution = AdminTrialService::chatAttribution($trialIdentity);
ok($trialAttribution->sourceKind === 'chat'
    && $trialAttribution->serviceAction === 'sand_ai.chat.complete'
    && $trialAttribution->capabilityCode === 'chat',
    'D-C4-01：试用归因为 source_kind=chat（与公开 chat 派发同源）');

// ChatGateway 对 publicChat scope 落库的 dispatch/usage 维度（无 DB，用持久化
// 形状数组直接喂 facts 纯投影）。
$chatFactsInvocation = [
    'id' => 42,
    'status' => 1,
    'delete_time' => null,
    'organization_id' => 7,
    'application_id' => 9,
    'environment_id' => 3,
    'workload_client_id' => 11,
    'data_class' => null,
    'knowledge_publication_id' => null,
    'knowledge_base_version_id' => null,
    'knowledge_source_scope_sha256' => null,
];
$chatFactsDispatch = [
    'invocation_id' => 42,
    'environment_id' => 3,
    'application_id' => 9,
    'execution_scope' => 'chat',
    'source_kind' => $trialAttribution->sourceKind,
    'service_action' => $trialAttribution->serviceAction,
];

$chatFacts = ChatInvocationFacts::fromPersisted($chatFactsInvocation, $chatFactsDispatch, 42);
ok(($chatFacts['resource_type'] ?? null) === 'sand_ai.chat_invocation'
    && ($chatFacts['resource_ref'] ?? null) === '42'
    && ($chatFacts['workload_client_id'] ?? null) === 11
    && ($chatFacts['environment_id'] ?? null) === 3,
    'D-C4-01：试用归因 source_kind 通过 ChatInvocationFacts 校验（dispatch 落库值=facts 接受值）');

$readFacts = InvocationReadFacts::fromPersisted($chatFactsInvocation, $chatFactsDispatch, 42);
ok(($readFacts['resource_type'] ?? null) === 'sand_ai.invocation'
    && ($readFacts['resource_ref'] ?? null) === '42',
    'D-C4-01：同一 dispatch 形状通过 InvocationReadFacts（结果/用量读取）校验');

// 对照：修复前的 'admin_trial' 必须仍被拒绝，证明白名单未被放宽、缺陷语义未变。
$rejectedDispatch = $chatFactsDispatch;
$rejectedDispatch['source_kind'] = 'admin_trial';
$problem = problemOf(static fn () => ChatInvocationFacts::fromPersisted($chatFactsInvocation, $rejectedDispatch, 42));
ok($problem !== null && $problem['message'] === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED',
    'D-C4-01：旧值 admin_trial 仍被 facts 白名单拒绝（缺陷对照）');
$problem = problemOf(static fn () => InvocationReadFacts::fromPersisted($chatFactsInvocation, $rejectedDispatch, 42));
ok($problem !== null && $problem['message'] === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED',
    'D-C4-01：旧值 admin_trial 在读取 facts 同样被拒绝（缺陷对照）');

// --- A5：Idempotency-Key 签发前预检 -----------------------------------------
// preflight() 与 required() 共用同一校验规则，但不需要已签发的 identity
// context，因此 chat() 可在 SandIAM 签发凭证/写审计之前廉价拒绝缺失或畸形键。
$preflightOk = new SupportRequest(['idempotency-key' => 'trial-key-0001']);
$problem = problemOf(static fn () => RequestIdempotencyKey::preflight($preflightOk));
ok($problem === null, 'A5：合法 Idempotency-Key 通过签发前预检');

foreach ([[], ['idempotency-key' => '   '], ['idempotency-key' => 'bad key'], ['idempotency-key' => str_repeat('k', 513)]] as $index => $headers) {
    $problem = problemOf(static fn () => RequestIdempotencyKey::preflight(new SupportRequest($headers)));
    ok($problem !== null && $problem['code'] === 'SAND_AI_IDEMPOTENCY_KEY_REQUIRED',
        'A5：缺失/畸形 Idempotency-Key 在签发前被拒 #' . $index);
}

// --- A10：试用幂等键按管理员隔离 ----------------------------------------------
// 受管 workload client 由同一应用的所有管理员共享，derived key 必须额外
// 绑定管理员身份，否则两个管理员复用同一 Idempotency-Key 会命中同一请求指纹。
$trialKeyAdmin7 = RequestIdempotencyKey::fromCallerKey('shared-idem-key', 'chat', $trialIdentity, 'admin:7');
$trialKeyAdmin7Replay = RequestIdempotencyKey::fromCallerKey('shared-idem-key', 'chat', $trialIdentity, 'admin:7');
$trialKeyAdmin8 = RequestIdempotencyKey::fromCallerKey('shared-idem-key', 'chat', $trialIdentity, 'admin:8');
$trialKeyLegacy = RequestIdempotencyKey::fromCallerKey('shared-idem-key', 'chat', $trialIdentity);
ok($trialKeyAdmin7 === $trialKeyAdmin7Replay
    && $trialKeyAdmin7 !== $trialKeyAdmin8
    && $trialKeyAdmin7 !== $trialKeyLegacy
    && $trialKeyLegacy === RequestIdempotencyKey::fromCallerKey('shared-idem-key', 'chat', $trialIdentity, ''),
    'A10：同管理员幂等键稳定重放，跨管理员与通用派生互不冲突');

// --- A12：签发失败按稳定错误码分类 --------------------------------------------
// mapIssueFailure 只认消息开头的 SAND_IAM_* 码 token（与
// HostSandIamErrorMapper 同一契约），松散子串不再误分类。
$mapFailure = new ReflectionMethod(AdminTrialIamGateway::class, 'mapIssueFailure');
$mapFailure->setAccessible(true);
$gateway = new AdminTrialIamGateway();

$denied = $mapFailure->invoke($gateway, new RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: ambiguous service action grant'));
ok($denied instanceof ApiProblem && $denied->errorCode === 'SAND_AI_TRIAL_NOT_GRANTED',
    'A12：动作授权拒绝按稳定码映射为未开通');
$revoked = $mapFailure->invoke($gateway, new RuntimeException('SAND_IAM_CREDENTIAL_REVOKED'));
ok($revoked instanceof ApiProblem && $revoked->errorCode === 'SAND_AI_TRIAL_NOT_GRANTED',
    'A12：凭证吊销按稳定码映射为未开通');
$network = $mapFailure->invoke($gateway, new RuntimeException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN'));
ok($network instanceof ApiProblem && $network->errorCode === 'SAND_AI_TRIAL_ISSUE_NETWORK_FORBIDDEN',
    'A12：网络策略拒绝独立成码，不再误报为未开通');
$looseForbidden = $mapFailure->invoke($gateway, new RuntimeException('upstream said FORBIDDEN somewhere'));
ok($looseForbidden instanceof ApiProblem && $looseForbidden->errorCode === 'SAND_AI_TRIAL_ISSUE_FAILED'
    && $looseForbidden->retryable === true,
    'A12：非码前缀的 FORBIDDEN 字样回落为可重试签发失败');
$midToken = $mapFailure->invoke($gateway, new RuntimeException('prefix SAND_IAM_SERVICE_ACTION_FORBIDDEN'));
ok($midToken instanceof ApiProblem && $midToken->errorCode === 'SAND_AI_TRIAL_ISSUE_FAILED',
    'A12：码 token 不在开头时不参与分类');

// --- A8：试用授权拒绝写 SandAI 本地审计 ---------------------------------------
// 网关是 final 且依赖 SandIAM 插件，完整链路由协调仓库的夹具套件覆盖；
// 这里直接驱动审计助手，断言只写安全字段且可用性故障不产生审计行。
$deniedRows = [];
$deniedAuditSink = static function (array $entry) use (&$deniedRows): void {
    $deniedRows[] = $entry;
};
$trialService = new AdminTrialService(null, null, $deniedAuditSink);
$auditDenied = new ReflectionMethod(AdminTrialService::class, 'auditDeniedAccess');
$auditDenied->setAccessible(true);

$auditDenied->invoke($trialService, 7, 42, 'trial.chat',
    new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能'), 55);
ok(count($deniedRows) === 1
    && $deniedRows[0]['actor_type'] === 'admin'
    && $deniedRows[0]['actor_ref'] === '7'
    && $deniedRows[0]['action'] === 'admin_trial.access.denied'
    && $deniedRows[0]['resource_type'] === 'application'
    && $deniedRows[0]['resource_id'] === 42
    && ($deniedRows[0]['context']['endpoint'] ?? null) === 'trial.chat'
    && ($deniedRows[0]['context']['reason_code'] ?? null) === 'SAND_AI_TRIAL_NOT_GRANTED'
    && ($deniedRows[0]['context']['workload_client_id'] ?? null) === 55,
    'A8：授权拒绝写入 SandAI 审计且只携带安全上下文字段');

$deniedRows = [];
$auditDenied->invoke($trialService, 7, 42, 'trial.chat',
    new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', 'SandIAM 不可用'), 55);
$auditDenied->invoke($trialService, 7, 42, 'trial.chat',
    new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '签发失败', true), 55);
ok($deniedRows === [], 'A8：可用性故障不写授权拒绝审计');

echo "----\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
