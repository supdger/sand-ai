<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeAnswerAttribution;
use app\Application\Knowledge\KnowledgeBaseQueryGateway;
use app\Application\Knowledge\KnowledgeSearchExpression;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\SkillSourceSuggestion;

/** Internal proof built only from an authorized persisted suggestion and its live sources. */
final readonly class SkillSourceSuggestionContext
{
    private function __construct(
        public int $suggestionId,
        public int $environmentId,
        public string $suggestionRequestFingerprint,
        public string $consumerContextSha256,
        public string $evidenceSha256,
        public int $knowledgePublicationId,
        public int $knowledgeBaseVersionId,
        public string $knowledgeSnapshotSha256,
        public string $knowledgeSourceScopeSha256,
        public ?string $dataClass,
    ) {
    }

    public static function fromPersisted(SkillConsumerContext $consumer, int $suggestionId): self
    {
        $row = self::owner($suggestionId);
        $consumer->assertRun($row->consumer_context);
        if ((int) $row->environment_id !== $consumer->identity->environmentId) self::invalid();
        $proof = self::proof($row);
        return new self($suggestionId, (int) $row->environment_id, (string) $row->request_fingerprint,
            $proof['consumer_sha256'], $proof['evidence_sha256'], $proof['knowledge']['publication_id'],
            $proof['version_id'], $proof['snapshot_sha256'], $proof['knowledge']['source_scope_sha256'], $proof['knowledge']['data_class']);
    }

    public function fingerprint(): array
    {
        return [
            'suggestion_id' => $this->suggestionId, 'environment_id' => $this->environmentId,
            'suggestion_request_fingerprint' => $this->suggestionRequestFingerprint,
            'consumer_context_sha256' => $this->consumerContextSha256, 'evidence_sha256' => $this->evidenceSha256,
            'knowledge_publication_id' => $this->knowledgePublicationId, 'knowledge_base_version_id' => $this->knowledgeBaseVersionId,
            'knowledge_snapshot_sha256' => $this->knowledgeSnapshotSha256,
            'knowledge_source_scope_sha256' => $this->knowledgeSourceScopeSha256, 'data_class' => $this->dataClass,
        ];
    }

    public function knowledgeAttribution(): KnowledgeAnswerAttribution
    {
        return new KnowledgeAnswerAttribution($this->knowledgePublicationId, $this->knowledgeBaseVersionId,
            $this->knowledgeSourceScopeSha256, $this->dataClass);
    }

    public function assertAttribution(UsageAttribution $attribution): void
    {
        $row = self::owner($this->suggestionId);
        $this->assertProof($row);
        $consumer = (array) $row->consumer_context;
        if ($attribution->environmentId !== $this->environmentId
            || $attribution->organizationId !== ($consumer['organization_id'] ?? null)
            || $attribution->applicationId !== ($consumer['application_id'] ?? null)
            || $attribution->workloadClientId !== ($consumer['workload_client_id'] ?? null)
            || $attribution->sourceKind !== 'chat' || $attribution->serviceAction !== 'sand_ai.chat.complete'
            || $attribution->capabilityCode !== 'chat') self::invalid();
    }

    /** Called inside Chat's accepting transaction before dispatch can become sendable. */
    public function bindInvocation(Invocation $invocation): void
    {
        $row = SkillSourceSuggestion::where('id', $this->suggestionId)->lock(true)->find();
        if ($row === null || $row->state !== 'generating') self::invalid();
        $this->assertProof($row);
        $this->assertInvocationOwnership($row, $invocation);
        $bound = (int) ($row->invocation_id ?? 0);
        if ($bound !== 0 && $bound !== (int) $invocation->id) self::invalid();
        if ($bound === 0) $row->save(['invocation_id' => (int) $invocation->id]);
    }

    public function assertInvocation(Invocation $invocation): void
    {
        $row = self::owner($this->suggestionId);
        $this->assertProof($row);
        $this->assertInvocationOwnership($row, $invocation);
        if ((int) $row->invocation_id !== (int) $invocation->id) self::invalid();
    }

    private function assertInvocationOwnership(SkillSourceSuggestion $row, Invocation $invocation): void
    {
        $consumer = (array) $row->consumer_context;
        if ((int) $invocation->id < 1 || (int) $invocation->environment_id !== $this->environmentId
            || (int) $invocation->organization_id !== ($consumer['organization_id'] ?? null)
            || (int) $invocation->application_id !== ($consumer['application_id'] ?? null)
            || (int) $invocation->workload_client_id !== ($consumer['workload_client_id'] ?? null)
            || (int) $invocation->knowledge_publication_id !== $this->knowledgePublicationId
            || (int) $invocation->knowledge_base_version_id !== $this->knowledgeBaseVersionId
            || $invocation->knowledge_source_scope_sha256 !== $this->knowledgeSourceScopeSha256
            || $invocation->data_class !== $this->dataClass
            || $invocation->request_id !== 'skill-source:v1:' . $this->environmentId . ':' . $this->suggestionId) self::invalid();
    }

    private function assertProof(SkillSourceSuggestion $row): void
    {
        $proof = self::proof($row);
        if ((int) $row->environment_id !== $this->environmentId
            || !hash_equals((string) $row->request_fingerprint, $this->suggestionRequestFingerprint)
            || !hash_equals($proof['consumer_sha256'], $this->consumerContextSha256)
            || !hash_equals($proof['evidence_sha256'], $this->evidenceSha256)
            || $proof['version_id'] !== $this->knowledgeBaseVersionId
            || $proof['snapshot_sha256'] !== $this->knowledgeSnapshotSha256
            || $proof['knowledge']['publication_id'] !== $this->knowledgePublicationId
            || $proof['knowledge']['source_scope_sha256'] !== $this->knowledgeSourceScopeSha256
            || $proof['knowledge']['data_class'] !== $this->dataClass) self::invalid();
    }

    private static function owner(int $id): SkillSourceSuggestion
    {
        $row = $id > 0 ? SkillSourceSuggestion::where('id', $id)->find() : null;
        if ($row === null) self::invalid();
        return $row;
    }

    private static function proof(SkillSourceSuggestion $row): array
    {
        if ($row->content_purged_time !== null) throw new ApiProblem('SAND_AI_SKILL_SOURCE_CONTENT_PURGED', 'Source suggestion content was purged');
        if (!in_array($row->state, ['generating', 'pending_review', 'adopted', 'failed'], true)
            || $row->content_purged_time !== null || !is_array($row->input) || !is_array($row->evidence)
            || !is_array($row->consumer_context)) self::invalid();
        $input = $row->input;
        if (!is_int($input['knowledge_base_version_id'] ?? null) || !is_string($input['snapshot_sha256'] ?? null)
            || !is_string($input['query'] ?? null) || $row->evidence === []) self::invalid();
        // Missing metadata belongs to the frozen 0.1.3 order. A present null,
        // unknown value or wrong type must fail closed, never pick a default.
        $retrievalAlgorithm = array_key_exists('retrieval_algorithm', $input)
            ? $input['retrieval_algorithm'] : KnowledgeSearchExpression::LEGACY_ALGORITHM;
        if (!is_string($retrievalAlgorithm)
            || !in_array($retrievalAlgorithm, [
                KnowledgeSearchExpression::LEGACY_ALGORITHM,
                KnowledgeSearchExpression::CURRENT_ALGORITHM,
            ], true)) self::invalid();
        $knowledge = (new SkillKnowledgePublication())->resolve((int) $row->environment_id,
            $input['knowledge_base_version_id'], $input['snapshot_sha256']);
        if ((int) $row->knowledge_base_id !== $knowledge['knowledge_base_id']
            || (int) $row->knowledge_base_version_id !== $input['knowledge_base_version_id']
            || (int) $row->knowledge_publication_id !== $knowledge['publication_id']
            || $row->data_class !== $knowledge['data_class']) self::invalid();
        $actual = (new KnowledgeBaseQueryGateway())->queryVersion($knowledge['owner_environment_id'],
            $input['knowledge_base_version_id'], $input['query'], ['limit' => 5], $retrievalAlgorithm);
        if (!hash_equals((string) $row->consumer_context_sha256, hash('sha256', SkillOperationFingerprint::canonicalJson($row->consumer_context)))
            || !hash_equals((string) $row->evidence_sha256, hash('sha256', SkillOperationFingerprint::canonicalJson($row->evidence)))) self::invalid();
        if (SkillOperationFingerprint::canonicalJson($actual['items'] ?? []) !== SkillOperationFingerprint::canonicalJson($row->evidence)) self::invalid();
        return ['consumer_sha256' => hash('sha256', SkillOperationFingerprint::canonicalJson($row->consumer_context)),
            'evidence_sha256' => hash('sha256', SkillOperationFingerprint::canonicalJson($row->evidence)),
            'version_id' => $input['knowledge_base_version_id'], 'snapshot_sha256' => $input['snapshot_sha256'], 'knowledge' => $knowledge];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Source suggestion ownership or frozen evidence is unavailable');
    }
}
