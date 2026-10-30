<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

/** Encrypts a WeCom user ID with a key domain separate from queued IAM contexts. */
final class WecomDeliveryCipher
{
    /** @var array<string,string> */
    private array $keys;
    private string $currentId;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $id = $config['current_key_id'] ?? null;
        $key = $config['current_key'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1 || !is_string($key)) self::invalid();
        $this->currentId = $id;
        $this->keys = [$id => self::derive($key)];
        $previous = $config['previous_keys_json'] ?? '';
        if (!is_string($previous)) self::invalid();
        if ($previous !== '') {
            try { $decoded = json_decode($previous, true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { self::invalid(); }
            if (!is_array($decoded) || array_is_list($decoded)) self::invalid();
            foreach ($decoded as $previousId => $previousKey) {
                if (!is_string($previousId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $previousId) !== 1
                    || isset($this->keys[$previousId]) || !is_string($previousKey)) self::invalid();
                $this->keys[$previousId] = self::derive($previousKey);
            }
        }
    }

    /** @return array{version:int,key_id:string,nonce:string,tag:string,ciphertext:string} */
    public function seal(string $userId, int $taskId, int $environmentId, string $code): array
    {
        self::validate($userId, $taskId, $environmentId, $code);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($userId, 'aes-256-gcm', $this->keys[$this->currentId], OPENSSL_RAW_DATA,
            $nonce, $tag, self::aad($taskId, $environmentId, $code));
        if (!is_string($cipher) || strlen($tag) !== 16) self::invalid();
        return ['version' => 1, 'key_id' => $this->currentId, 'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag), 'ciphertext' => base64_encode($cipher)];
    }

    /** @param array<string,mixed> $envelope */
    public function open(array $envelope, int $taskId, int $environmentId, string $code): string
    {
        $fields = array_keys($envelope);
        sort($fields, SORT_STRING);
        if ($fields !== ['ciphertext','key_id','nonce','tag','version'] || ($envelope['version'] ?? null) !== 1
            || !is_string($envelope['key_id']) || !isset($this->keys[$envelope['key_id']])
            || !is_string($envelope['nonce']) || !is_string($envelope['tag']) || !is_string($envelope['ciphertext'])) self::invalid();
        $nonce = base64_decode($envelope['nonce'], true);
        $tag = base64_decode($envelope['tag'], true);
        $cipher = base64_decode($envelope['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16
            || !is_string($cipher) || strlen($cipher) < 1 || strlen($cipher) > 128) self::invalid();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keys[$envelope['key_id']], OPENSSL_RAW_DATA,
            $nonce, $tag, self::aad($taskId, $environmentId, $code));
        if (!is_string($plain)) self::invalid();
        self::validate($plain, $taskId, $environmentId, $code);
        return $plain;
    }

    private static function aad(int $taskId, int $environmentId, string $code): string
    {
        return json_encode([$taskId, $environmentId, $code], JSON_THROW_ON_ERROR);
    }

    private static function validate(string $userId, int $taskId, int $environmentId, string $code): void
    {
        if ($taskId < 1 || $environmentId < 1 || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1
            || $userId === '' || strlen($userId) > 128) self::invalid();
    }

    private static function derive(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) !== 32) self::invalid();
        return hash_hkdf('sha256', $raw, 32, 'sand-ai/wecom-delivery/aead/v1', 'sand-ai/wecom-delivery/salt/v1');
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'WeCom delivery encryption is unavailable');
    }
}
