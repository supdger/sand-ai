<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;

/**
 * 将服务商只写配置加密为可由主应用运行时解密的信封。
 * 密钥、明文配置和密文细节均不应进入任何 API DTO、日志或审计上下文。
 */
final class ProviderSecretEncryptor
{
    /** @param array<string, mixed> $config @return array{version: string, iv: string, tag: string, ciphertext: string} */
    public function encrypt(array $config): array
    {
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration key is unavailable');
        }

        $iv = random_bytes(12);
        $tag = '';
        $plaintext = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration could not be encrypted');
        }

        return [
            'version' => 'v1',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }
}
