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
require_once dirname(__DIR__) . '/app/Application/Onboarding/ProviderConnectionPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OnboardingHttpResponse.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OnboardingHttpTransport.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/RemoteModelIdentifier.php';
require_once dirname(__DIR__) . '/app/Application/Onboarding/OpenAiCompatibleOnboardingClient.php';

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\EgressAllowlist;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\OnboardingHttpResponse;
use app\Application\Onboarding\OnboardingHttpTransport;
use app\Application\Onboarding\OpenAiCompatibleOnboardingClient;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\VerifiedEndpoint;

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
    && ($problem['details']['blocked_address'] ?? null) === '198.18.0.123',
    '默认：公网域解析进 198.18.0.0/15 仍被阻断并带结构化标记');

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

echo "----\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
