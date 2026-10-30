<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;

/** Dedicated external-audio AEAD domain. It never accepts or stores Provider raw bodies. */
final class ExternalAudioReceiptCipher
{
    public const VERSION = 'external_audio_receipt/v1';
    private const MAX_BYTES = 262144;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    /** Validate receipt persistence keys before any Provider-visible request. */
    public function assertReady(): void { $this->ttlSeconds(); $this->currentKey('result'); }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function encrypt(string $kind, string $canonicalPayload, array $context): array
    {
        $this->assertKind($kind); $this->assertPayload($canonicalPayload);
        $now = time(); $expire = gmdate('Y-m-d H:i:s', $now + $this->ttlSeconds()); $retain = gmdate('Y-m-d H:i:s', $now + 604800);
        $aad = self::aad($kind, $context, $expire, $retain);
        [$id, $key] = $this->currentKey($kind); $nonce = random_bytes(12); $tag = '';
        $ciphertext = openssl_encrypt($canonicalPayload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_UNAVAILABLE', 'External audio receipt encryption failed', true);
        return ['receipt_version' => self::VERSION, 'receipt_kind' => $kind, 'key_id' => $id, 'nonce' => $nonce, 'tag' => $tag, 'ciphertext' => $ciphertext, 'plaintext_bytes' => strlen($canonicalPayload), 'content_hmac' => hash_hmac('sha256', $canonicalPayload, $this->hmacKey($key, $kind), true), 'expire_time' => $expire, 'retain_until' => $retain, 'acknowledged_time' => null];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $context */
    public function decrypt(array $record, array $context): string
    {
        if (($record['receipt_version'] ?? null) !== self::VERSION || !is_string($record['receipt_kind'] ?? null) || !is_string($record['key_id'] ?? null)
            || !is_string($record['nonce'] ?? null) || !is_string($record['tag'] ?? null) || !is_string($record['ciphertext'] ?? null) || !is_int($record['plaintext_bytes'] ?? null) || !is_string($record['content_hmac'] ?? null) || !is_string($record['expire_time'] ?? null) || !is_string($record['retain_until'] ?? null) || (($record['acknowledged_time'] ?? null) !== null && !is_string($record['acknowledged_time']))
            || strlen($record['nonce']) !== 12 || strlen($record['tag']) !== 16 || strlen($record['content_hmac']) !== 32) $this->invalid();
        $kind = $record['receipt_kind']; $this->assertKind($kind); if ($record['expire_time'] <= gmdate('Y-m-d H:i:s') || $record['retain_until'] < $record['expire_time']) $this->invalid(); $key = $this->key($kind, $record['key_id']);
        $plain = openssl_decrypt($record['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $record['nonce'], $record['tag'], self::aad($kind, $context, $record['expire_time'], $record['retain_until']));
        if (!is_string($plain) || strlen($plain) !== $record['plaintext_bytes'] || !hash_equals($record['content_hmac'], hash_hmac('sha256', $plain, $this->hmacKey($key, $kind), true))) $this->invalid();
        $this->assertPayload($plain); return $plain;
    }

    /** @param array<string,mixed> $context */
    public static function aad(string $kind, array $context, string $expireTime, string $retainUntil): string
    {
        self::assertContext($context); self::assertKind($kind);
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $expireTime) || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $retainUntil) || $retainUntil < $expireTime) self::invalid();
        return json_encode(['receipt_domain' => 'sand-ai/external-audio/' . $kind . '/v1'] + $context + ['expire_time' => $expireTime, 'retain_until' => $retainUntil], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $context */
    private static function assertContext(array $context): void
    {
        $keys = ['environment_id','task_run_id','dispatch_id','request_fingerprint','logical_execution_id','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','credential_revision_id','credential_digest','price_snapshot_hash'];
        if (array_keys($context) !== $keys) self::invalid();
        foreach (['environment_id','task_run_id','dispatch_id','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','credential_revision_id'] as $key) if (!is_int($context[$key]) || $context[$key] < 1) self::invalid();
        foreach (['request_fingerprint','credential_digest','price_snapshot_hash'] as $key) if (!is_string($context[$key]) || preg_match('/\A[a-f0-9]{64}\z/', $context[$key]) !== 1) self::invalid();
        if (!is_string($context['logical_execution_id']) || preg_match('/\A[a-z][a-z0-9:_-]{7,191}\z/', $context['logical_execution_id']) !== 1) self::invalid();
    }

    private function currentKey(string $kind): array
    {
        $id = $this->config['current_key_id'] ?? null; $raw = $this->config['current_key'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1 || !is_string($raw)) $this->unavailable();
        return [$id, $this->derive($raw, $kind)];
    }
    private function key(string $kind, string $id): string
    {
        [$current] = $this->currentKey($kind); if (hash_equals($current, $id)) return $this->derive((string) $this->config['current_key'], $kind);
        $previous = $this->config['previous_keys_json'] ?? ''; if (!is_string($previous)) $this->unavailable();
        try { $keys = $previous === '' ? [] : json_decode($previous, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { $this->unavailable(); }
        if (!is_array($keys) || array_is_list($keys) || !is_string($keys[$id] ?? null)) $this->invalid();
        return $this->derive($keys[$id], $kind);
    }
    private function derive(string $encoded, string $kind): string
    {
        $master = base64_decode($encoded, true); if (!is_string($master) || strlen($master) !== 32) $this->unavailable();
        $key = hash_hkdf('sha256', $master, 32, 'sand-ai/external-audio/' . $kind . '/aead/v1', 'sand-ai/external-audio/receipt/salt/v1');
        if (!is_string($key) || strlen($key) !== 32) $this->unavailable(); return $key;
    }
    private function hmacKey(string $key, string $kind): string { return hash_hkdf('sha256', $key, 32, 'sand-ai/external-audio/' . $kind . '/hmac/v1', 'sand-ai/external-audio/receipt/salt/v1'); }
    private function ttlSeconds(): int { $ttl=$this->config['ttl_seconds']??null; if(!is_int($ttl)||$ttl<60||$ttl>604800) $this->unavailable(); return $ttl; }
    private static function assertKind(string $kind): void { if (!in_array($kind, ['accepted','result'], true)) self::invalid(); }
    private function assertPayload(string $value): void { if ($value === '' || strlen($value) > self::MAX_BYTES || preg_match('/(?:secret|token|password|api_key|credential|endpoint|url|object_key|bucket|raw)/i', $value) === 1) $this->invalid(); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'External audio receipt is unavailable for recovery'); }
    private function unavailable(): never { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_UNAVAILABLE', 'External audio receipt encryption is not configured'); }
}
