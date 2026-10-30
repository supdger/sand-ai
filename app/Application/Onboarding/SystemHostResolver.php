<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

final class SystemHostResolver implements HostResolver
{
    public function __construct(private readonly string $pinEnvironmentPrefix = 'SAND_AI_PROVIDER')
    {
    }

    public function resolve(string $host): array
    {
        // These settings belong to the server process, never to a Provider or
        // an API request. An incomplete pin fails closed instead of falling
        // back to a possibly intercepted system resolver.
        // Only code-owned server namespaces are supported. Connection/request
        // input cannot select environment keys or supply a pin.
        if (!in_array($this->pinEnvironmentPrefix, ['SAND_AI_PROVIDER', 'SAND_AI_TOOL'], true)) {
            return [];
        }
        $pinnedHost = env($this->pinEnvironmentPrefix . '_PINNED_HOST', null);
        $pinnedAddress = env($this->pinEnvironmentPrefix . '_PINNED_IP', null);
        if ($pinnedHost !== null || $pinnedAddress !== null) {
            if (!is_string($pinnedHost) || !is_string($pinnedAddress)
                || $pinnedHost === '' || $pinnedAddress === ''
                || $pinnedHost !== strtolower($pinnedHost)
                || strlen($pinnedHost) > 253
                || preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)+\z/D', $pinnedHost) !== 1
                || filter_var($pinnedAddress, FILTER_VALIDATE_IP) === false) {
                return [];
            }
            if ($host === $pinnedHost) {
                // ProviderConnectionPolicy must still reject reserved/private
                // addresses before either transport sees this result.
                return [$pinnedAddress];
            }
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $field) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
