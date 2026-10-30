<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Infrastructure\Persistence\Model\Provider;

/** One-shot request material: callers can persist or fingerprint it, never read it. */
final class RequestScopedProviderConnection
{
    /** @var \WeakMap<object,array<string,mixed>>|null */
    private static ?\WeakMap $sealed = null;

    /** @param array{version:string,hmac_sha256:string} $fingerprint */
    private function __construct(private array $fingerprint)
    {
    }

    /** @param array<string,mixed> $connection */
    public static function seal(array $connection): self
    {
        $allowed = ['base_url', 'api_key', 'extra_headers', 'output_token_parameter', 'response_max_bytes', 'idempotency_mode', 'idempotency_header'];
        if (array_diff(array_keys($connection), $allowed) !== []) {
            throw new \InvalidArgumentException('Unsupported deployment connection field');
        }
        $encrypted = (new ProviderSecretEncryptor())->encrypt((new OpenAiCompatibleExecutionConfigPolicy())->normalize($connection));
        $handle = new self((new ProviderExecutionConfigFingerprint())->fromEncrypted('openai_compatible', $encrypted));
        self::$sealed ??= new \WeakMap();
        self::$sealed[$handle] = $encrypted;
        return $handle;
    }

    /** @return array{version:string,hmac_sha256:string} */
    public function executionFingerprint(): array
    {
        return $this->fingerprint;
    }

    /** @param array{code:string,name:string,adapter:string,timeout_ms:int} $provider @return array{id:int,code:string,name:string,adapter:string,timeout_ms:int,status:int,has_encrypted_config:bool} */
    public function createProvider(array $provider): array
    {
        $encrypted = self::$sealed[$this] ?? null;
        if (!is_array($encrypted)) {
            throw new \RuntimeException('Request-scoped provider connection was already consumed');
        }
        unset(self::$sealed[$this]);
        $created = Provider::create([...$provider, 'encrypted_config' => $encrypted, 'status' => 1]);
        return ['id' => (int) $created->id, ...$provider, 'status' => 1, 'has_encrypted_config' => true];
    }

    /** Request material must never cross a serialization boundary. */
    public function __serialize(): array
    {
        throw new \LogicException('Request-scoped provider connection cannot be serialized');
    }

    /** Do not expose request material to diagnostics or debug renderers. */
    public function __debugInfo(): array
    {
        return [];
    }
}
