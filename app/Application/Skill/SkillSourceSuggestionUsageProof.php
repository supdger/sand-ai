<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageMeter;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\SkillSourceSuggestion;
use think\facade\Db;

/** Immutable non-content accounting evidence for an already purged suggestion. */
final readonly class SkillSourceSuggestionUsageProof
{
    private function __construct(
        public int $suggestionId,
        public int $invocationId,
        public string $requestFingerprint,
        public string $consumerContextSha256,
        public string $priceSnapshotSha256,
        public ?int $priceRevisionId,
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
        if ($suggestionId < 1 || $invocationId < 1 || $inputTokens < 0 || $outputTokens < 0
            || $inputTokens > PHP_INT_MAX - $outputTokens || $latencyMs < 0 || ($priceRevisionId !== null && $priceRevisionId < 1)
            || !in_array($providerAdapter, ['fake', 'openai_compatible'], true)) self::invalid();
        foreach ([$requestFingerprint, $consumerContextSha256, $priceSnapshotSha256] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) self::invalid();
        }
        foreach ([$provider, $model] as $snapshot) {
            if (array_diff(array_keys($snapshot), ['id', 'code', 'name']) !== [] || count($snapshot) !== 3
                || !is_int($snapshot['id'] ?? null) || $snapshot['id'] < 1
                || !is_string($snapshot['code'] ?? null) || $snapshot['code'] === '' || !is_string($snapshot['name'] ?? null)) self::invalid();
        }
        if (array_diff(array_keys($deployment), ['id', 'remote_model', 'revision', 'route']) !== [] || count($deployment) !== 4
            || !is_int($deployment['id'] ?? null) || $deployment['id'] < 1
            || !is_int($deployment['revision'] ?? null) || $deployment['revision'] < 1
            || !is_string($deployment['remote_model'] ?? null) || $deployment['remote_model'] === ''
            || !is_array($deployment['route'] ?? null)) self::invalid();
        $attributionFields = ['format_version', 'environment_id', 'organization_id', 'application_id', 'workload_client_id', 'identity_snapshot', 'capability_code', 'source_kind', 'service_action'];
        if (array_diff(array_keys($attribution), $attributionFields) !== [] || count($attribution) !== count($attributionFields)
            || !is_int($attribution['environment_id'] ?? null) || !is_string($attribution['source_kind'] ?? null)
            || !is_string($attribution['service_action'] ?? null)) self::invalid();
        try {
            $trusted = UsageAttribution::fromCurrentPersisted($attribution, $attribution['environment_id'], $attribution['source_kind'], $attribution['service_action']);
        } catch (\Throwable) { self::invalid(); }
        if ($trusted->sourceKind !== 'chat' || $trusted->serviceAction !== 'sand_ai.chat.complete' || $trusted->capabilityCode !== 'chat') self::invalid();
    }

    public static function recordPurgedResult(SkillSourceSuggestionContext $originalContext, Invocation $invocation, KnowledgeProviderOutcome $receivedOutcome): self
    {
        return Db::connect('pgsql')->transaction(function () use ($originalContext, $invocation, $receivedOutcome): self {
            $row = SkillSourceSuggestion::where('id', $originalContext->suggestionId)->lock(true)->find();
            if ($row === null || $row->content_purged_time === null
                || (int) $row->invocation_id !== (int) $invocation->id
                || (int) $row->environment_id !== $originalContext->environmentId
                || !hash_equals((string) $row->request_fingerprint, $originalContext->suggestionRequestFingerprint)
                || !hash_equals((string) $row->consumer_context_sha256, $originalContext->consumerContextSha256)
                || !hash_equals((string) $row->evidence_sha256, $originalContext->evidenceSha256)) self::invalid();
            $proof = new self((int) $row->id, (int) $invocation->id, (string) $invocation->request_fingerprint,
                (string) $row->consumer_context_sha256, self::priceHash($invocation),
                $invocation->call_start_price_revision_id === null ? null : (int) $invocation->call_start_price_revision_id,
                $receivedOutcome->inputTokens, $receivedOutcome->outputTokens, $receivedOutcome->usageMeasured,
                $receivedOutcome->latencyMs, $receivedOutcome->attribution, $receivedOutcome->provider,
                $receivedOutcome->model, $receivedOutcome->deployment, $receivedOutcome->providerAdapter);
            $proof->assertInvocation($invocation);
            $stored = $row->accounting_outcome;
            if ($stored !== null && SkillOperationFingerprint::canonicalJson((array) $stored) !== SkillOperationFingerprint::canonicalJson($proof->snapshot())) self::invalid();
            if ($stored === null) $row->save(['accounting_outcome' => $proof->snapshot()]);
            return $proof;
        });
    }

    /** Called with the owner lock held, before destroying an available private receipt. */
    public static function recordReceiptOutcomeForPurge(int $suggestionId, Invocation $invocation, KnowledgeProviderOutcome $receivedOutcome): self
    {
        $row = SkillSourceSuggestion::where('id', $suggestionId)->lock(true)->find();
        if ($row === null || (int) $row->invocation_id !== (int) $invocation->id) self::invalid();
        $proof = new self($suggestionId, (int) $invocation->id, (string) $invocation->request_fingerprint,
            (string) $row->consumer_context_sha256, self::priceHash($invocation),
            $invocation->call_start_price_revision_id === null ? null : (int) $invocation->call_start_price_revision_id,
            $receivedOutcome->inputTokens, $receivedOutcome->outputTokens, $receivedOutcome->usageMeasured,
            $receivedOutcome->latencyMs, $receivedOutcome->attribution, $receivedOutcome->provider,
            $receivedOutcome->model, $receivedOutcome->deployment, $receivedOutcome->providerAdapter);
        $proof->assertBoundInvocation($invocation, false);
        if ($row->accounting_outcome === null) $row->save(['accounting_outcome' => $proof->snapshot()]);
        return $proof;
    }

    public static function fromPersisted(int $suggestionId): self
    {
        $row = SkillSourceSuggestion::where('id', $suggestionId)->find();
        if ($row === null || $row->content_purged_time === null || !is_array($row->accounting_outcome)) self::invalid();
        $value = $row->accounting_outcome;
        $fields = ['version', 'suggestion_id', 'invocation_id', 'request_fingerprint', 'consumer_context_sha256', 'price_snapshot_sha256', 'price_revision_id',
            'input_tokens', 'output_tokens', 'usage_measured', 'latency_ms', 'attribution', 'provider', 'model', 'deployment', 'provider_adapter'];
        if (array_diff(array_keys($value), $fields) !== [] || count($value) !== count($fields) || ($value['version'] ?? null) !== 1) self::invalid();
        foreach (['suggestion_id', 'invocation_id', 'input_tokens', 'output_tokens', 'latency_ms'] as $field) if (!is_int($value[$field])) self::invalid();
        foreach (['request_fingerprint', 'consumer_context_sha256', 'price_snapshot_sha256', 'provider_adapter'] as $field) if (!is_string($value[$field])) self::invalid();
        foreach (['attribution', 'provider', 'model', 'deployment'] as $field) if (!is_array($value[$field])) self::invalid();
        if (!is_bool($value['usage_measured']) || ($value['price_revision_id'] !== null && !is_int($value['price_revision_id']))) self::invalid();
        $proof = new self($value['suggestion_id'], $value['invocation_id'], $value['request_fingerprint'], $value['consumer_context_sha256'],
            $value['price_snapshot_sha256'], $value['price_revision_id'], $value['input_tokens'], $value['output_tokens'], $value['usage_measured'],
            $value['latency_ms'], $value['attribution'], $value['provider'], $value['model'], $value['deployment'], $value['provider_adapter']);
        if ($proof->suggestionId !== $suggestionId) self::invalid();
        $invocation = Invocation::where('id', $proof->invocationId)->find();
        if ($invocation === null) self::invalid();
        $proof->assertInvocation($invocation);
        return $proof;
    }

    public function assertInvocation(Invocation $invocation): void
    {
        $this->assertBoundInvocation($invocation, true);
    }

    private function assertBoundInvocation(Invocation $invocation, bool $requirePurged): void
    {
        $row = SkillSourceSuggestion::where('id', $this->suggestionId)->find();
        if ($row === null || ($requirePurged && $row->content_purged_time === null) || (int) $row->invocation_id !== $this->invocationId
            || (int) $invocation->id !== $this->invocationId || (int) $invocation->environment_id !== (int) $row->environment_id
            || $invocation->request_id !== 'skill-source:v1:' . (int) $row->environment_id . ':' . $this->suggestionId
            || !hash_equals((string) $invocation->request_fingerprint, $this->requestFingerprint)
            || !hash_equals((string) $row->consumer_context_sha256, $this->consumerContextSha256)
            || !hash_equals(hash('sha256', SkillOperationFingerprint::canonicalJson((array) $row->consumer_context)), $this->consumerContextSha256)
            || !hash_equals(self::priceHash($invocation), $this->priceSnapshotSha256)
            || ($invocation->call_start_price_revision_id === null ? null : (int) $invocation->call_start_price_revision_id) !== $this->priceRevisionId
            || (int) $invocation->model_id !== $this->model['id']
            || (int) $invocation->deployment_id !== $this->deployment['id']
            || (int) $invocation->knowledge_publication_id !== (int) $row->knowledge_publication_id
            || (int) $invocation->knowledge_base_version_id !== (int) $row->knowledge_base_version_id
            || $invocation->data_class !== $row->data_class) self::invalid();
        $dispatch = InvocationDispatch::where('invocation_id', $this->invocationId)->find();
        if ($dispatch === null || (int) $dispatch->environment_id !== (int) $row->environment_id
            || (string) $dispatch->execution_scope !== 'chat'
            || (int) $dispatch->provider_id !== $this->provider['id']
            || (int) $dispatch->model_id !== $this->model['id']
            || (int) $dispatch->deployment_id !== $this->deployment['id']
            || (int) $dispatch->application_id !== (int) $this->attribution['application_id']
            || (string) $dispatch->service_action !== $this->attribution['service_action']
            || (string) $dispatch->source_kind !== $this->attribution['source_kind']) self::invalid();
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        if (!is_array($route)) self::invalid();
        DispatchRouteSnapshot::assert($route, false);
        if ($route['provider_id'] !== $this->provider['id'] || $route['model_id'] !== $this->model['id']
            || $route['deployment_id'] !== $this->deployment['id'] || $route['provider_adapter'] !== $this->providerAdapter
            || $route['model_code'] !== $this->model['code']
            || $route['deployment_remote_model'] !== $this->deployment['remote_model']
            || $route['deployment_revision'] !== $this->deployment['revision']
            || (new UsageMeter())->usageRouteSnapshot($route) != $this->deployment['route']) self::invalid();
        $consumer = (array) $row->consumer_context;
        foreach (['organization_id', 'application_id', 'workload_client_id'] as $field) {
            if (($this->attribution[$field] ?? null) !== ($consumer[$field] ?? null)
                || (int) $invocation->getAttr($field) !== ($consumer[$field] ?? null)) self::invalid();
        }
        if (($this->attribution['environment_id'] ?? null) !== (int) $row->environment_id) self::invalid();
        if ($row->accounting_outcome !== null
            && SkillOperationFingerprint::canonicalJson((array) $row->accounting_outcome) !== SkillOperationFingerprint::canonicalJson($this->snapshot())) self::invalid();
    }

    private function snapshot(): array
    {
        return ['version' => 1, 'suggestion_id' => $this->suggestionId, 'invocation_id' => $this->invocationId,
            'request_fingerprint' => $this->requestFingerprint, 'consumer_context_sha256' => $this->consumerContextSha256,
            'price_snapshot_sha256' => $this->priceSnapshotSha256, 'price_revision_id' => $this->priceRevisionId,
            'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'usage_measured' => $this->usageMeasured,
            'latency_ms' => $this->latencyMs, 'attribution' => $this->attribution, 'provider' => $this->provider,
            'model' => $this->model, 'deployment' => $this->deployment, 'provider_adapter' => $this->providerAdapter];
    }

    private static function priceHash(Invocation $invocation): string
    {
        if (!is_array($invocation->call_start_price_snapshot)) self::invalid();
        return hash('sha256', SkillOperationFingerprint::canonicalJson($invocation->call_start_price_snapshot));
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Purged suggestion accounting evidence is invalid');
    }
}
