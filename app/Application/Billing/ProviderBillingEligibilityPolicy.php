<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

final class ProviderBillingEligibilityPolicy
{
    /** @param array<string,mixed>|null $provider */
    public function assertIdentity(?array $provider): void
    {
        if ($provider === null
            || ($provider['status'] ?? 0) !== 1
            || !in_array($provider['adapter'] ?? null, ['openai_compatible', 'openai'], true)
            || !is_string($provider['code'] ?? null) || $provider['code'] === '') {
            throw new ApiProblem('SAND_AI_BILLING_UNSUPPORTED', 'provider unavailable');
        }
    }

    /** @param array<string,mixed>|null $provider */
    public function assertEligible(?array $provider): void
    {
        $this->driver($provider);
    }

    /** The configured official endpoint selects a supported billing protocol. */
    public function driver(?array $provider): string
    {
        $this->assertIdentity($provider);
        $baseUrl = $provider['base_url'] ?? null;
        if (($provider['adapter'] ?? null) === 'openai'
            && $this->isOfficialCode($provider['code'] ?? null)
            && ($baseUrl === null || $this->isOfficialBaseUrl($baseUrl))) return 'openai_organization_costs';
        if ($this->isOfficialCode($provider['code'] ?? null) && $this->isOfficialBaseUrl($baseUrl)) return 'openai_organization_costs';
        if (($provider['adapter'] ?? null) === 'openai_compatible'
            && $this->isOfficialOpenRouterBaseUrl($baseUrl)) return 'openrouter_generation';
        throw new ApiProblem('SAND_AI_BILLING_UNSUPPORTED', 'provider unavailable');
    }

    private function isOfficialOpenRouterBaseUrl(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value) === 1) return false;
        $parts = parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && strtolower($parts['host'] ?? '') === 'openrouter.ai'
            && (!isset($parts['port']) || $parts['port'] === 443)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && rtrim($parts['path'] ?? '', '/') === '/api/v1';
    }

    public function isOfficialBaseUrl(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $parts = parse_url($value);
        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.openai.com'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        return rtrim((string) ($parts['path'] ?? ''), '/') === '/v1';
    }

    private function isOfficialCode(mixed $value): bool
    {
        return is_string($value) && ($value === 'openai' || str_starts_with($value, 'openai_'));
    }
}
