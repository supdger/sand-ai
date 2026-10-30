<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\Provider;

/** Infrastructure-only decrypt-and-HMAC projection; callers never receive config material. */
final class ProviderExecutionConfigFingerprint
{
    public const VERSION = 'openai_compatible_execution_config@1';

    public function __construct(private readonly ProviderSecretDecryptor $decryptor = new ProviderSecretDecryptor(), private readonly OpenAiCompatibleExecutionConfigPolicy $policy = new OpenAiCompatibleExecutionConfigPolicy()) {}

    /** @param array<string,mixed> $encryptedConfig @return array{version:string,hmac_sha256:string} */
    public function fromEncrypted(string $adapter, array $encryptedConfig): array
    {
        if ($adapter === 'local_ocr_runtime') {
            if ($encryptedConfig !== []) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Local OCR must not contain remote connection material');
            return \app\Infrastructure\Parsing\LocalOcrExecutionConfig::fingerprint();
        }
        if ($adapter === 'local_runtime') {
            if ($encryptedConfig !== []) {
                throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Local runtime must not contain remote connection material');
            }
            return (new \app\Application\Audio\LocalAudioExecutionFingerprintPolicy())->capture();
        }
        if (!in_array($adapter, ['openai_compatible', 'cohere_rerank', 'jina_rerank'], true)) { throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider execution fingerprint adapter is unsupported'); }
        return $this->fromPlaintext($adapter, $this->decryptor->decrypt($encryptedConfig));
    }

    /** Validated temporary verification shares the existing credential-sensitive HMAC. */
    public function fromPlaintext(string $adapter, array $config): array
    {
        if (!in_array($adapter, ['openai_compatible', 'cohere_rerank', 'jina_rerank'], true)) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Execution fingerprint adapter is unsupported');
        $projection = $this->policy->normalize($config);
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) { throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider execution fingerprint key is unavailable'); }
        if ($adapter !== 'openai_compatible') $projection = ['adapter' => $adapter, 'config' => $projection];
        return ['version' => $adapter === 'openai_compatible' ? self::VERSION : 'professional_rerank_execution_config@1', 'hmac_sha256' => hash_hmac('sha256', self::encode($projection), hash_hmac('sha256', 'sand-ai-provider-execution-fingerprint@1', $key, true))];
    }

    /** @return array{version:string,hmac_sha256:string} */
    public function fromProviderId(int $providerId): array
    {
        $provider = Provider::where('id', $providerId)->whereNull('delete_time')->find();
        if (!$provider instanceof Provider || !is_array($provider->encrypted_config)) { throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Provider execution configuration is unavailable'); }
        return $this->fromEncrypted((string) $provider->adapter, $provider->encrypted_config);
    }

    private static function encode(array $value): string { return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private static function canonical(mixed $value): mixed { if (!is_array($value)) { return $value; } if (array_is_list($value)) { return array_map(self::canonical(...), $value); } ksort($value, SORT_STRING); return array_map(self::canonical(...), $value); }
}
