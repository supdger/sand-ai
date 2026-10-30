<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** Provider result and non-secret accounting facts sealed in a private receipt. */
final readonly class KnowledgeProviderOutcome
{
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public bool $usageMeasured,
        public int $latencyMs,
        public array $attribution,
        public array $provider,
        public array $model,
        public array $deployment,
        public string $providerAdapter,
    ) {
        if ($content === '' || $inputTokens < 0 || $outputTokens < 0
            || $inputTokens > PHP_INT_MAX - $outputTokens || $latencyMs < 0
            || !in_array($providerAdapter, ['fake', 'openai_compatible'], true)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge provider outcome is invalid');
        }
        foreach ([$provider, $model] as $snapshot) {
            if (array_keys($snapshot) !== ['id', 'code', 'name']
                || !is_int($snapshot['id']) || $snapshot['id'] < 1
                || !is_string($snapshot['code']) || $snapshot['code'] === ''
                || !is_string($snapshot['name'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge provider snapshot is invalid');
            }
        }
        if (array_keys($deployment) !== ['id', 'remote_model', 'revision', 'route']
            || !is_int($deployment['id']) || $deployment['id'] < 1
            || !is_int($deployment['revision']) || $deployment['revision'] < 1
            || !is_string($deployment['remote_model']) || $deployment['remote_model'] === ''
            || !is_array($deployment['route'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge deployment snapshot is invalid');
        }
        if (array_keys($attribution) !== ['format_version', 'environment_id', 'organization_id', 'application_id', 'workload_client_id', 'identity_snapshot', 'capability_code', 'source_kind', 'service_action']
            || !is_string($attribution['source_kind']) || !is_string($attribution['service_action'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge attribution is invalid');
        }
        try {
            UsageAttribution::fromCurrentPersisted($attribution, $attribution['environment_id'], $attribution['source_kind'], $attribution['service_action']);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge attribution is invalid');
        }
    }

    /** @param array<string,mixed> $result @param array<string,int|string> $usageRoute */
    public static function fromProviderResult(array $result, UsageAttribution $attribution, Provider $provider, AiModel $model, ModelDeployment $deployment, array $usageRoute): self
    {
        if (!is_string($result['content'] ?? null) || !is_int($result['input_tokens'] ?? null)
            || !is_int($result['output_tokens'] ?? null) || !is_bool($result['usage_measured'] ?? true)
            || !is_int($result['latency_ms'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge provider outcome is invalid');
        }
        return new self(
            $result['content'], $result['input_tokens'], $result['output_tokens'],
            $result['usage_measured'] ?? true, $result['latency_ms'],
            $attribution->toPersisted() + ['source_kind' => $attribution->sourceKind, 'service_action' => $attribution->serviceAction],
            ['id' => (int) $provider->id, 'code' => (string) $provider->code, 'name' => (string) $provider->getAttr('name')],
            ['id' => (int) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->getAttr('name')],
            ['id' => (int) $deployment->id, 'remote_model' => (string) $deployment->remote_model, 'revision' => (int) $deployment->revision, 'route' => $usageRoute],
            (string) $provider->adapter,
        );
    }

    public function encode(): string
    {
        return json_encode([
            'version' => 1, 'content' => $this->content, 'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens, 'usage_measured' => $this->usageMeasured,
            'latency_ms' => $this->latencyMs, 'attribution' => $this->attribution,
            'provider' => $this->provider, 'model' => $this->model,
            'deployment' => $this->deployment, 'provider_adapter' => $this->providerAdapter,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $encoded): self
    {
        try {
            $value = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge provider outcome is invalid');
        }
        if (!is_array($value) || array_keys($value) !== [
            'version', 'content', 'input_tokens', 'output_tokens', 'usage_measured',
            'latency_ms', 'attribution', 'provider', 'model', 'deployment', 'provider_adapter',
        ] || $value['version'] !== 1
            || !is_string($value['content']) || !is_int($value['input_tokens'])
            || !is_int($value['output_tokens']) || !is_bool($value['usage_measured'])
            || !is_int($value['latency_ms']) || !is_array($value['attribution'])
            || !is_array($value['provider']) || !is_array($value['model'])
            || !is_array($value['deployment']) || !is_string($value['provider_adapter'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge provider outcome is invalid');
        }
        return new self(
            $value['content'], $value['input_tokens'], $value['output_tokens'],
            $value['usage_measured'], $value['latency_ms'], $value['attribution'],
            $value['provider'], $value['model'], $value['deployment'], $value['provider_adapter'],
        );
    }
}
