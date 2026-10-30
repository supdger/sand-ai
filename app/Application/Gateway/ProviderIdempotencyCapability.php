<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

final readonly class ProviderIdempotencyCapability
{
    public function __construct(public string $mode = 'none', public ?string $headerName = null)
    {
        if (!in_array($mode, ['none', 'provider_key'], true)
            || ($mode === 'provider_key' && (preg_match('/\A(?:Idempotency-Key|X-Idempotency-Key)\z/', (string) $headerName) !== 1))) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider idempotency capability is invalid');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromServerConfig(string $adapter, array $config): self
    {
        if ($adapter === 'openai_compatible') {
            $mode = $config['idempotency_mode'] ?? 'none';
            if (!is_string($mode) || !in_array($mode, ['none', 'provider_key'], true)) {
                throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider idempotency mode is invalid');
            }
            return $mode === 'provider_key' ? new self('provider_key', is_string($config['idempotency_header'] ?? null) ? $config['idempotency_header'] : 'Idempotency-Key') : new self();
        }
        return new self();
    }
}
