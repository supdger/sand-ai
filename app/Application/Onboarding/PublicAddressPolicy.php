<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

/**
 * Public-address classification shared by every outbound egress policy.
 *
 * The deny list stays deliberately conservative: private, loopback,
 * link-local, documentation, benchmarking, multicast and reserved ranges are
 * never public unless an administrator EgressAllowlist excuses an exact CIDR.
 * blockedRange() names the reserved range (or range class) that rejected an
 * address so error details can carry a rule identifier instead of echoing the
 * resolved IP itself.
 */
final class PublicAddressPolicy
{
    /** @var list<array{0: string, 1: int}> */
    private const IPV4_RESERVED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.31.196.0', 24], ['192.52.193.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const IPV6_RESERVED = [
        ['2001::', 23], ['2001:2::', 48], ['2001:10::', 28], ['2001:20::', 28],
        ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20],
    ];

    /**
     * Whether a resolved address is publicly routable. Unparseable input is
     * never public (fail closed).
     */
    public static function isPublic(string $address): bool
    {
        return self::blockedRange($address) === null;
    }

    /**
     * The reserved range that rejects this address, or null when public.
     * IPv4 answers name the matched CIDR; IPv6 answers name the matched
     * exception CIDR or 'non_global_unicast' outside 2000::/3; unparseable
     * input reports 'invalid'. The result is a rule identifier for sanitized
     * error details, never the resolved address itself.
     */
    public static function blockedRange(string $address): ?string
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return 'invalid';
        }
        if (strlen($packed) === 4) {
            foreach (self::IPV4_RESERVED as [$network, $prefix]) {
                if (self::matchesCidr($address, $network, $prefix)) {
                    return $network . '/' . $prefix;
                }
            }

            return null;
        }
        if (strlen($packed) !== 16) {
            return 'invalid';
        }
        if (!self::matchesCidr($address, '2000::', 3)) {
            return 'non_global_unicast';
        }
        foreach (self::IPV6_RESERVED as [$network, $prefix]) {
            if (self::matchesCidr($address, $network, $prefix)) {
                return $network . '/' . $prefix;
            }
        }

        return null;
    }

    private static function matchesCidr(string $address, string $network, int $prefix): bool
    {
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
