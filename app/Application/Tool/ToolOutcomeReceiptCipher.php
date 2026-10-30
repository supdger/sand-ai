<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Separate AEAD domain for normalized external outcomes; raw responses are never encrypted or stored. */
final class ToolOutcomeReceiptCipher
{
    private const MAX_PLAINTEXT_BYTES = 262144;
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 604800;

    /** @var array<string,mixed> */
    private readonly array $config;

    /** @param array<string,mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $resolved = $config ?? config('sand_ai_outcome_receipt');
        if (!is_array($resolved)) $this->unavailable();
        $this->config = $resolved;
    }

    public function assertAvailable(): void
    {
        [$currentKeyId] = $this->currentKey();
        $previous = $this->previousKeys();
        if (array_key_exists($currentKeyId, $previous)) $this->unavailable();
        $this->maxPlaintextBytes();
        $this->ttlSeconds();
    }

    public function ttlSeconds(): int
    {
        $ttl = $this->config['ttl_seconds'] ?? null;
        if (!is_int($ttl) || $ttl < self::MIN_TTL_SECONDS || $ttl > self::MAX_TTL_SECONDS) $this->unavailable();
        return $ttl;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function encrypt(string $plaintext, array $context): array
    {
        if ($plaintext === '' || strlen($plaintext) > $this->maxPlaintextBytes()) $this->invalid();
        [$keyId, $key] = $this->currentKey();
        $iv = random_bytes(12); $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($context));
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'External tool receipt could not be recorded', true);
        return [
            'receipt_version' => 'v1', 'cipher_version' => 'v1', 'key_id' => $keyId,
            'nonce' => $iv, 'tag' => $tag, 'ciphertext' => $ciphertext,
            'plaintext_bytes' => strlen($plaintext), 'ciphertext_bytes' => strlen($ciphertext),
            'content_hmac' => hash_hmac('sha256', $plaintext, $this->hmacKey($key), true),
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $context */
    public function decrypt(array $record, array $context): string
    {
        $keyId = $record['key_id'] ?? null;
        if (($record['receipt_version'] ?? null) !== 'v1' || ($record['cipher_version'] ?? null) !== 'v1' || !is_string($keyId)) $this->invalid();
        $key = $this->key($keyId);
        foreach (['nonce', 'tag', 'ciphertext', 'content_hmac'] as $field) $record[$field] = $this->bytes($record[$field] ?? null);
        if (strlen($record['nonce']) !== 12 || strlen($record['tag']) !== 16 || strlen($record['ciphertext']) !== (int) ($record['ciphertext_bytes'] ?? -1)) $this->invalid();
        $plaintext = openssl_decrypt($record['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $record['nonce'], $record['tag'], $this->aad($context));
        if (!is_string($plaintext) || strlen($plaintext) !== (int) ($record['plaintext_bytes'] ?? -1)
            || !hash_equals($record['content_hmac'], hash_hmac('sha256', $plaintext, $this->hmacKey($key), true))) $this->invalid();
        return $plaintext;
    }

    /** @param array<string,mixed> $context */
    private function aad(array $context): string
    {
        if (array_keys($context) !== ['environment_id', 'tool_id', 'tool_version_id', 'tool_run_id', 'request_fingerprint', 'version']
            || !is_int($context['environment_id']) || !is_int($context['tool_id']) || !is_int($context['tool_version_id']) || !is_int($context['tool_run_id'])
            || min($context['environment_id'], $context['tool_id'], $context['tool_version_id'], $context['tool_run_id']) < 1
            || !is_string($context['request_fingerprint']) || preg_match('/\A[0-9a-f]{64}\z/', $context['request_fingerprint']) !== 1
            || !in_array($context['version'], ['standard_readonly_http/v1', 'mcp_streamable_http/2025-11-25/json', 'mcp_streamable_http/2025-06-18/json'], true)) $this->invalid();
        return ToolFingerprint::canonicalJson($context);
    }

    /** @return array{0:string,1:string} */
    private function currentKey(): array
    {
        $keyId = $this->config['current_key_id'] ?? null;
        if (!is_string($keyId) || !$this->validKeyId($keyId)) $this->unavailable();
        return [$keyId, $this->decode((string) ($this->config['current_key'] ?? ''))];
    }

    private function key(string $keyId): string
    {
        if (!$this->validKeyId($keyId)) $this->invalid();
        if (hash_equals((string) ($this->config['current_key_id'] ?? ''), $keyId)) return $this->decode((string) ($this->config['current_key'] ?? ''));
        $previous = $this->previousKeys();
        if (!is_string($previous[$keyId] ?? null)) $this->invalid();
        return $this->decode($previous[$keyId]);
    }

    /** @return array<string,string> */
    private function previousKeys(): array
    {
        $encoded = $this->config['previous_keys_json'] ?? '';
        if ($encoded === '' || $encoded === null) return [];
        if (!is_string($encoded)) $this->unavailable();
        try {
            $previous = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->unavailable();
        }
        if (!is_array($previous) || array_is_list($previous)) $this->unavailable();
        foreach ($previous as $keyId => $key) {
            if (!is_string($keyId) || !$this->validKeyId($keyId) || !is_string($key)) $this->unavailable();
            $this->decode($key);
        }
        return $previous;
    }

    private function maxPlaintextBytes(): int
    {
        $maximum = $this->config['max_plaintext_bytes'] ?? null;
        if (!is_int($maximum) || $maximum < 1 || $maximum > self::MAX_PLAINTEXT_BYTES) $this->unavailable();
        return $maximum;
    }

    private function decode(string $encoded): string
    {
        $master = base64_decode($encoded, true);
        if (!is_string($master) || strlen($master) !== 32) $this->unavailable();
        return hash_hkdf('sha256', $master, 32, 'sand-ai/tool-outcome/aead/v1', 'sand-ai/tool-outcome/salt/v1');
    }

    private function hmacKey(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, 'sand-ai/tool-outcome/hmac/v1', 'sand-ai/tool-outcome/salt/v1');
    }

    private function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            $decoded = stream_get_contents($value);
            if (!is_string($decoded)) $this->invalid();
            return $decoded;
        }
        if (!is_string($value)) $this->invalid();
        return $value;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt is unavailable for replay');
    }

    private function validKeyId(string $keyId): bool
    {
        return preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $keyId) === 1;
    }

    private function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'External tool receipt encryption is not configured');
    }
}
