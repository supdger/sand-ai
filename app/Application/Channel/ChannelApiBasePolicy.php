<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\EgressAllowlist;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\PublicAddressPolicy;
use app\Application\Onboarding\SystemHostResolver;
use app\Application\Onboarding\VerifiedEndpoint;

/**
 * Outbound egress policy for channel `api_base` overrides (Feishu/WeCom
 * private deployments and Lark endpoints).
 *
 * `ChannelApiBase::resolve` only proves the value is a well-formed HTTPS URL;
 * this policy is the authoritative gate applied before every
 * credential-bearing POST: the host must not be an IP literal or a blocked
 * name, the port must be an approved one, every DNS answer must be publicly
 * routable (or explicitly allowlisted), and the returned VerifiedEndpoint
 * pins the connection to the checked address so a later DNS rebind cannot
 * redirect channel secrets to an internal host.
 *
 * Private deployments are an explicit exception, never implicit: the
 * administrator lists the resolved ranges in SAND_AI_CHANNEL_EGRESS_ALLOW_CIDRS
 * (default empty, evaluated independently from the provider
 * SAND_AI_EGRESS_ALLOW_CIDRS so channel exceptions cannot widen provider
 * egress). SAND_AI_CHANNEL_PINNED_HOST/SAND_AI_CHANNEL_PINNED_IP pin the
 * channel resolver like the provider/tool pins; incomplete pins fail closed.
 */
final class ChannelApiBasePolicy
{
    public const ALLOWLIST_ENVIRONMENT_VARIABLE = 'SAND_AI_CHANNEL_EGRESS_ALLOW_CIDRS';

    /** @var list<int> */
    private const ALLOWED_PORTS = [443, 8443];

    public function __construct(
        private readonly HostResolver $resolver = new SystemHostResolver('SAND_AI_CHANNEL'),
        private readonly ?EgressAllowlist $allowlist = null,
    ) {
    }

    /**
     * Verify the effective channel API base and return the endpoint transports
     * must use. $apiBase is the optional stored override (null/'' selects the
     * official $default); shape rules come from ChannelApiBase::resolve.
     */
    public function verify(mixed $apiBase, string $default): VerifiedEndpoint
    {
        $baseUrl = ChannelApiBase::resolve($apiBase, $default);
        $parts = parse_url($baseUrl);
        // resolve() already enforced https scheme, a host and no
        // userinfo/query/fragment, so this parse cannot fail shape checks.
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($host === '' || self::isIpLiteral($host) || self::isBlockedHost($host)
            || !in_array($port, self::ALLOWED_PORTS, true)) {
            throw new ApiProblem('SAND_AI_CHANNEL_API_BASE_FORBIDDEN', '渠道 API 地址不允许指向本机、内网、保留地址或未批准端口');
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new ApiProblem('SAND_AI_CHANNEL_API_BASE_DNS_UNAVAILABLE', '无法解析渠道 API 地址，请确认地址后重试', true);
        }
        // Same rule as ProviderConnectionPolicy: the deny list governs every
        // resolved address; the channel allowlist only excuses addresses that
        // match one of its explicit CIDR ranges.
        $allowlist = $this->allowlist ?? self::allowlistFromEnvironment();
        foreach ($addresses as $address) {
            if (!PublicAddressPolicy::isPublic($address) && !$allowlist->allows($address)) {
                throw new ApiProblem(
                    'SAND_AI_CHANNEL_API_BASE_FORBIDDEN',
                    '渠道 API 地址解析到了不允许访问的网络地址',
                    false,
                    [
                        // Sanitized fields only: the configured hostname and the
                        // reserved range class. The resolved IP itself is
                        // internal network detail and is never echoed.
                        'reason_code' => 'channel_api_base_blocked',
                        'api_host' => $host,
                        'blocked_range' => PublicAddressPolicy::blockedRange($address) ?? 'reserved',
                    ],
                );
            }
        }

        return new VerifiedEndpoint(rtrim($baseUrl, '/'), $host, $port, $addresses);
    }

    private static function isBlockedHost(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === 'metadata' || str_ends_with($host, '.metadata')
            || $host === 'metadata.google.internal';
    }

    private static function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    private static function allowlistFromEnvironment(): EgressAllowlist
    {
        // env() is a host-framework helper; isolated tests inject the
        // allowlist explicitly, so a missing helper means "no exceptions".
        $raw = function_exists('env') ? env(self::ALLOWLIST_ENVIRONMENT_VARIABLE, '') : '';

        return EgressAllowlist::fromString(is_string($raw) ? $raw : '', self::ALLOWLIST_ENVIRONMENT_VARIABLE);
    }
}
