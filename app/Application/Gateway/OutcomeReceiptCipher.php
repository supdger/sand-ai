<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/**
 * Separate AEAD keyring for replayable outcomes.  It intentionally does not
 * share the Provider secret cipher or its environment key.
 */
final class OutcomeReceiptCipher
{
    public const RECEIPT_VERSION = 'v1';
    public const CIPHER_VERSION = 'v1';
    public const MAX_PLAINTEXT_BYTES = 262144;
    private const HKDF_SALT = 'sand-ai/outcome-receipt/hkdf-salt/v1';
    private const AEAD_INFO = 'sand-ai/outcome-receipt/aead/v1';
    private const CONTENT_HMAC_INFO = 'sand-ai/outcome-receipt/content-hmac/v1';

    /** @var array<string,array{aead:string,content_hmac:string}> */
    private array $keys;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $currentKeyId = $config['current_key_id'] ?? null;
        $currentKey = $config['current_key'] ?? null;
        if (!is_string($currentKeyId) || !self::validKeyId($currentKeyId) || !is_string($currentKey)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
        }

        $keys = [$currentKeyId => self::deriveSubkeys(self::decodeKey($currentKey))];
        $previous = self::previousKeys($config['previous_keys_json'] ?? '');
        foreach ($previous as $keyId => $encodedKey) {
            if (isset($keys[$keyId])) {
                throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
            }
            $keys[$keyId] = self::deriveSubkeys(self::decodeKey($encodedKey));
        }
        $this->keys = $keys;
    }

    /** @param array<string,mixed> $context */
    public function encrypt(string $plaintext, array $context): OutcomeReceiptEnvelope
    {
        $this->assertPlaintextLength($plaintext);
        $nonce = random_bytes(12);
        $tag = '';
        $keyId = (string) $this->config['current_key_id'];
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->keys[$keyId]['aead'], OPENSSL_RAW_DATA, $nonce, $tag, self::aad($context));
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption failed', true);
        }

        return new OutcomeReceiptEnvelope(self::RECEIPT_VERSION, self::CIPHER_VERSION, $keyId, $nonce, $tag, $ciphertext, strlen($plaintext), hash_hmac('sha256', $plaintext, $this->keys[$keyId]['content_hmac'], true));
    }

    /** @param array<string,mixed> $context */
    public function decrypt(OutcomeReceiptEnvelope $envelope, array $context): string
    {
        if ($envelope->receiptVersion !== self::RECEIPT_VERSION || $envelope->cipherVersion !== self::CIPHER_VERSION
            || !isset($this->keys[$envelope->keyId]) || strlen($envelope->nonce) !== 12 || strlen($envelope->tag) !== 16) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
        $plaintext = openssl_decrypt($envelope->ciphertext, 'aes-256-gcm', $this->keys[$envelope->keyId]['aead'], OPENSSL_RAW_DATA, $envelope->nonce, $envelope->tag, self::aad($context));
        if (!is_string($plaintext) || strlen($plaintext) !== $envelope->plaintextBytes || !hash_equals($envelope->contentHmac, hash_hmac('sha256', $plaintext, $this->keys[$envelope->keyId]['content_hmac'], true))) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
        $this->assertPlaintextLength($plaintext);
        return $plaintext;
    }

    public function maxPlaintextBytes(): int
    {
        $value = $this->config['max_plaintext_bytes'] ?? null;
        if (!is_int($value) || $value < 1 || $value > self::MAX_PLAINTEXT_BYTES) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
        }
        return $value;
    }

    /** @param array<string,mixed> $context */
    public static function aad(array $context): string
    {
        $required = ['environment_id', 'invocation_id', 'request_fingerprint', 'capability_code', 'model_id', 'deployment_id', 'version'];
        if (array_keys($context) !== $required) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt context is invalid');
        }
        foreach (['environment_id', 'invocation_id', 'model_id', 'deployment_id'] as $field) {
            if (!is_int($context[$field]) || $context[$field] < 1) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt context is invalid');
            }
        }
        foreach (['request_fingerprint', 'capability_code', 'version'] as $field) {
            if (!is_string($context[$field]) || $context[$field] === '') {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt context is invalid');
            }
        }
        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function assertPlaintextLength(string $plaintext): void
    {
        if (strlen($plaintext) < 1 || strlen($plaintext) > $this->maxPlaintextBytes()) {
            throw new ApiProblem('SAND_AI_RECEIPT_INPUT_TOO_LARGE', 'Outcome receipt content exceeds its configured safety limit');
        }
    }

    /** @return array<string,string> */
    private static function previousKeys(mixed $value): array
    {
        if ($value === '' || $value === null) {
            return [];
        }
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
        }
        $keys = [];
        foreach ($decoded as $keyId => $key) {
            if (!is_string($keyId) || !self::validKeyId($keyId) || !is_string($key)) {
                throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
            }
            $keys[$keyId] = $key;
        }
        return $keys;
    }

    private static function decodeKey(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
        }
        return $key;
    }

    /** @return array{aead:string,content_hmac:string} */
    private static function deriveSubkeys(string $masterKey): array
    {
        $aead = hash_hkdf('sha256', $masterKey, 32, self::AEAD_INFO, self::HKDF_SALT);
        $contentHmac = hash_hkdf('sha256', $masterKey, 32, self::CONTENT_HMAC_INFO, self::HKDF_SALT);
        if (!is_string($aead) || !is_string($contentHmac) || strlen($aead) !== 32 || strlen($contentHmac) !== 32 || hash_equals($aead, $contentHmac)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt key configuration is invalid');
        }
        return ['aead' => $aead, 'content_hmac' => $contentHmac];
    }

    private static function validKeyId(string $keyId): bool
    {
        return preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $keyId) === 1;
    }
}
