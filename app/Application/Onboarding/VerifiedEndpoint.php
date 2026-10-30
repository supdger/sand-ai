<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

/**
 * A URL may only be used through the address resolved and checked during this
 * request. The transport keeps the original host for TLS/SNI while pinning the
 * TCP connection to connectionAddress.
 */
final class VerifiedEndpoint
{
    /** @param list<string> $resolvedAddresses */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $host,
        public readonly int $port,
        public readonly array $resolvedAddresses,
    ) {
    }

    public function connectionAddress(): string
    {
        return $this->resolvedAddresses[0];
    }

    public function curlResolveEntry(): string
    {
        $address = $this->connectionAddress();
        if (str_contains($address, ':')) {
            $address = '[' . $address . ']';
        }

        return $this->host . ':' . $this->port . ':' . $address;
    }
}
