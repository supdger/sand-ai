<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

/** Encrypts write-only channel credentials with a domain separate from IAM contexts and chat targets. */
final class WecomCredentialCipher
{
    /** @var array<string,string> */
    private array $keys = [];
    private string $currentId;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $id = $config['current_key_id'] ?? null;
        $encoded = $config['current_key'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1 || !is_string($encoded)) self::unavailable();
        $this->currentId = $id;
        $this->keys[$id] = self::derive($encoded);
        $previous = $config['previous_keys_json'] ?? '';
        if (!is_string($previous)) self::unavailable();
        if ($previous !== '') {
            try { $decoded = json_decode($previous, true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { self::unavailable(); }
            if (!is_array($decoded) || array_is_list($decoded)) self::unavailable();
            foreach ($decoded as $keyId => $key) {
                if (!is_string($keyId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $keyId) !== 1
                    || isset($this->keys[$keyId]) || !is_string($key)) self::unavailable();
                $this->keys[$keyId] = self::derive($key);
            }
        }
    }

    /** @param array<string,string> $credentials @return array{version:int,key_id:string,nonce:string,tag:string,ciphertext:string} */
    public function seal(array $credentials, int $environmentId, int $bindingId, string $code): array
    {
        WecomChannelCredentials::validate($credentials);
        self::scope($environmentId, $bindingId, $code);
        $nonce = random_bytes(12);
        $tag = '';
        $plain = json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->keys[$this->currentId], OPENSSL_RAW_DATA,
            $nonce, $tag, self::aad($environmentId, $bindingId, $code));
        if (!is_string($cipher) || strlen($tag) !== 16) self::unavailable();
        return ['version' => 1, 'key_id' => $this->currentId, 'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag), 'ciphertext' => base64_encode($cipher)];
    }

    /** @param array<string,mixed> $envelope @return array<string,string> */
    public function open(array $envelope, int $environmentId, int $bindingId, string $code): array
    {
        self::scope($environmentId, $bindingId, $code);
        $fields = array_keys($envelope);
        sort($fields, SORT_STRING);
        if ($fields !== ['ciphertext','key_id','nonce','tag','version'] || ($envelope['version'] ?? null) !== 1
            || !is_string($envelope['key_id']) || !isset($this->keys[$envelope['key_id']])
            || !is_string($envelope['nonce']) || !is_string($envelope['tag']) || !is_string($envelope['ciphertext'])) self::unavailable();
        $nonce = base64_decode($envelope['nonce'], true);
        $tag = base64_decode($envelope['tag'], true);
        $cipher = base64_decode($envelope['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16
            || !is_string($cipher) || strlen($cipher) < 1 || strlen($cipher) > 65536) self::unavailable();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keys[$envelope['key_id']], OPENSSL_RAW_DATA,
            $nonce, $tag, self::aad($environmentId, $bindingId, $code));
        if (!is_string($plain)) self::unavailable();
        try { $credentials = json_decode($plain, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::unavailable(); }
        if (!is_array($credentials)) self::unavailable();
        WecomChannelCredentials::validate($credentials);
        return $credentials;
    }

    private static function aad(int $environmentId, int $bindingId, string $code): string
    {
        return json_encode([$environmentId, $bindingId, $code], JSON_THROW_ON_ERROR);
    }

    private static function scope(int $environmentId, int $bindingId, string $code): void
    {
        if ($environmentId < 1 || $bindingId < 1) self::unavailable();
        ChannelBindingPolicy::code($code);
    }

    private static function derive(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) !== 32) self::unavailable();
        return hash_hkdf('sha256', $raw, 32, 'sand-ai/wecom-credential/aead/v1', 'sand-ai/wecom-credential/salt/v1');
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_UNAVAILABLE', 'Wecom channel configuration is unavailable');
    }
}
