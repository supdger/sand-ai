<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** Canonical non-plaintext evidence for every field that can change model execution. */
final class DeploymentExecutionSnapshot
{
    public const VERSION = 'onboarding_deployment_execution@1';

    public function __construct(private readonly ProviderExecutionConfigFingerprint $providerConfig = new ProviderExecutionConfigFingerprint()) {}

    /** @return array{snapshot:array<string,mixed>,sha256:string} */
    public function capture(Provider $provider, AiModel $model, ModelDeployment $deployment): array
    {
        return $this->captureArrays(
            [...$provider->toArray(), 'encrypted_config' => is_array($provider->encrypted_config) ? $provider->encrypted_config : []],
            $model->toArray(),
            $deployment->toArray(),
        );
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $model @param array<string,mixed> $deployment @return array{snapshot:array<string,mixed>,sha256:string} */
    public function captureArrays(array $provider, array $model, array $deployment): array
    {
        $encryptedConfig = is_array($provider['encrypted_config'] ?? null) ? $provider['encrypted_config'] : [];
        $executionConfig = $this->providerConfig->fromEncrypted((string) ($provider['adapter'] ?? ''), $encryptedConfig);
        $capabilities = is_array($model['capabilities'] ?? null) ? array_values(array_unique(array_map('strval', $model['capabilities']))) : [];
        sort($capabilities, SORT_STRING);
        $snapshot = self::canonical([
            'version' => self::VERSION,
            'provider' => [
                'id' => (int) ($provider['id'] ?? 0),
                'code' => (string) ($provider['code'] ?? ''),
                'adapter' => (string) ($provider['adapter'] ?? ''),
                'timeout_ms' => (int) ($provider['timeout_ms'] ?? 0),
                'status' => (int) ($provider['status'] ?? 0),
                'execution_config_version' => $executionConfig['version'],
                'execution_config_hmac_sha256' => $executionConfig['hmac_sha256'],
            ],
            'model' => [
                'id' => (int) ($model['id'] ?? 0),
                'code' => (string) ($model['code'] ?? ''),
                'type' => (string) ($model['type'] ?? ''),
                'capabilities' => $capabilities,
                'config' => is_array($model['config'] ?? null) ? $model['config'] : [],
                'status' => (int) ($model['status'] ?? 0),
            ],
            'deployment' => [
                'id' => (int) ($deployment['id'] ?? 0),
                'provider_id' => (int) ($deployment['provider_id'] ?? 0),
                'model_id' => (int) ($deployment['model_id'] ?? 0),
                'remote_model' => (string) ($deployment['remote_model'] ?? ''),
                'config' => is_array($deployment['config'] ?? null) ? $deployment['config'] : [],
                'revision' => (int) ($deployment['revision'] ?? 0),
                'status' => (int) ($deployment['status'] ?? 0),
            ],
        ]);
        return ['snapshot' => $snapshot, 'sha256' => hash('sha256', self::encode($snapshot))];
    }

    /** @param array<string,mixed> $stored */
    public function assertMatches(array $stored, string $storedDigest, Provider $provider, AiModel $model, ModelDeployment $deployment): void
    {
        $providerValues = [...$provider->toArray(), 'encrypted_config' => is_array($provider->encrypted_config) ? $provider->encrypted_config : []];
        $this->assertArrayMatches($stored, $storedDigest, $providerValues, $model->toArray(), $deployment->toArray());
    }

    /** @param array<string,mixed> $stored @param array<string,mixed> $provider @param array<string,mixed> $model @param array<string,mixed> $deployment */
    public function assertArrayMatches(array $stored, string $storedDigest, array $provider, array $model, array $deployment): void
    {
        try {
            $rebuilt = $this->captureArrays($provider, $model, $deployment);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested provider, model, or deployment configuration has changed');
        }
        $storedCanonicalDigest = hash('sha256', self::encode($stored));
        if (($stored['version'] ?? null) !== self::VERSION
            || !preg_match('/\A[a-f0-9]{64}\z/', $storedDigest)
            || !hash_equals($storedDigest, $storedCanonicalDigest)
            || !hash_equals($storedDigest, $rebuilt['sha256'])) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Tested provider, model, or deployment configuration has changed');
        }
    }

    private static function encode(array $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }
}
