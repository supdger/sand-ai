<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\BigModelMeetingTokenCounter;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** Catalogue of implemented counters, not inferred provider capabilities. */
final class MeetingTokenizerDrivers
{
    /** @param array<string,mixed> $route @return list<array<string,mixed>> */
    public static function forRoute(array $route): array
    {
        // This binding describes the existing concrete production adapter.
        // Additional counters need their own implemented protocol and evidence.
        if (($route['provider_adapter'] ?? null) !== 'openai_compatible'
            || ($route['deployment_remote_model'] ?? null) !== 'glm-5.3-flash') return [];
        return [[
            'code' => 'bigmodel_tokenizer@1', 'display_name' => '智谱官方分词计数',
            'context_window_ceiling' => 1000000, 'request_limit_min' => 40000, 'request_limit_max' => 100000,
        ]];
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    public static function require(array $route, string $code): array
    {
        foreach (self::forRoute($route) as $driver) if ($driver['code'] === $code) return $driver;
        throw new ApiProblem('SAND_AI_MEETING_TOKENIZER_UNSUPPORTED', '当前发布模型没有可执行的分词驱动，请选择已支持的部署');
    }

    /** Current endpoint eligibility is checked locally; no discovery or remote probe. */
    public static function assertEndpoint(array $route): void
    {
        $provider = Provider::where('id', $route['provider_id'])->where('status', 1)->find();
        $encrypted = $provider?->encrypted_config;
        $encrypted = is_object($encrypted) ? (array) $encrypted : $encrypted;
        if ($provider === null || !is_array($encrypted)
            || !hash_equals($route['provider_config_sha256'], RequestPayloadFingerprint::fingerprint($encrypted, []))) {
            throw new ApiProblem('SAND_AI_MEETING_TOKENIZER_UNSUPPORTED', '当前发布服务商配置不支持该分词驱动');
        }
        $config = (new ProviderSecretDecryptor())->decrypt($encrypted);
        if (rtrim((string) ($config['base_url'] ?? ''), '/') !== BigModelMeetingTokenCounter::BASE_URL) {
            throw new ApiProblem('SAND_AI_MEETING_TOKENIZER_UNSUPPORTED', '当前发布连接没有该分词驱动所需的正式接口，不能按模型别名推断支持');
        }
    }

    public static function endpoint(string $code): string
    {
        if ($code === 'bigmodel_tokenizer@1') return BigModelMeetingTokenCounter::ENDPOINT;
        throw new ApiProblem('SAND_AI_MEETING_TOKENIZER_UNSUPPORTED', '当前分词驱动不可用');
    }
}
