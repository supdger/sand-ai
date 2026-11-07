<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/**
 * Administrator-controlled CIDR allowlist for outbound connection checks.
 *
 * The default posture stays deny: every reserved/private/documentation
 * destination rejected by ProviderConnectionPolicy remains rejected. A host
 * administrator may explicitly list CIDR ranges in the
 * SAND_AI_EGRESS_ALLOW_CIDRS environment variable (comma separated) when a
 * trusted resolver deliberately answers inside a blocked range — for example
 * a local proxy whose fake-IP pool maps public provider domains into
 * 198.18.0.0/15. Each resolved address is then accepted only when it matches
 * a listed range; nothing is allowed implicitly.
 *
 * This deliberately weakens egress protection and must stay empty unless the
 * administrator trusts every destination inside the listed ranges. An invalid
 * entry fails closed: the whole allowlist is rejected rather than silently
 * partially applied.
 */
final class EgressAllowlist
{
    public const ENVIRONMENT_VARIABLE = 'SAND_AI_EGRESS_ALLOW_CIDRS';

    /** @var list<array{0: string, 1: int}> normalized [packed-network, prefix] pairs */
    private readonly array $cidrs;

    /** @param list<array{0: string, 1: int}> $cidrs */
    private function __construct(array $cidrs)
    {
        $this->cidrs = $cidrs;
    }

    public static function fromEnvironment(): self
    {
        $raw = env(self::ENVIRONMENT_VARIABLE, '');
        return self::fromString(is_string($raw) ? $raw : '');
    }

    /**
     * Parses a comma/whitespace separated CIDR list. An empty string yields an
     * empty allowlist. Any malformed entry rejects the entire configuration so
     * a typo cannot silently narrow or widen the intended ranges. $source names
     * the environment variable the list came from so the error points at the
     * right knob (provider vs channel egress lists are separate).
     */
    public static function fromString(string $raw, string $source = self::ENVIRONMENT_VARIABLE): self
    {
        $entries = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($entries)) {
            $entries = [];
        }
        $cidrs = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (strlen($entry) > 64
                || preg_match('/\A([0-9A-Fa-f:.]+)\/(\d{1,3})\z/', $entry, $match) !== 1) {
                throw self::invalid($entry, $source);
            }
            $network = inet_pton($match[1]);
            if ($network === false) {
                throw self::invalid($entry, $source);
            }
            $prefix = (int) $match[2];
            $bits = strlen($network) * 8;
            if ($prefix > $bits) {
                throw self::invalid($entry, $source);
            }
            // Normalize host bits away so `198.18.0.123/15` means `198.18.0.0/15`.
            $masked = substr($network, 0, intdiv($prefix, 8));
            $rest = $prefix % 8;
            if ($rest !== 0) {
                $masked .= chr(ord($network[intdiv($prefix, 8)]) & ((0xff << (8 - $rest)) & 0xff));
            }
            $normalized = str_pad($masked, strlen($network), "\0");
            $key = strlen($network) . ':' . $normalized . ':' . $prefix;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cidrs[] = [$normalized, $prefix];
        }

        return new self($cidrs);
    }

    /** Whether a resolved IP literal falls inside an explicitly listed range. */
    public function allows(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($this->cidrs as [$network, $prefix]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $wholeBytes = intdiv($prefix, 8);
            if (substr($packed, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
                continue;
            }
            $rest = $prefix % 8;
            if ($rest === 0
                || (ord($packed[$wholeBytes]) & ((0xff << (8 - $rest)) & 0xff))
                    === (ord($network[$wholeBytes]) & ((0xff << (8 - $rest)) & 0xff))) {
                return true;
            }
        }

        return false;
    }

    private static function invalid(string $entry, string $source): ApiProblem
    {
        return new ApiProblem(
            'SAND_AI_EGRESS_ALLOWLIST_INVALID',
            '放行名单配置包含无效网段，请宿主管理员检查 ' . $source . ' 后重载',
            false,
            ['reason_code' => 'egress_allowlist_invalid'],
        );
    }
}
