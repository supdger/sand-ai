<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageMeter;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** Private query vector and metering facts sealed before finalizing its invocation. */
final readonly class KnowledgeQueryEmbeddingOutcome
{
    /** @param list<float> $vector
     *  @param array<string,mixed> $attribution
     *  @param array<string,mixed> $provider
     *  @param array<string,mixed> $model
     *  @param array<string,mixed> $deployment
     */
    public function __construct(
        public int $publicationId,
        public int $versionId,
        public int $indexId,
        public string $querySha256,
        public array $vector,
        public int $inputTokens,
        public bool $usageMeasured,
        public int $latencyMs,
        public array $attribution,
        public array $provider,
        public array $model,
        public array $deployment,
    ) {
        if ($publicationId < 1 || $versionId < 1 || $indexId < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $querySha256) !== 1
            || !array_is_list($vector) || count($vector) < 1 || count($vector) > 4096
            || $inputTokens < 0 || $latencyMs < 0
            || ($attribution['capability_code'] ?? null) !== 'embedding'
            || ($attribution['source_kind'] ?? null) !== 'knowledge_query'
            || ($attribution['service_action'] ?? null) !== 'sand_ai.knowledge.query'
            || (int) ($provider['id'] ?? 0) < 1 || (int) ($model['id'] ?? 0) < 1
            || (int) ($deployment['id'] ?? 0) < 1 || !is_array($deployment['route'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector receipt is invalid');
        }
        $norm = 0.0;
        foreach ($vector as $number) {
            if (!is_float($number) || !is_finite($number) || abs($number) > 1.000001) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector is invalid');
            }
            $norm += $number * $number;
        }
        if ($norm < 0.999 || $norm > 1.001) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query vector is not normalized');
        }
        try {
            UsageAttribution::fromCurrentPersisted(
                $attribution, (int) ($attribution['environment_id'] ?? 0),
                'knowledge_query', 'sand_ai.knowledge.query',
            );
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query attribution is invalid');
        }
    }

    /** @param array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} $result
     *  @param array<string,mixed> $routeSnapshot
     */
    public static function fromProvider(
        int $publicationId, int $versionId, int $indexId, string $querySha256,
        array $result, int $latencyMs, UsageAttribution $attribution,
        Provider $provider, AiModel $model, ModelDeployment $deployment, array $routeSnapshot,
    ): self {
        if (count($result['vectors'] ?? []) !== 1
            || !is_int($result['input_tokens'] ?? null)
            || !is_bool($result['usage_measured'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query provider result is incomplete');
        }
        return new self(
            $publicationId, $versionId, $indexId, $querySha256, $result['vectors'][0],
            $result['input_tokens'], $result['usage_measured'], $latencyMs,
            $attribution->toPersisted() + [
                'source_kind' => $attribution->sourceKind,
                'service_action' => $attribution->serviceAction,
            ],
            ['id' => (int) $provider->id, 'code' => (string) $provider->code,
                'name' => (string) $provider->getAttr('name')],
            ['id' => (int) $model->id, 'code' => (string) $model->code,
                'name' => (string) $model->getAttr('name')],
            ['id' => (int) $deployment->id, 'remote_model' => (string) $deployment->remote_model,
                'revision' => (int) $deployment->revision,
                'route' => (new UsageMeter())->usageRouteSnapshot($routeSnapshot)],
        );
    }

    public function encode(): string
    {
        return json_encode([
            'version' => 1, 'publication_id' => $this->publicationId,
            'version_id' => $this->versionId, 'index_id' => $this->indexId,
            'query_sha256' => $this->querySha256, 'vector' => $this->vector,
            'input_tokens' => $this->inputTokens, 'usage_measured' => $this->usageMeasured,
            'latency_ms' => $this->latencyMs, 'attribution' => $this->attribution,
            'provider' => $this->provider, 'model' => $this->model,
            'deployment' => $this->deployment,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $encoded): self
    {
        try {
            $value = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query receipt is invalid');
        }
        if (!is_array($value) || array_keys($value) !== [
            'version', 'publication_id', 'version_id', 'index_id', 'query_sha256',
            'vector', 'input_tokens', 'usage_measured', 'latency_ms',
            'attribution', 'provider', 'model', 'deployment',
        ] || $value['version'] !== 1 || !is_int($value['publication_id'])
            || !is_int($value['version_id']) || !is_int($value['index_id'])
            || !is_string($value['query_sha256']) || !is_array($value['vector'])
            || !is_int($value['input_tokens']) || !is_bool($value['usage_measured'])
            || !is_int($value['latency_ms']) || !is_array($value['attribution'])
            || !is_array($value['provider']) || !is_array($value['model'])
            || !is_array($value['deployment'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge query receipt is invalid');
        }
        return new self(
            $value['publication_id'], $value['version_id'], $value['index_id'],
            $value['query_sha256'], $value['vector'], $value['input_tokens'],
            $value['usage_measured'], $value['latency_ms'], $value['attribution'],
            $value['provider'], $value['model'], $value['deployment'],
        );
    }
}
