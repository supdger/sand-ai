<?php

declare(strict_types=1);

/**
 * Isolated egress-policy tests: no real DNS, no network, no keys.
 *
 * A fixture HostResolver injects resolved addresses (fake DNS) and a fixture
 * transport records outbound attempts, so DNS multi-answer, rebinding, the
 * administrator CIDR allowlist and the redirect boundary are exercised without
 * touching the network or any AI provider.
 *
 * Usage: php tests/egress-policy-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/HostResolver.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/VerifiedEndpoint.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/EgressAllowlist.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/PublicAddressPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/ProviderConnectionPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OnboardingHttpResponse.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OnboardingHttpTransport.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/RemoteModelIdentifier.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OpenAiCompatibleOnboardingClient.php';
require_once dirname(__DIR__) . '/app/Application/Channel/ChannelApiBase.php';
require_once dirname(__DIR__) . '/app/Application/Channel/ChannelApiBasePolicy.php';
require_once dirname(__DIR__) . '/app/Application/Channel/FeishuMessageClient.php';
require_once dirname(__DIR__) . '/app/Application/Channel/WecomMessageClient.php';

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelApiBasePolicy;
use app\Application\Channel\FeishuMessageClient;
use app\Application\Channel\WecomMessageClient;
use app\Application\Onboarding\EgressAllowlist;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\OnboardingHttpResponse;
use app\Application\Onboarding\OnboardingHttpTransport;
use app\Application\Onboarding\OpenAiCompatibleOnboardingClient;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\PublicAddressPolicy;
use app\Application\Onboarding\VerifiedEndpoint;

/** Webman \Log facade stand-in: the WeCom client logs transport facts. */
final class Log
{
    /** @param mixed ...$args */
    public static function error(mixed ...$args): void
    {
    }
}

final class FixtureResolver implements HostResolver
{
    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}

final class FixtureTransport implements OnboardingHttpTransport
{
    public int $calls = 0;

    public function __construct(private readonly OnboardingHttpResponse $response)
    {
    }

    public function request(string $method, VerifiedEndpoint $endpoint, string $path, array $headers, ?array $json, int $timeoutMs): OnboardingHttpResponse
    {
        $this->calls++;

        return $this->response;
    }
}

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

/** @return array{code:string,details:array<string,mixed>}|null */
function problemOf(callable $run): ?array
{
    try {
        $run();
    } catch (ApiProblem $problem) {
        return ['code' => $problem->errorCode, 'details' => $problem->details];
    } catch (Throwable $exception) {
        return ['code' => get_class($exception), 'details' => []];
    }

    return null;
}

/** @return array<string,mixed> */
function connection(string $baseUrl = 'https://open.example.test'): array
{
    return ['base_url' => $baseUrl, 'api_key' => 'fixture-key'];
}

function policy(array $addresses, ?EgressAllowlist $allowlist): ProviderConnectionPolicy
{
    return new ProviderConnectionPolicy(new FixtureResolver($addresses), $allowlist);
}

// --- 默认阻断 -------------------------------------------------------------
$problem = problemOf(static function (): void {
    policy(['198.18.0.123'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null
    && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN'
    && ($problem['details']['reason_code'] ?? null) === 'resolved_address_blocked'
    && ($problem['details']['service_host'] ?? null) === 'open.example.test'
    && ($problem['details']['blocked_range'] ?? null) === '198.18.0.0/15'
    && !isset($problem['details']['blocked_address']),
    '默认：公网域解析进 198.18.0.0/15 仍被阻断并带结构化标记（不回显解析 IP）');

$problem = problemOf(static function (): void {
    policy(['127.0.0.1'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '默认：回环地址解析被阻断');

$problem = problemOf(static function (): void {
    policy(['169.254.169.254'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '默认：元数据网段解析被阻断');

$problem = problemOf(static function (): void {
    policy(['10.1.2.3'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '默认：私网地址解析被阻断');

$problem = problemOf(static function (): void {
    policy(['8.8.8.8'], EgressAllowlist::fromString(''))->normalize(connection('https://198.18.0.1'));
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN'
    && $problem['details'] === [],
    'IP 字面量输入被拒绝且不携带解析阻断标记');

// --- 精确授权放行 ---------------------------------------------------------
try {
    $normalized = policy(['198.18.0.123'], EgressAllowlist::fromString('198.18.0.0/15'))->normalize(connection());
    ok($normalized['endpoint'] instanceof VerifiedEndpoint
        && $normalized['endpoint']->connectionAddress() === '198.18.0.123',
        '授权：管理员列入 198.18.0.0/15 后该地址放行');
} catch (Throwable) {
    ok(false, '授权：管理员列入 198.18.0.0/15 后该地址放行');
}

$problem = problemOf(static function (): void {
    policy(['198.18.0.123'], EgressAllowlist::fromString('198.19.0.0/16'))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '授权：邻近但未列入的网段不放行');

$problem = problemOf(static function (): void {
    policy(['10.9.9.9'], EgressAllowlist::fromString('198.18.0.0/15'))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '授权：私网网段未被列入时仍阻断');

try {
    policy(['10.9.9.9'], EgressAllowlist::fromString('10.0.0.0/8'))->normalize(connection());
    ok(true, '授权：管理员明确列入私网网段后对应地址放行');
} catch (Throwable) {
    ok(false, '授权：管理员明确列入私网网段后对应地址放行');
}

try {
    policy(['198.18.0.123'], EgressAllowlist::fromString('8.8.8.0/24, 198.18.0.0/15'))->normalize(connection());
    ok(true, '授权：多条目逗号分隔的放行名单');
} catch (Throwable) {
    ok(false, '授权：多条目逗号分隔的放行名单');
}

try {
    policy(['198.18.0.123'], EgressAllowlist::fromString('198.18.0.123/15'))->normalize(connection());
    ok(true, '授权：带主机位的 CIDR 规范化后仍按网段放行');
} catch (Throwable) {
    ok(false, '授权：带主机位的 CIDR 规范化后仍按网段放行');
}

// --- 非法 CIDR 拒绝 --------------------------------------------------------
foreach (['not-a-cidr', '10.0.0.0/33', '10.0.0.0', '10.0.0.0/-1', '999.1.1.1/8', '::1/129', '10.0.0.0/x'] as $bad) {
    $problem = problemOf(static function () use ($bad): void {
        EgressAllowlist::fromString($bad);
    });
    ok($problem !== null && $problem['code'] === 'SAND_AI_EGRESS_ALLOWLIST_INVALID',
        '非法 CIDR 拒绝：' . $bad);
}

$problem = problemOf(static function (): void {
    EgressAllowlist::fromString('198.18.0.0/15, garbage');
});
ok($problem !== null && $problem['code'] === 'SAND_AI_EGRESS_ALLOWLIST_INVALID',
    '非法 CIDR 拒绝：混合列表中任一无效条目整体拒绝');

// --- DNS 多地址与重绑定 ----------------------------------------------------
$problem = problemOf(static function (): void {
    policy(['8.8.8.8', '198.18.0.5'], EgressAllowlist::fromString('198.18.0.0/15'))->normalize(connection());
});
ok($problem === null, '多地址：全部地址均被放行或通过时连接成立');

$problem = problemOf(static function (): void {
    policy(['8.8.8.8', '10.1.1.1'], EgressAllowlist::fromString('198.18.0.0/15'))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    '多地址：任一未授权保留地址混入即整体阻断');

$normalized = policy(['8.8.8.8'], EgressAllowlist::fromString(''))->normalize(connection());
ok($normalized['endpoint']->curlResolveEntry() === 'open.example.test:443:8.8.8.8',
    '重绑定：连接固定到本次解析的地址，不重新解析');

$problem = problemOf(static function (): void {
    policy([], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_DNS_UNAVAILABLE',
    'DNS 无记录仍是独立错误');

// --- IPv6 边界 -------------------------------------------------------------
$problem = problemOf(static function (): void {
    policy(['::1'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    'IPv6：回环解析被阻断');

$problem = problemOf(static function (): void {
    policy(['2001:db8::1'], EgressAllowlist::fromString(''))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    'IPv6：文档段解析被阻断');

try {
    policy(['2606:4700:4700::1111'], EgressAllowlist::fromString(''))->normalize(connection());
    ok(true, 'IPv6：公网地址默认放行');
} catch (Throwable) {
    ok(false, 'IPv6：公网地址默认放行');
}

$problem = problemOf(static function (): void {
    policy(['::1'], EgressAllowlist::fromString('2001:db8::/32'))->normalize(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    'IPv6：放行名单按地址族匹配，IPv4 段不误放 IPv6 地址');

// --- A11：阻断详情只回网段标识，不回显解析地址 ------------------------------
ok(PublicAddressPolicy::blockedRange('198.18.0.123') === '198.18.0.0/15'
    && PublicAddressPolicy::blockedRange('10.1.2.3') === '10.0.0.0/8'
    && PublicAddressPolicy::blockedRange('169.254.169.254') === '169.254.0.0/16'
    && PublicAddressPolicy::blockedRange('::1') === 'non_global_unicast'
    && PublicAddressPolicy::blockedRange('2001:db8::1') === '2001:db8::/32'
    && PublicAddressPolicy::blockedRange('8.8.8.8') === null
    && PublicAddressPolicy::blockedRange('2606:4700:4700::1111') === null
    && PublicAddressPolicy::isPublic('8.8.8.8') === true
    && PublicAddressPolicy::isPublic('198.18.0.123') === false
    && PublicAddressPolicy::isPublic('not-an-ip') === false,
    'A11：blockedRange 给出网段标识且 isPublic 语义与原分类一致');

// --- 重定向边界 -------------------------------------------------------------
$transport = new FixtureTransport(new OnboardingHttpResponse(302, '{"location":"https://internal.example.test"}'));
$client = new OpenAiCompatibleOnboardingClient(policy(['8.8.8.8'], EgressAllowlist::fromString('')), $transport);
$problem = problemOf(static function () use ($client): void {
    $client->discover(connection());
});
ok($problem !== null && $problem['code'] === 'SAND_AI_PROVIDER_REJECTED' && $transport->calls === 1,
    '重定向：上游 3xx 不被跟随且只发一次请求');

$transport = new FixtureTransport(new OnboardingHttpResponse(200, '{"data":[{"id":"fixture-model"}]}'));
$client = new OpenAiCompatibleOnboardingClient(policy(['198.18.0.7'], EgressAllowlist::fromString('198.18.0.0/15')), $transport);
try {
    $models = $client->discover(connection());
    ok(count($models) === 1 && ($models[0]['remote_model'] ?? null) === 'fixture-model' && $transport->calls === 1,
        '放行名单下发现请求发往已校验端点');
} catch (Throwable) {
    ok(false, '放行名单下发现请求发往已校验端点');
}

// --- A7：渠道 api_base 出站校验 ---------------------------------------------
function channelPolicy(array $addresses, ?EgressAllowlist $allowlist): ChannelApiBasePolicy
{
    return new ChannelApiBasePolicy(new FixtureResolver($addresses), $allowlist);
}

$endpoint = channelPolicy(['8.8.8.8'], EgressAllowlist::fromString(''))
    ->verify(null, 'https://open.feishu.cn');
ok($endpoint->baseUrl === 'https://open.feishu.cn'
    && $endpoint->curlResolveEntry() === 'open.feishu.cn:443:8.8.8.8',
    '渠道：缺省 api_base 回落官方地址并固定到已校验解析地址');

$endpoint = channelPolicy(['8.8.8.8'], EgressAllowlist::fromString(''))
    ->verify('https://feishu.corp.example.com:8443/', 'https://open.feishu.cn');
ok($endpoint->baseUrl === 'https://feishu.corp.example.com:8443'
    && $endpoint->curlResolveEntry() === 'feishu.corp.example.com:8443:8.8.8.8',
    '渠道：私有部署 api_base 允许 8443 端口并按实际端口固定解析');

foreach ([
    'https://192.168.1.10',
    'https://169.254.169.254',
    'https://[::1]',
    'https://localhost',
    'https://api.localhost',
    'https://metadata.google.internal',
    'https://feishu.corp.example.com:8080',
    'https://user:pass@feishu.corp.example.com',
    'http://feishu.corp.example.com',
] as $index => $badBase) {
    $problem = problemOf(static function () use ($badBase): void {
        channelPolicy(['8.8.8.8'], EgressAllowlist::fromString(''))->verify($badBase, 'https://open.feishu.cn');
    });
    ok($problem !== null
        && in_array($problem['code'], ['SAND_AI_CHANNEL_API_BASE_FORBIDDEN', 'SAND_AI_CHANNEL_DELIVERY_INVALID'], true),
        '渠道：非法 api_base 被拒 #' . $index . '（' . $badBase . '）');
}

$problem = problemOf(static function (): void {
    channelPolicy(['10.0.0.5'], EgressAllowlist::fromString(''))->verify('https://feishu.corp.example.com', 'https://open.feishu.cn');
});
ok($problem !== null
    && $problem['code'] === 'SAND_AI_CHANNEL_API_BASE_FORBIDDEN'
    && ($problem['details']['reason_code'] ?? null) === 'channel_api_base_blocked'
    && ($problem['details']['api_host'] ?? null) === 'feishu.corp.example.com'
    && ($problem['details']['blocked_range'] ?? null) === '10.0.0.0/8'
    && !isset($problem['details']['blocked_address'])
    && !in_array('10.0.0.5', $problem['details'], true),
    '渠道：公网域解析进私网被阻断且不回显解析 IP');

$endpoint = channelPolicy(['10.0.0.5'], EgressAllowlist::fromString('10.0.0.0/8'))
    ->verify('https://feishu.corp.example.com', 'https://open.feishu.cn');
ok($endpoint->connectionAddress() === '10.0.0.5',
    '渠道：显式渠道放行名单列入私网网段后私有部署放行');

$problem = problemOf(static function (): void {
    channelPolicy(['10.0.0.5'], EgressAllowlist::fromString('198.18.0.0/15'))
        ->verify('https://feishu.corp.example.com', 'https://open.feishu.cn');
});
ok($problem !== null && $problem['code'] === 'SAND_AI_CHANNEL_API_BASE_FORBIDDEN',
    '渠道：未列入的私网网段不放行（渠道名单独立于服务商名单）');

$problem = problemOf(static function (): void {
    channelPolicy([], EgressAllowlist::fromString(''))->verify('https://feishu.corp.example.com', 'https://open.feishu.cn');
});
ok($problem !== null && $problem['code'] === 'SAND_AI_CHANNEL_API_BASE_DNS_UNAVAILABLE',
    '渠道：DNS 无记录是独立可重试错误');

// --- A7：消息客户端在携带凭据前执行同一出口闸门 ------------------------------
$feishuCalls = 0;
$feishuPost = static function (string $url, array $headers, array $body) use (&$feishuCalls): array {
    $feishuCalls++;
    if (str_contains($url, 'tenant_access_token')) {
        return ['status' => 200, 'body' => ['code' => 0, 'tenant_access_token' => 'fixture-token']];
    }
    return ['status' => 200, 'body' => ['code' => 0, 'data' => ['message_id' => 'om_fixture_1']]];
};
$feishu = new FeishuMessageClient($feishuPost, channelPolicy(['8.8.8.8'], EgressAllowlist::fromString('')));
$sent = $feishu->sendText('fixture-app', 'fixture-secret', 'oc_fixture', '你好',
    '01234567-89ab-cdef-0123-456789abcdef', null, 'https://feishu.corp.example.com');
ok($sent['message_id'] === 'om_fixture_1' && $feishuCalls === 2,
    '渠道：合法 api_base 下飞书客户端完成令牌与消息两次调用');

$feishuCalls = 0;
$problem = problemOf(static function () use ($feishuPost, &$feishuCalls): void {
    (new FeishuMessageClient($feishuPost, channelPolicy(['169.254.169.254'], EgressAllowlist::fromString(''))))
        ->sendText('fixture-app', 'fixture-secret', 'oc_fixture', '你好',
            '01234567-89ab-cdef-0123-456789abcdef', null, 'https://feishu.corp.example.com');
});
ok($problem !== null && $problem['code'] === 'SAND_AI_CHANNEL_API_BASE_FORBIDDEN' && $feishuCalls === 0,
    '渠道：被拦 api_base 在携带 app_secret 前被拒绝，传输零调用');

$wecomCalls = 0;
$wecomRequest = static function (string $url, ?array $body, bool $messagePost) use (&$wecomCalls): array {
    $wecomCalls++;
    if (str_contains($url, 'gettoken')) {
        return ['errcode' => 0, 'access_token' => 'fixture-token'];
    }
    return ['errcode' => 0];
};
$wecom = new WecomMessageClient($wecomRequest, channelPolicy(['8.8.8.8'], EgressAllowlist::fromString('')));
$wecom->sendText('fixture-corp', 'fixture-secret', '1000001', 'fixture-user', '你好', null,
    'https://wecom.corp.example.com:8443');
ok($wecomCalls === 2, '渠道：合法 api_base 下企业微信客户端完成令牌与消息两次调用');

$wecomCalls = 0;
$problem = problemOf(static function () use ($wecomRequest, &$wecomCalls): void {
    (new WecomMessageClient($wecomRequest, channelPolicy(['192.168.0.9'], EgressAllowlist::fromString(''))))
        ->sendText('fixture-corp', 'fixture-secret', '1000001', 'fixture-user', '你好', null,
            'https://wecom.corp.example.com');
});
ok($problem !== null && $problem['code'] === 'SAND_AI_CHANNEL_API_BASE_FORBIDDEN' && $wecomCalls === 0,
    '渠道：被拦 api_base 在携带 corp_secret 前被拒绝，传输零调用');

echo "----\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
