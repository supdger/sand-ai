<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/**
 * Validates temporary onboarding connections before any outbound request.
 * This also makes the provider discovery testable without a real network.
 */
final class ProviderConnectionPolicy
{
    /** @var list<string> */
    private const EXTRA_HEADER_ALLOWLIST = [
        'OpenAI-Organization',
        'OpenAI-Project',
        'X-Api-Key',
        'X-Organization',
        'X-Project',
    ];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly ?EgressAllowlist $allowlist = null,
    ) {
    }

    /**
     * @param array<string, mixed> $connection
     * @return array{endpoint: VerifiedEndpoint, api_key: string, extra_headers: array<string, string>}
     */
    public function normalize(array $connection): array
    {
        if (array_diff(array_keys($connection), ['base_url', 'api_key', 'extra_headers']) !== []
            || !is_string($connection['base_url'] ?? null) || !is_string($connection['api_key'] ?? null)) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '服务商连接需要字符串格式的 HTTPS 服务地址和 API Key，且不能包含未支持的字段');
        }
        if (preg_match('//u', $connection['base_url']) !== 1 || preg_match('//u', $connection['api_key']) !== 1
            || preg_match('/\p{Cc}/u', $connection['base_url']) === 1 || preg_match('/\p{Cc}/u', $connection['api_key']) === 1) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '服务商连接信息不能包含控制字符');
        }
        $baseUrl = trim($connection['base_url']);
        $apiKey = trim($connection['api_key']);
        if ($baseUrl === '' || $apiKey === '') {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '请填写 HTTPS 服务地址和 API Key');
        }
        if (strlen($baseUrl) > 2048 || strlen($apiKey) > 1024 || preg_match('/[\r\n]/', $apiKey) === 1) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '服务商连接信息格式不安全');
        }
        $extraHeaders = $this->normalizeHeaders($connection['extra_headers'] ?? []);

        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '服务地址必须是没有账号、查询参数或片段的 HTTPS API 地址');
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($host === '' || $this->isIpLiteral($host) || $this->isBlockedHost($host) || !in_array($port, [443, 8443], true)) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN', '服务地址不允许指向本机、内网、保留地址或未批准端口');
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new ApiProblem('SAND_AI_ONBOARDING_DNS_UNAVAILABLE', '无法解析服务商地址，请确认地址后重试', true);
        }
        // The deny list still governs every resolved address; an administrator
        // allowlist only excuses an address that matches one of its explicit
        // CIDR ranges, never a whole class of reserved space.
        $allowlist = $this->allowlist ?? EgressAllowlist::fromEnvironment();
        foreach ($addresses as $address) {
            if (!PublicAddressPolicy::isPublic($address) && !$allowlist->allows($address)) {
                throw new ApiProblem(
                    'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
                    '服务地址解析到了不允许访问的网络地址',
                    false,
                    [
                        // Sanitized fields for the admin hint: the entered public
                        // hostname and the reserved range that blocked the
                        // resolved address. The resolved IP itself is internal
                        // network detail and is never echoed to the frontend.
                        'reason_code' => 'resolved_address_blocked',
                        'service_host' => $host,
                        'blocked_range' => PublicAddressPolicy::blockedRange($address) ?? 'reserved',
                    ],
                );
            }
        }

        return [
            'endpoint' => new VerifiedEndpoint(rtrim($baseUrl, '/'), $host, $port, $addresses),
            'api_key' => $apiKey,
            'extra_headers' => $extraHeaders,
        ];
    }

    /** @param mixed $headers @return array<string, string> */
    private function normalizeHeaders(mixed $headers): array
    {
        if ($headers === null || $headers === []) {
            return [];
        }
        if (!is_array($headers) || count($headers) > 5) {
            throw new ApiProblem('SAND_AI_ONBOARDING_HEADER_INVALID', '额外请求头最多五项，且必须使用允许的字段');
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || !in_array($name, self::EXTRA_HEADER_ALLOWLIST, true)
                || strlen($value) > 512 || preg_match('/[\r\n]/', $value) === 1) {
                throw new ApiProblem('SAND_AI_ONBOARDING_HEADER_INVALID', '额外请求头包含不允许的字段或内容');
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private function isBlockedHost(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === 'metadata' || str_ends_with($host, '.metadata')
            || $host === 'metadata.google.internal';
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }
}
