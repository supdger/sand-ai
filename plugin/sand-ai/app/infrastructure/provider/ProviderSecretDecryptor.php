<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\provider;

use plugin\SandAi\app\api\support\ApiProblem;

/** Trusted runtime decryptor; it never emits provider plaintext in a DTO. */
final class ProviderSecretDecryptor
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function decrypt(array $payload): array
    {
        if (($payload['version'] ?? null) !== 'v1') {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is unavailable');
        }
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration key is unavailable');
        }
        $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
        $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($payload['ciphertext'] ?? ''), true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is malformed');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration could not be decrypted');
        }
        try {
            $config = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is malformed');
        }
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is malformed');
        }

        return $config;
    }
}
