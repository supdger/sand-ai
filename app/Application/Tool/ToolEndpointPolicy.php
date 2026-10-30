<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Public-HTTPS-only endpoint verifier. No request or environment test switch exists. */
final class ToolEndpointPolicy implements ToolEndpointVerifier
{
    public function __construct(private readonly ToolHostResolver $resolver = new SystemToolHostResolver())
    {
    }

    public function verify(mixed $origin, mixed $path): VerifiedToolEndpoint
    {
        if (!is_string($origin) || !is_string($path)) $this->reject();
        $origin = rtrim(trim($origin), '/');
        $path = trim($path);
        if (strlen($origin) > 2048 || strlen($path) > 512 || preg_match('/[\r\n]/', $origin . $path) === 1
            || preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#D', $path) !== 1
            || str_contains($path, '//') || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1) $this->reject();
        $parts = parse_url($origin);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
            || isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/') || (($parts['port'] ?? 443) !== 443)) $this->reject();
        $host = strtolower($parts['host']);
        if (strlen($host) > 253 || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host) !== 1
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $this->blockedHost($host)) $this->reject();
        $addresses = $this->resolver->resolve($host);
        if ($addresses === [] || count($addresses) > 16) {
            throw new ApiProblem('SAND_AI_TOOL_DNS_UNAVAILABLE', 'Tool endpoint DNS could not be verified');
        }
        foreach ($addresses as $address) if (!is_string($address) || !$this->publicAddress($address)) $this->reject();
        sort($addresses, SORT_STRING);
        return new VerifiedToolEndpoint('https://' . $host, $host, $path, array_values(array_unique($addresses)));
    }

    private function blockedHost(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata'
            || str_ends_with($host, '.metadata') || $host === 'metadata.google.internal'
            || str_ends_with($host, '.internal') || str_ends_with($host, '.local');
    }

    private function publicAddress(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) return false;
        if (strlen($packed) === 4) {
            return !$this->matchesAny($address, [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
                ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
                ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ]);
        }
        return strlen($packed) === 16 && $this->matches($address, '2000::', 3) && !$this->matchesAny($address, [
            ['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20],
        ]);
    }

    /** @param list<array{0:string,1:int}> $cidrs */
    private function matchesAny(string $address, array $cidrs): bool
    {
        foreach ($cidrs as [$network, $prefix]) if ($this->matches($address, $network, $prefix)) return true;
        return false;
    }

    private function matches(string $address, string $network, int $prefix): bool
    {
        $left = inet_pton($address); $right = inet_pton($network);
        if ($left === false || $right === false || strlen($left) !== strlen($right)) return false;
        $whole = intdiv($prefix, 8); $bits = $prefix % 8;
        if (substr($left, 0, $whole) !== substr($right, 0, $whole)) return false;
        if ($bits === 0) return true;
        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($left[$whole]) & $mask) === (ord($right[$whole]) & $mask);
    }

    private function reject(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_ENDPOINT_FORBIDDEN', 'Tool endpoint is not an approved public HTTPS destination');
    }
}
