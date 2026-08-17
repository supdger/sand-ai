<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\gateway;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\domain\capability\CapabilityProfilePolicy;
use plugin\SandAi\app\domain\capability\CapabilityProfileResolver;
use plugin\SandAi\app\infrastructure\provider\FakeChatProvider;
use plugin\SandAi\app\infrastructure\provider\OpenAiCompatibleChatProvider;
use plugin\SandAi\app\infrastructure\provider\ProviderSecretDecryptor;
use plugin\SandAi\app\model\AiModel;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\ConfigRevision;
use plugin\SandAi\app\model\Invocation;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\SandAi\app\model\Provider;
use plugin\SandAi\app\model\UsageRecord;

/**
 * Plugin-local chat execution. Model visibility is driven only by a published
 * SandAI capability profile for a SandIAM-authorized environment.
 */
final class ChatGateway
{
    /** @return list<array{id: int, code: string, name: string, type: string, capabilities: array}> */
    public function availableModels(int $environmentId): array
    {
        $routes = (new CapabilityProfileResolver())->routes($environmentId, 'chat');
        $modelIds = [];
        foreach ($routes as $route) {
            if (!isset($route['deployment_id'])) {
                continue;
            }
            $modelId = ModelDeployment::where('id', (int) $route['deployment_id'])->value('model_id');
            if ($modelId !== null) {
                $modelIds[] = (int) $modelId;
            }
        }
        if ($modelIds === []) {
            return [];
        }

        return AiModel::whereIn('id', array_values(array_unique($modelIds)))->where('status', 1)->order('id')->select()
            ->map(fn (AiModel $model): array => [
                'id' => (int) $model->id,
                'code' => (string) $model->code,
                'name' => (string) $model->name,
                'type' => (string) $model->type,
                'capabilities' => $this->stringList($model->capabilities),
            ])->all();
    }

    /** @param list<array{role: string, content: string}> $messages @return array<string, mixed> */
    public function complete(int $environmentId, string $modelCode, array $messages, string $requestId, string $workloadClientId): array
    {
        $existing = Invocation::where('request_id', $requestId)->find();
        if ($existing !== null) {
            if ((int) $existing->environment_id !== $environmentId) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another application environment');
            }
            return ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($existing)];
        }
        $model = AiModel::where('code', $modelCode)->where('status', 1)->find();
        if ($model === null || (string) $model->type !== 'chat') {
            throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'The requested model is not available to this application');
        }
        $deployment = $this->approvedDeployment($environmentId, (int) $model->id);
        $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
        if ($provider === null) {
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'No supported provider is available for this deployment');
        }

        $startedAt = microtime(true);
        $invocation = Invocation::create([
            'request_id' => $requestId,
            'environment_id' => $environmentId,
            'model_id' => $model->id,
            'deployment_id' => $deployment->id,
            'state' => 'running',
        ]);
        try {
            $result = $this->completeWithProvider($provider, $deployment, $messages);
            $latency = (int) round((microtime(true) - $startedAt) * 1000);
            $invocation->save(['state' => 'succeeded', 'latency_ms' => $latency, 'completed_at' => date('Y-m-d H:i:s')]);
            UsageRecord::create([
                'invocation_id' => $invocation->id,
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'total_tokens' => $result['input_tokens'] + $result['output_tokens'],
                'cost_amount' => '0.000000', 'currency' => 'CNY', 'source' => (string) $provider->adapter,
            ]);
            AuditLog::create([
                'actor_type' => 'workload_client', 'actor_ref' => $workloadClientId,
                'action' => 'chat.completion', 'resource_type' => 'invocation',
                'resource_id' => $invocation->id, 'summary' => 'Chat completion succeeded',
                'context' => ['request_id' => $requestId, 'model' => $modelCode],
            ]);
            return [
                'idempotent_replay' => false,
                'invocation' => $this->invocationSummary($invocation),
                'content' => $result['content'],
                'usage' => ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'total_tokens' => $result['input_tokens'] + $result['output_tokens']],
            ];
        } catch (ApiProblem $problem) {
            $invocation->save(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $problem->errorCode, 'error_summary' => $problem->getMessage()]);
            throw $problem;
        } catch (\Throwable) {
            $invocation->save(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => 'SAND_AI_PROVIDER_FAILURE', 'error_summary' => 'Provider execution failed']);
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider could not complete this request', true);
        }
    }

    /** @return array<string, mixed> */
    public function invocation(int $environmentId, string $requestId): array
    {
        $invocation = Invocation::where('environment_id', $environmentId)->where('request_id', $requestId)->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Invocation not found');
        }

        return $this->invocationSummary($invocation);
    }

    /** @return array<string, mixed> */
    private function invocationSummary(Invocation $invocation): array
    {
        return ['request_id' => (string) $invocation->request_id, 'state' => (string) $invocation->state, 'latency_ms' => $invocation->latency_ms, 'error_code' => $invocation->error_code];
    }

    private function approvedDeployment(int $environmentId, int $modelId): ModelDeployment
    {
        foreach ((new CapabilityProfileResolver())->routes($environmentId, 'chat') as $route) {
            $deployment = ModelDeployment::where('id', (int) ($route['deployment_id'] ?? 0))->where('status', 1)->find();
            if ($deployment !== null && (int) $deployment->model_id === $modelId) {
                if (!ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $deployment->id)
                    ->where('revision', (int) $deployment->revision)->where('state', CapabilityProfilePolicy::PUBLISHED)->where('status', 1)->find()) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published deployment is available for this model');
                }
                return $deployment;
            }
        }
        throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'The requested model is not enabled by this capability profile');
    }

    /** @param list<array{role: string, content: string}> $messages @return array{content: string, input_tokens: int, output_tokens: int} */
    private function completeWithProvider(Provider $provider, ModelDeployment $deployment, array $messages): array
    {
        return match ((string) $provider->adapter) {
            'fake' => (new FakeChatProvider())->complete($messages),
            'openai_compatible' => (new OpenAiCompatibleChatProvider())->complete(
                (new ProviderSecretDecryptor())->decrypt($this->providerConfig($provider)),
                (string) $deployment->remote_model, $messages, max(1000, (int) $provider->timeout_ms),
            ),
            default => throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'No supported provider is available for this deployment'),
        };
    }

    /** @return array<string, mixed> */
    private function providerConfig(Provider $provider): array
    {
        $config = is_object($provider->encrypted_config) ? (array) $provider->encrypted_config : $provider->encrypted_config;
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is unavailable');
        }
        return $config;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $items = is_object($value) ? (array) $value : $value;
        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }
}
