<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\SystemHostResolver;

/** Production endpoint policy reuses onboarding's public-DNS verification. */
final class HttpsProviderEndpointResolver implements ProviderEndpointResolver
{
    public function __construct(private readonly HostResolver $hostResolver = new SystemHostResolver())
    {
    }

    public function chatCompletions(array $config): ResolvedProviderEndpoint
    {
        try {
            $verified = (new ProviderConnectionPolicy($this->hostResolver))->normalize([
                'base_url' => $config['base_url'] ?? null,
                // ProviderConnectionPolicy only validates presence/format here;
                // the real secret remains solely in the provider request.
                'api_key' => 'endpoint-policy-placeholder',
            ])['endpoint'];
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint could not be verified as a public HTTPS destination');
        }

        return new ResolvedProviderEndpoint(
            $verified->baseUrl . '/chat/completions',
            $verified->curlResolveEntry(),
        );
    }

    /** @param array<string,mixed> $config */
    public function embeddings(array $config): ResolvedProviderEndpoint
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!is_string($baseUrl) || !$this->audioBasePathIsSafe($baseUrl)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider embedding endpoint base path is invalid');
        }
        $parts = parse_url($baseUrl);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        foreach (explode('/', $path) as $segment) {
            $decoded = $this->decodeAudioPathSegment($segment);
            if ($decoded === null || strtolower($decoded) === 'embeddings') {
                throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider embedding endpoint base path is invalid');
            }
        }
        try {
            $verified = (new ProviderConnectionPolicy($this->hostResolver))->normalize([
                'base_url' => $baseUrl,
                'api_key' => 'endpoint-policy-placeholder',
            ])['endpoint'];
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint could not be verified as a public HTTPS destination');
        }
        return new ResolvedProviderEndpoint(rtrim($verified->baseUrl, '/') . '/embeddings', $verified->curlResolveEntry());
    }

    public function audioTranscriptions(array $config): ResolvedProviderEndpoint
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!is_string($baseUrl) || !$this->audioBasePathIsSafe($baseUrl)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider audio endpoint base path is invalid');
        }
        try {
            $verified = (new ProviderConnectionPolicy($this->hostResolver))->normalize([
                'base_url' => $baseUrl,
                'api_key' => 'endpoint-policy-placeholder',
            ])['endpoint'];
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint could not be verified as a public HTTPS destination');
        }
        return new ResolvedProviderEndpoint(rtrim($verified->baseUrl, '/') . '/audio/transcriptions', $verified->curlResolveEntry());
    }

    public function audioSpeech(array $config): ResolvedProviderEndpoint
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!is_string($baseUrl) || !$this->audioBasePathIsSafe($baseUrl)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider speech endpoint base path is invalid');
        }
        try {
            $verified = (new ProviderConnectionPolicy($this->hostResolver))->normalize([
                'base_url' => $baseUrl,
                'api_key' => 'endpoint-policy-placeholder',
            ])['endpoint'];
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint could not be verified as a public HTTPS destination');
        }
        return new ResolvedProviderEndpoint(rtrim($verified->baseUrl, '/') . '/audio/speech', $verified->curlResolveEntry());
    }

    private function audioBasePathIsSafe(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment'])) return false;
        $path = (string) ($parts['path'] ?? '');
        $segments = [];
        foreach (explode('/', $path) as $rawSegment) {
            if ($rawSegment === '') continue;
            $segment = $this->decodeAudioPathSegment($rawSegment);
            if ($segment === null || $segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\')) return false;
            $segments[] = strtolower($segment);
        }
        for ($index = 0; $index < count($segments) - 1; $index++) {
            if ($segments[$index] === 'audio' && in_array($segments[$index + 1], ['transcriptions', 'speech'], true)) return false;
        }
        return true;
    }

    /** Decode until stable so double-encoding cannot hide traversal or separators. */
    private function decodeAudioPathSegment(string $rawSegment): ?string
    {
        $value = $rawSegment;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            if (preg_match('/%(?![0-9a-f]{2})/i', $value) === 1) return null;
            $decoded = rawurldecode($value);
            if ($decoded === $value) return $value;
            $value = $decoded;
        }
        return null;
    }
}
