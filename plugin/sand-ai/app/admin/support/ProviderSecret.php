<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\support;

use plugin\sandadmin\exception\ApiException;

final class ProviderSecret
{
    /** @param array<string, mixed> $config @return array<string, string> */
    public static function encrypt(array $config): array
    {
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: SAND_AI_CONFIG_KEY must contain at least 32 characters');
        }
        $iv = random_bytes(12);
        $plaintext = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new ApiException('SAND_AI_SECRET_WRITE_ONLY');
        }
        return [
            'version' => 'v1',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }
}
