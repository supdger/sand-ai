<?php

declare(strict_types=1);

namespace support;

/**
 * Minimal stand-in for the Webman `support\Request` inside isolated guard
 * tests: only the header()/getRemoteIp() surface the access guards consume.
 * The real class ships with the host framework and is never loaded here.
 */
final class Request
{
    /** @param array<string,string> $headers lowercase header name => value */
    public function __construct(
        private readonly array $headers = [],
        private readonly string $remoteIp = '203.0.113.10',
    ) {
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }
}
