<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Infrastructure\Provider\NeuronControlledHttpClient;
use app\Infrastructure\Provider\NeuronMaterialChatProvider;
use app\Infrastructure\Provider\NeuronSkillOutputAdapter;

/**
 * Temporary OpenAI-compatible discovery and verification. It never persists a
 * credential, never follows redirects, and maps upstream bodies to stable
 * local errors before they leave this application layer.
 */
final class OpenAiCompatibleOnboardingClient
{
    public const DEFAULT_VERIFICATION_TIMEOUT_MS = 120000;
    private const MAX_DISCOVERED_MODELS = 100;

    public function __construct(
        private readonly ProviderConnectionPolicy $connectionPolicy,
        private readonly OnboardingHttpTransport $transport,
    ) {
    }

    /**
     * @param array<string, mixed> $connection
     * @return list<array{remote_model: string, owner: string|null}>
     */
    public function discover(array $connection): array
    {
        $normalized = $this->connectionPolicy->normalize($connection);
        $payload = $this->requestJson('GET', $normalized, '/models', null);
        $models = $payload['data'] ?? null;
        if (!is_array($models)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', '服务商没有返回可识别的模型列表', true);
        }
        if ($models === []) {
            return [];
        }

        $result = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            try {
                $id = RemoteModelIdentifier::normalize($model['id'] ?? null);
            } catch (ApiProblem) {
                continue;
            }
            if (!isset($result[$id]) && count($result) >= self::MAX_DISCOVERED_MODELS) {
                throw new ApiProblem('SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE', '服务商返回的模型数量超过首次开通允许的上限');
            }
            $owner = isset($model['owned_by']) && is_string($model['owned_by']) && strlen($model['owned_by']) <= 128
                ? $model['owned_by'] : null;
            $result[$id] = ['remote_model' => $id, 'owner' => $owner];
        }
        if ($result === []) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', '服务商没有返回可用模型，请检查账号权限', true);
        }

        return array_values($result);
    }

    /** @param array<string, mixed> $connection @return array{usage_measured: bool, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null} */
    public function verify(array $connection, string $remoteModel, int $maxOutputTokens = 1024, ?int $inputTokenCeiling = null): array
    {
        $this->verificationSettings($maxOutputTokens, $inputTokenCeiling);
        $normalized = $this->connectionPolicy->normalize($connection);
        return $this->verifyNormalized($normalized, $remoteModel, $maxOutputTokens, $inputTokenCeiling);
    }

    /**
     * @param array{endpoint: VerifiedEndpoint, api_key: string, extra_headers: array<string, string>} $normalized
     * @return array{usage_measured: bool, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    public function verifyNormalized(array $normalized, string $remoteModel, int $maxOutputTokens = 1024, ?int $inputTokenCeiling = null, ?ProviderSendBoundary $boundary = null): array
    {
        $remoteModel = RemoteModelIdentifier::normalize($remoteModel);
        $settings = $this->verificationSettings($maxOutputTokens, $inputTokenCeiling);
        // Verify with the same administrator-confirmed output bound that the
        // successful draft will freeze, using the runtime's neutral body builder.
        $body = ProviderRequestBodyBuilder::chat(
            $remoteModel,
            [['role' => 'user', 'content' => 'Reply with OK.']],
            new ChatCompletionOptions((int) $settings['max_output_tokens']),
        );
        if (!class_exists(\NeuronAI\Providers\OpenAI\OpenAI::class)
            || !\Composer\InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) \Composer\InstalledVersions::getVersion('neuron-core/neuron-ai'), '4.0.2', '>=')) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', '当前运行环境缺少首次开通所需的 Neuron 模型验证能力');
        }
        $headers = $this->headers($normalized);
        $endpoint = $normalized['endpoint']->baseUrl . '/chat/completions';
        $send = function (ProviderSendBoundary $boundary) use ($normalized, $body): array {
            // All connection/body checks preceded this sole controlled send.
            $boundary->markStarted();
            $status = null;
            $payload = $this->requestJson('POST', $normalized, '/chat/completions', $body, self::DEFAULT_VERIFICATION_TIMEOUT_MS, $status);
            $verified = $this->verifiedCompletion($payload);
            return ['payload' => $payload, 'status' => $status, 'result' => [
                'content' => $payload['choices'][0]['message']['content'],
                'input_tokens' => $verified['input_tokens'] ?? 0,
                'output_tokens' => $verified['output_tokens'] ?? 0,
                'usage_measured' => $verified['usage_measured'],
            ]];
        };
        $httpClient = new NeuronControlledHttpClient($endpoint, $headers, ProviderRequestBodyBuilder::digest($body), $send);
        $neuron = new NeuronMaterialChatProvider($normalized['api_key'], $remoteModel, $body, $endpoint, $headers, $httpClient);
        $httpClient->bindBoundary($boundary ?? new ProviderSendBoundary());
        try {
            $completion = $neuron->chat(...NeuronSkillOutputAdapter::messages($body['messages']));
            $result = $httpClient->completion($completion->message());
        } catch (\app\Infrastructure\Provider\MeasuredChatFailure $failure) {
            throw $failure;
        } catch (ApiProblem $problem) {
            throw $httpClient->measuredFailure() ?? $problem;
        } catch (\Throwable) {
            if (($failure = $httpClient->measuredFailure()) !== null) throw $failure;
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', '模型验证结果无法可靠确认，可能已产生费用；请先检查服务商调用记录', true);
        }
        return [
            'usage_measured' => $result['usage_measured'],
            'input_tokens' => $result['usage_measured'] ? $result['input_tokens'] : null,
            'output_tokens' => $result['usage_measured'] ? $result['output_tokens'] : null,
            'total_tokens' => $result['usage_measured'] ? $result['input_tokens'] + $result['output_tokens'] : null,
        ];
    }

    /** @param array<string,mixed> $payload @return array{usage_measured:bool,input_tokens:?int,output_tokens:?int,total_tokens:?int} */
    private function verifiedCompletion(array $payload): array
    {
        $content = $payload['choices'][0]['message']['content'] ?? null;
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $inputTokens = $this->tokenCount($usage['prompt_tokens'] ?? null);
        $outputTokens = $this->tokenCount($usage['completion_tokens'] ?? null);
        $totalTokens = $this->tokenCount($usage['total_tokens'] ?? null);
        $measured = $inputTokens !== null && $outputTokens !== null && $totalTokens !== null;
        if ($measured && ($inputTokens > PHP_INT_MAX - $outputTokens
            || $totalTokens !== $inputTokens + $outputTokens)) {
            throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Provider returned inconsistent token usage');
        }

        if (!is_string($content) || trim($content) === '') {
            if ($measured) throw new \app\Infrastructure\Provider\MeasuredChatFailure($inputTokens, $outputTokens);
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Model verification has no usable completion; usage cannot be confirmed', true);
        }

        return [
            'usage_measured' => $measured,
            // Partial or missing usage cannot establish any measured total.
            'input_tokens' => $measured ? $inputTokens : null,
            'output_tokens' => $measured ? $outputTokens : null,
            'total_tokens' => $measured ? $totalTokens : null,
        ];
    }

    /** @return array<string,int|string> */
    private function verificationSettings(int $maxOutputTokens, ?int $inputTokenCeiling): array
    {
        $settings = (new DeploymentDraftConfigurationPolicy())->normalize(array_filter(
            ['max_output_tokens' => $maxOutputTokens, 'input_token_ceiling' => $inputTokenCeiling],
            static fn (mixed $value): bool => $value !== null,
        ));
        (new DeploymentDraftConfigurationPolicy())->assertForModel($settings, 'chat');
        return $settings;
    }

    /** @param array{endpoint: VerifiedEndpoint, api_key: string, extra_headers: array<string, string>} $normalized @param array<string, mixed>|null $json @return array<string, mixed> */
    private function requestJson(string $method, array $normalized, string $path, ?array $json, int $timeoutMs = 10000, ?int &$status = null): array
    {
        $headers = $this->headers($normalized);
        $response = $this->transport->request($method, $normalized['endpoint'], $path, $headers, $json, $timeoutMs);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            if ($response->statusCode === 401) {
                throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', '服务商未接受 API Key；请在服务商控制台确认 Key 有效且属于此服务地址');
            }
            if ($response->statusCode === 403) {
                throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', '服务商拒绝了账号权限；请确认项目、模型和接口访问权限');
            }
            if ($method === 'GET' && in_array($response->statusCode, [404, 405], true)) {
                throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', '当前服务地址未提供模型列表；请从服务商控制台取得聊天模型标识后手动填写并验证');
            }
            if ($response->statusCode === 429) {
                throw new ApiProblem('SAND_AI_PROVIDER_RATE_LIMITED', '服务商当前限流，请稍后重试', true);
            }
            throw new ApiProblem(
                $response->statusCode >= 500 ? 'SAND_AI_PROVIDER_UNAVAILABLE' : 'SAND_AI_PROVIDER_REJECTED',
                $response->statusCode >= 500 ? '服务商暂时不可用，请稍后重试' : '服务商拒绝了连接或模型请求，请检查 Key 和权限',
                $response->statusCode >= 500,
            );
        }
        $status = $response->statusCode;
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', '服务商返回了无法识别的数据', true);
        }
        if (!is_array($payload)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', '服务商返回了无法识别的数据', true);
        }

        return $payload;
    }

    /** @param array{api_key:string,extra_headers:array<string,string>} $normalized @return array<string,string> */
    private function headers(array $normalized): array
    {
        $headers = ['Authorization' => 'Bearer ' . $normalized['api_key'], 'Accept' => 'application/json'];
        foreach ($normalized['extra_headers'] as $name => $value) {
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function tokenCount(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        return null;
    }
}
