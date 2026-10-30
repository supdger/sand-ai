<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Dedicated rotating AEAD keyring for write-only managed connection secrets. */
final class ToolConnectionSecretCipher
{
    private const LEGACY_KEY_ID = 'legacy_config_v1';
    private const HKDF_SALT = 'sand-ai/tool-connection/salt/v2';
    private const AEAD_INFO = 'sand-ai/tool-connection/aead/v2';
    private const FINGERPRINT_INFO = 'sand-ai/tool-connection/fingerprint/v2';

    /** @var array<string,array{aead:string,fingerprint:string}> */
    private array $keys = [];
    private string $idempotencyHmacKey;

    /** @param array<string,mixed>|null $config */
    public function __construct(private readonly ?array $config = null)
    {
        $settings = $this->settings();
        $currentKeyId = $settings['current_key_id'] ?? null;
        $currentKey = $settings['current_key'] ?? null;
        $idempotencyKey = $settings['idempotency_hmac_key'] ?? null;
        if (!is_string($currentKeyId) || !$this->validKeyId($currentKeyId) || !is_string($currentKey) || !is_string($idempotencyKey)) $this->unavailable();
        $this->idempotencyHmacKey = hash_hkdf('sha256', $this->decode($idempotencyKey), 32, 'sand-ai/tool-connection/idempotency-hmac/v1', self::HKDF_SALT);
        $this->keys[$currentKeyId] = $this->derive($this->decode($currentKey));
        foreach ($this->previousKeys($settings['previous_keys_json'] ?? '') as $keyId => $encoded) {
            if (isset($this->keys[$keyId])) $this->unavailable();
            $this->keys[$keyId] = $this->derive($this->decode($encoded));
        }
        $legacy = $settings['legacy_config_key'] ?? '';
        if (is_string($legacy) && $legacy !== '') {
            if (strlen($legacy) < 32 || isset($this->keys[self::LEGACY_KEY_ID])) $this->unavailable();
            $this->keys[self::LEGACY_KEY_ID] = [
                'aead' => hash_hkdf('sha256', $legacy, 32, 'sand-ai/tool-connection/aead/v1', 'sand-ai/tool-connection/salt/v1'),
                'fingerprint' => hash_hkdf('sha256', $legacy, 32, 'sand-ai/tool-config-fingerprint/v1', 'sand-ai/tool-config-salt/v1'),
            ];
        }
    }

    public function currentKeyId(): string { return (string) $this->settings()['current_key_id']; }

    /** @param array<string,mixed> $config @return array{version:string,cipher:string,key_id:string,nonce:string,tag:string,ciphertext:string} */
    public function encrypt(array $config): array
    {
        $keyId = $this->currentKeyId(); $nonce = random_bytes(12); $tag = '';
        $ciphertext = openssl_encrypt(ToolFingerprint::canonicalJson($config), 'aes-256-gcm', $this->keys[$keyId]['aead'], OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($keyId));
        if (!is_string($ciphertext) || strlen($tag) !== 16) $this->unavailable();
        return ['version' => 'v2', 'cipher' => 'aes-256-gcm', 'key_id' => $keyId, 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)];
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function decrypt(array $envelope, string $expectedKeyId): array
    {
        if (!$this->validKeyId($expectedKeyId) || !isset($this->keys[$expectedKeyId])) $this->invalid();
        if (($envelope['version'] ?? null) === 'v1') return $this->decryptLegacy($envelope, $expectedKeyId);
        $keys = array_keys($envelope); sort($keys, SORT_STRING);
        if (($envelope['version'] ?? null) !== 'v2' || $keys !== ['cipher', 'ciphertext', 'key_id', 'nonce', 'tag', 'version']
            || ($envelope['cipher'] ?? null) !== 'aes-256-gcm' || ($envelope['key_id'] ?? null) !== $expectedKeyId) $this->invalid();
        $nonce = base64_decode((string) $envelope['nonce'], true); $tag = base64_decode((string) $envelope['tag'], true); $ciphertext = base64_decode((string) $envelope['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) $this->invalid();
        return $this->decodeConfig(openssl_decrypt($ciphertext, 'aes-256-gcm', $this->keys[$expectedKeyId]['aead'], OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($expectedKeyId)));
    }

    /** @param array<string,mixed> $config */
    public function fingerprint(array $config, string $keyId): string
    {
        if (!$this->validKeyId($keyId) || !isset($this->keys[$keyId])) $this->invalid();
        return hash_hmac('sha256', ToolFingerprint::canonicalJson($config), $this->keys[$keyId]['fingerprint']);
    }

    /** Stable across encryption-key rotation so an existing operation key replays. @param array<string,mixed> $value */
    public function idempotencyFingerprint(array $value): string
    {
        return hash_hmac('sha256', ToolFingerprint::canonicalJson(['version' => 'v1', 'value' => $value]), $this->idempotencyHmacKey);
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    private function decryptLegacy(array $envelope, string $expectedKeyId): array
    {
        $keys = array_keys($envelope); sort($keys, SORT_STRING);
        if ($expectedKeyId !== self::LEGACY_KEY_ID || $keys !== ['ciphertext', 'iv', 'tag', 'version']) $this->invalid();
        $iv = base64_decode((string) $envelope['iv'], true); $tag = base64_decode((string) $envelope['tag'], true); $ciphertext = base64_decode((string) $envelope['ciphertext'], true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) $this->invalid();
        return $this->decodeConfig(openssl_decrypt($ciphertext, 'aes-256-gcm', $this->keys[$expectedKeyId]['aead'], OPENSSL_RAW_DATA, $iv, $tag, 'sand-ai/tool-connection/v1'));
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $plaintext): array
    {
        if (!is_string($plaintext)) $this->invalid();
        try { $config = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { $this->invalid(); }
        if (!is_array($config) || array_is_list($config)) $this->invalid();
        return $config;
    }

    /** @return array<string,string> */
    private function previousKeys(mixed $value): array
    {
        if ($value === '' || $value === null) return [];
        if (!is_string($value)) $this->unavailable();
        try { $keys = json_decode($value, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { $this->unavailable(); }
        if (!is_array($keys) || array_is_list($keys)) $this->unavailable();
        foreach ($keys as $keyId => $encoded) if (!is_string($keyId) || !$this->validKeyId($keyId) || !is_string($encoded)) $this->unavailable();
        return $keys;
    }

    /** @return array{aead:string,fingerprint:string} */
    private function derive(string $key): array { return ['aead' => hash_hkdf('sha256', $key, 32, self::AEAD_INFO, self::HKDF_SALT), 'fingerprint' => hash_hkdf('sha256', $key, 32, self::FINGERPRINT_INFO, self::HKDF_SALT)]; }
    private function decode(string $encoded): string { $key = base64_decode($encoded, true); if (!is_string($key) || strlen($key) !== 32) $this->unavailable(); return $key; }
    private function aad(string $keyId): string { return 'sand-ai/tool-connection/v2:' . $keyId; }
    private function validKeyId(string $keyId): bool { return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/', $keyId) === 1; }
    /** @return array<string,mixed> */
    private function settings(): array { $value = $this->config ?? config('sand_ai_tool_connection'); if (!is_array($value)) $this->unavailable(); return $value; }
    private function unavailable(): never { throw new ApiProblem('SAND_AI_TOOL_CONNECTION_UNAVAILABLE', 'Managed tool connection encryption is not configured'); }
    private function invalid(): never { throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool connection encryption binding is invalid'); }
}
