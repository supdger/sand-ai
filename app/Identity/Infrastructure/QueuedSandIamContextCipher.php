<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;

/** Dedicated key domain for a short-lived original SandIAM context. */
final class QueuedSandIamContextCipher
{
    private const MAX_CONTEXT_BYTES = 65536;
    private const HKDF_SALT = 'sand-ai/queued-sandiam-context/hkdf-salt/v1';
    private const HKDF_INFO = 'sand-ai/queued-sandiam-context/aead/v1';

    /** @var array<string,string> */
    private array $keys;
    private string $currentKeyId;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $keyId = $config['current_key_id'] ?? null;
        $encodedKey = $config['current_key'] ?? null;
        if (!is_string($keyId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $keyId) !== 1 || !is_string($encodedKey)) {
            self::unavailable();
        }
        $this->currentKeyId = $keyId;
        $this->keys = [$keyId => self::key($encodedKey)];
        $previous = $config['previous_keys_json'] ?? '';
        if ($previous !== '') {
            if (!is_string($previous)) {
                self::unavailable();
            }
            try {
                $decoded = json_decode($previous, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::unavailable();
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                self::unavailable();
            }
            foreach ($decoded as $previousId => $previousKey) {
                if (!is_string($previousId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $previousId) !== 1
                    || !is_string($previousKey) || isset($this->keys[$previousId])) {
                    self::unavailable();
                }
                $this->keys[$previousId] = self::key($previousKey);
            }
        }
    }

    /** @return array{version:int,key_id:string,nonce:string,tag:string,ciphertext:string} */
    public function seal(string $signedContext, QueuedSandIamContextAad $binding): array
    {
        if ($signedContext === '' || strlen($signedContext) > self::MAX_CONTEXT_BYTES
            || $binding->expiresAt() <= time() || $binding->expiresAt() > time() + 300) {
            self::unavailable();
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($signedContext, 'aes-256-gcm', $this->keys[$this->currentKeyId], OPENSSL_RAW_DATA, $nonce, $tag, $binding->aad());
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            self::unavailable();
        }
        return [
            'version' => 1,
            'key_id' => $this->currentKeyId,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /** @param array<string,mixed> $envelope */
    public function open(array $envelope, QueuedSandIamContextAad $binding): string
    {
        if ($binding->expiresAt() <= time()) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'Queued workload identity context has expired');
        }
        return $this->decryptEnvelope($envelope, $binding);
    }

    /** Internal denied-cleanup proof only: authenticates original expired AAD without returning a context or authorizing execution. */
    public function assertExpiredAuthentic(array $envelope, QueuedSandIamContextAad $binding): void
    {
        if ($binding->expiresAt() > time()) self::unavailable();
        $this->decryptEnvelope($envelope, $binding);
    }

    /** @param array<string,mixed> $envelope */
    private function decryptEnvelope(array $envelope, QueuedSandIamContextAad $binding): string
    {
        $fields = array_keys($envelope);
        sort($fields, SORT_STRING);
        if ($fields !== ['ciphertext', 'key_id', 'nonce', 'tag', 'version']
            || $envelope['version'] !== 1
            || !is_string($envelope['key_id']) || !isset($this->keys[$envelope['key_id']])
            || !is_string($envelope['nonce']) || !is_string($envelope['tag']) || !is_string($envelope['ciphertext'])) {
            self::unavailable();
        }
        if (strlen($envelope['nonce']) > 24 || strlen($envelope['tag']) > 32
            || strlen($envelope['ciphertext']) > intdiv((self::MAX_CONTEXT_BYTES + 2) * 4, 3) + 4) {
            self::unavailable();
        }
        $nonce = base64_decode($envelope['nonce'], true);
        $tag = base64_decode($envelope['tag'], true);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce) !== 12
            || !is_string($tag) || strlen($tag) !== 16
            || !is_string($ciphertext) || strlen($ciphertext) < 1 || strlen($ciphertext) > self::MAX_CONTEXT_BYTES) {
            self::unavailable();
        }
        $signedContext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->keys[$envelope['key_id']], OPENSSL_RAW_DATA, $nonce, $tag, $binding->aad());
        if (!is_string($signedContext) || $signedContext === '') {
            self::unavailable();
        }
        return $signedContext;
    }

    private static function key(string $encoded): string
    {
        $master = base64_decode($encoded, true);
        if (!is_string($master) || strlen($master) !== 32) {
            self::unavailable();
        }
        $derived = hash_hkdf('sha256', $master, 32, self::HKDF_INFO, self::HKDF_SALT);
        if (!is_string($derived) || strlen($derived) !== 32) {
            self::unavailable();
        }
        return $derived;
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
    }
}
