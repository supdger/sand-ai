<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\SkillSourceSuggestion;
use think\facade\Db;

/** Removes this derived content owner and its copied provenance, preserving accounting. */
final class SkillSourceSuggestionContentPurgeGateway
{
    public function purge(SkillConsumerContext $consumer, int $suggestionId, string $key): array
    {
        $this->requireSchema();
        $requestKey = $consumer->key($key);
        $fingerprint = SkillOperationFingerprint::for('source_content_purge', [
            'consumer_context' => $consumer->snapshot(), 'suggestion_id' => $suggestionId,
        ]);
        $result = Db::connect('pgsql')->transaction(function () use ($consumer, $suggestionId, $requestKey, $fingerprint): array {
            Db::connect('pgsql')->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,134))', ['skill-source-purge:' . $consumer->identity->environmentId . ':' . $requestKey]);
            $previous = Db::connect('pgsql')->query('SELECT suggestion_id,request_fingerprint FROM sand_ai_skill_source_suggestion_purge WHERE environment_id=? AND request_id=?', [$consumer->identity->environmentId, $requestKey]);
            if ($previous !== [] && ((int) $previous[0]['suggestion_id'] !== $suggestionId || $previous[0]['request_fingerprint'] !== $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Source content purge request changed');
            }
            $row = SkillSourceSuggestion::where('id', $suggestionId)->lock(true)->find();
            $this->assertScope($row, $consumer);
            if ($previous === []) Db::connect('pgsql')->execute('INSERT INTO sand_ai_skill_source_suggestion_purge(environment_id,request_id,request_fingerprint,suggestion_id) VALUES (?,?,?,?)', [$consumer->identity->environmentId, $requestKey, $fingerprint, $suggestionId]);
            return $this->purgeOwner($row, $requestKey, $fingerprint, 'api_credential', $consumer->auditRef(), 'consumer_requested', $consumer->snapshot());
        });
        $accounting = $this->recoverAccounting($suggestionId);
        return $result + ['accounting_state' => $accounting['state']];
    }

    /** Called only by the existing authorized disabled-knowledge purge, with its base lock held. */
    public function purgeForKnowledgeBase(int $knowledgeBaseId, string $operationKey, string $adminActor): array
    {
        $this->requireSchema();
        $db = Db::connect('pgsql');
        $base = $db->query('SELECT id,state,status FROM sand_ai_knowledge_base WHERE id=? AND delete_time IS NULL FOR UPDATE', [$knowledgeBaseId]);
        if (count($base) !== 1 || $base[0]['state'] !== 'disabled' || (int) $base[0]['status'] !== 2
            || $db->query("SELECT 1 FROM sand_ai_knowledge_publication WHERE knowledge_base_id=? AND state='published' LIMIT 1", [$knowledgeBaseId]) !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge sources must be retired before derived content can be purged');
        }
        $ids = $db->query('SELECT id FROM sand_ai_skill_source_suggestion WHERE knowledge_base_id=? ORDER BY id', [$knowledgeBaseId]);
        $count = 0;
        $receipts = 0;
        foreach ($ids as $id) {
            $row = SkillSourceSuggestion::where('id', (int) $id['id'])->lock(true)->find();
            if ($row === null || (int) $row->knowledge_base_id !== $knowledgeBaseId) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Derived source dependency changed');
            $key = hash('sha256', 'knowledge-source-content-purge:v1:' . $knowledgeBaseId . ':' . (int) $row->id . ':' . $operationKey);
            $fingerprint = SkillOperationFingerprint::for('knowledge_source_content_purge', ['knowledge_base_id' => $knowledgeBaseId, 'suggestion_id' => (int) $row->id]);
            $result = $this->purgeOwner($row, $key, $fingerprint, 'admin', $adminActor, 'source_purged', ['knowledge_base_id' => $knowledgeBaseId]);
            if (!$result['idempotent_replay']) $count++;
            $receipts += $result['receipt_ciphertexts_purged'];
        }
        return ['source_suggestions_purged' => $count, 'source_suggestion_receipts_purged' => $receipts];
    }

    /** No network and no live source read; call after committing content deletion. */
    public function recoverForKnowledgeBase(int $knowledgeBaseId): array
    {
        $ids = Db::connect('pgsql')->query('SELECT id FROM sand_ai_skill_source_suggestion WHERE knowledge_base_id=? AND content_purged_time IS NOT NULL ORDER BY id', [$knowledgeBaseId]);
        $pending = 0;
        foreach ($ids as $id) {
            $state = $this->recoverAccounting((int) $id['id']);
            if (in_array($state['state'], ['pending', 'unknown'], true)) $pending++;
        }
        $remaining = Db::connect('pgsql')->query("SELECT count(*) total FROM sand_ai_skill_source_suggestion
            WHERE knowledge_base_id=? AND (content_purged_time IS NULL OR evidence<>'[]'::jsonb OR suggestions IS NOT NULL OR jsonb_exists(input,'query') OR jsonb_exists(input,'reuse_declaration'))", [$knowledgeBaseId]);
        return ['remaining_source_suggestion_content' => (int) ($remaining[0]['total'] ?? 0), 'source_suggestion_accounting_pending' => $pending];
    }

    /** Safe GET projection; unlike recovery it never consumes receipts or changes accounting. */
    public function readPurgedState(SkillConsumerContext $consumer, int $suggestionId): array
    {
        $row = SkillSourceSuggestion::where('id', $suggestionId)->find();
        $this->assertScope($row, $consumer);
        $consumer->assertRun($row->consumer_context);
        if ($row->content_purged_time === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Source suggestion is not purged');
        return $this->summary($row, true, 0, 0) + ['accounting_state' => $this->accountingState($row)];
    }

    public function recoverPurgedAccounting(SkillConsumerContext $consumer, int $suggestionId): array
    {
        $row = SkillSourceSuggestion::where('id', $suggestionId)->find();
        $this->assertScope($row, $consumer);
        if ($row->content_purged_time === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Source suggestion is not purged');
        $consumer->assertRun($row->consumer_context);
        $state = $this->recoverAccounting($suggestionId);
        return $this->summary($row, true, 0, 0) + ['accounting_state' => $state['state']];
    }

    private function purgeOwner(SkillSourceSuggestion $row, string $requestKey, string $fingerprint, string $actorType, string $actorRef, string $reason, array $auditContext): array
    {
        $db = Db::connect('pgsql');
        $replay = $row->content_purged_time !== null;
        $invocationId = (int) ($row->invocation_id ?? 0);
        $ciphertextsBefore = $this->remainingCiphertexts($invocationId);
        if ($invocationId > 0) {
            $invocation = Invocation::where('id', $invocationId)->find();
            if ($invocation === null || (int) $invocation->environment_id !== (int) $row->environment_id) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Source suggestion invocation is unavailable');
            $outcome = $this->receipts()->readSkillSourceSuggestionOutcomeForPurge($invocationId, (int) $row->id);
            if ($outcome !== null) SkillSourceSuggestionUsageProof::recordReceiptOutcomeForPurge((int) $row->id, $invocation, $outcome);
        }
        $now = gmdate('Y-m-d H:i:s');
        if (!$replay) {
            $row->save(['content_purged_time' => $now, 'evidence' => [], 'suggestions' => null,
                'input' => array_diff_key((array) $row->input, ['query' => true, 'reuse_declaration' => true]),
                'purge_request_id' => $requestKey, 'purge_request_fingerprint' => $fingerprint,
                'purged_actor_type' => $actorType, 'purged_actor_ref' => $actorRef, 'purge_reason' => $reason]);
        }
        $tombstone = $this->tombstone($row);
        $versions = $db->execute('UPDATE sand_ai_skill_version SET provenance=CAST(? AS jsonb),source_provenance_purged_time=?
            WHERE source_suggestion_id=? AND source_provenance_purged_time IS NULL',
            [json_encode($tombstone, JSON_THROW_ON_ERROR), (string) $row->content_purged_time, (int) $row->id]);
        if ($invocationId > 0) $this->receipts()->destroySkillSourceSuggestion($invocationId, (int) $row->id, (string) $row->content_purged_time);
        $remaining = $this->remainingCiphertexts($invocationId);
        if ($remaining !== 0) throw new ApiProblem('SAND_AI_RECEIPT_PERSISTENCE_FAILED', 'Source suggestion private receipt was not destroyed', true);
        if (!$replay) AuditLog::create(['actor_type' => $actorType, 'actor_ref' => $actorRef,
            'action' => 'sand_ai.skill.source_content.purge', 'resource_type' => 'skill_source_suggestion', 'resource_id' => (int) $row->id,
            'summary' => 'Derived method content and copied provenance purged', 'context' => $auditContext + [
                'scope_source' => 'consumer_declared', 'reason' => $reason, 'invocation_id' => $invocationId ?: null,
                'provenance_versions_purged' => $versions, 'receipt_ciphertexts_purged' => $ciphertextsBefore]]);
        $result = $this->summary($row, $replay, $versions, $ciphertextsBefore);
        if ($result['remaining_private_fields'] !== 0) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Source suggestion private copies were not fully purged');
        return $result;
    }

    private function recoverAccounting(int $suggestionId): array
    {
        $row = SkillSourceSuggestion::where('id', $suggestionId)->find();
        if ($row === null || $row->content_purged_time === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Purged source suggestion not found');
        if ((int) ($row->invocation_id ?? 0) === 0) return ['state' => 'not_invoked'];
        if ($row->accounting_outcome !== null) {
            try {
                $proof = SkillSourceSuggestionUsageProof::fromPersisted($suggestionId);
                $recovered = (new ChatGateway())->recoverPurgedSkillSourceAccounting($proof);
                $usage = is_array($recovered['usage'] ?? null) ? $recovered['usage'] : null;
                if ($usage !== null) {
                    $db = Db::connect('pgsql');
                    $db->transaction(function () use ($suggestionId, $usage): void {
                        $owner = SkillSourceSuggestion::where('id', $suggestionId)->lock(true)->find();
                        if ($owner === null || $owner->content_purged_time === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Purged source owner changed');
                        if ($owner->usage === null) $owner->save(['usage' => $usage]);
                    });
                }
            } catch (ApiProblem) { return ['state' => 'pending']; }
        }
        return ['state' => $this->accountingState($row)];
    }

    private function accountingState(SkillSourceSuggestion $row): string
    {
        if ((int) ($row->invocation_id ?? 0) === 0) return 'not_invoked';
        $event = Db::connect('pgsql')->query('SELECT event.metered_amount,event.pricing_state,invocation.state invocation_state
            FROM sand_ai_usage_event event JOIN sand_ai_invocation invocation ON invocation.id=event.invocation_id
            WHERE event.invocation_id=? ORDER BY event.id DESC LIMIT 1', [(int) $row->invocation_id]);
        if ($event === []) return $row->accounting_outcome === null ? 'unknown' : 'pending';
        return $event[0]['invocation_state'] === 'succeeded' && $event[0]['pricing_state'] === 'priced' && $event[0]['metered_amount'] !== null ? 'settled' : 'unknown';
    }

    private function summary(SkillSourceSuggestion $row, bool $replay, int $versions, int $receipts): array
    {
        $remaining = Db::connect('pgsql')->query("SELECT (CASE WHEN evidence<>'[]'::jsonb THEN 1 ELSE 0 END
            + CASE WHEN suggestions IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN jsonb_exists(input,'query') THEN 1 ELSE 0 END
            + CASE WHEN jsonb_exists(input,'reuse_declaration') THEN 1 ELSE 0 END
            + (SELECT count(*) FROM sand_ai_skill_version WHERE source_suggestion_id=? AND source_provenance_purged_time IS NULL)) remaining
            FROM sand_ai_skill_source_suggestion WHERE id=?", [(int) $row->id, (int) $row->id]);
        return ['suggestion_id' => (int) $row->id, 'state' => 'content_purged', 'content_purged_at' => $row->content_purged_time,
            'idempotent_replay' => $replay, 'remaining_private_fields' => (int) ($remaining[0]['remaining'] ?? -1),
            'remaining_receipt_ciphertexts' => $this->remainingCiphertexts((int) ($row->invocation_id ?? 0)),
            'provenance_versions_purged' => $versions, 'receipt_ciphertexts_purged' => $receipts];
    }

    private function tombstone(SkillSourceSuggestion $row): array
    {
        return ['source_suggestion_id' => (int) $row->id, 'invocation_id' => $row->invocation_id === null ? null : (int) $row->invocation_id,
            'knowledge_base_id' => (int) $row->knowledge_base_id, 'knowledge_base_version_id' => (int) $row->knowledge_base_version_id,
            'knowledge_publication_id' => (int) $row->knowledge_publication_id, 'snapshot_sha256' => $row->input['snapshot_sha256'],
            'consumer_context_sha256' => $row->consumer_context_sha256, 'evidence_sha256' => $row->evidence_sha256, 'content_purged' => true];
    }

    private function remainingCiphertexts(int $invocationId): int
    {
        if ($invocationId === 0) return 0;
        $rows = Db::connect('pgsql')->query("SELECT count(*) total FROM sand_ai_invocation_outcome_receipt
            WHERE invocation_id=? AND capability_code='skill_source_suggestion' AND (ciphertext IS NOT NULL OR nonce IS NOT NULL OR tag IS NOT NULL OR content_hmac IS NOT NULL)", [$invocationId]);
        return (int) ($rows[0]['total'] ?? 0);
    }

    private function assertScope(?SkillSourceSuggestion $row, SkillConsumerContext $consumer): void
    {
        $stored = $row?->consumer_context;
        $current = $consumer->snapshot();
        if (!is_array($stored) || (int) $row->environment_id !== $consumer->identity->environmentId
            || ($stored['scope_source'] ?? null) !== 'consumer_declared'
            || ($stored['organization_id'] ?? null) !== $current['organization_id']
            || ($stored['application_id'] ?? null) !== $current['application_id']
            || ($stored['environment_id'] ?? null) !== $current['environment_id']
            || ($stored['scope']['type'] ?? null) !== $consumer->scopeType || ($stored['scope']['ref'] ?? null) !== $consumer->scopeRef) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Source suggestion is unavailable in this consumer scope');
        }
    }

    private function receipts(): OutcomeReceiptService
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Source suggestion receipt configuration is unavailable');
        return new OutcomeReceiptService(new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0));
    }

    private function requireSchema(): void
    {
        $rows = Db::connect('pgsql')->query("SELECT count(*) total FROM information_schema.columns WHERE table_schema=current_schema()
            AND ((table_name='sand_ai_skill_source_suggestion' AND column_name IN ('knowledge_base_id','knowledge_base_version_id','knowledge_publication_id','data_class','accounting_outcome','purge_request_id'))
              OR (table_name='sand_ai_skill_version' AND column_name IN ('source_suggestion_id','source_provenance_purged_time'))
              OR (table_name='sand_ai_skill_source_suggestion_purge' AND column_name='request_fingerprint'))");
        if ((int) ($rows[0]['total'] ?? 0) !== 9) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Source suggestion lifecycle schema is not installed');
    }
}
