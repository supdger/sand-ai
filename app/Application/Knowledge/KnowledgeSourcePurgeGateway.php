<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Skill\SkillSourceSuggestionContentPurgeGateway;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

/** Releases immutable source references only after the entire base is retired. */
final class KnowledgeSourcePurgeGateway
{
    /** @return array{knowledge_base_id:int,state:string,remaining_sources:int,remaining_conversations:int,idempotent_replay:bool} */
    public function purgeDisabled(int $environmentId, int $knowledgeBaseId, string $requestId, string $actorRef): array
    {
        if ($environmentId < 1 || $knowledgeBaseId < 1 || trim($requestId) === ''
            || strlen($requestId) > 128 || trim($actorRef) === '') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge source purge request is invalid');
        }
        try {
            (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        $derived = new SkillSourceSuggestionContentPurgeGateway();
        $result = Db::connect('pgsql')->transaction(function () use (
            $environmentId, $knowledgeBaseId, $requestId, $actorRef, $derived,
        ): array {
            $db = Db::connect('pgsql');
            $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                $environmentId . ':knowledge-operation:purge_sources:' . $requestId,
            ]);
            $previous = $db->query(
                "SELECT knowledge_base_id FROM sand_ai_knowledge_operation
                 WHERE environment_id=? AND operation_code='purge_sources' AND idempotency_key=? FOR UPDATE",
                [$environmentId, $requestId],
            );
            if ($previous !== []) {
                if ((int) $previous[0]['knowledge_base_id'] !== $knowledgeBaseId) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Knowledge source purge request changed');
                }
                $replayBase = $db->query(
                    'SELECT id,state,status FROM sand_ai_knowledge_base WHERE id=? AND environment_id=? FOR UPDATE',
                    [$knowledgeBaseId, $environmentId],
                );
                if (count($replayBase) !== 1) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge base not found');
                }
                $remainingSources = $db->query(
                    'SELECT 1 FROM sand_ai_knowledge_base_source source
                     JOIN sand_ai_knowledge_base_version version ON version.id=source.knowledge_base_version_id
                     WHERE version.knowledge_base_id=? LIMIT 1',
                    [$knowledgeBaseId],
                );
                if ($replayBase[0]['state'] !== 'disabled' || (int) $replayBase[0]['status'] !== 2
                    || $remainingSources !== []) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge base changed after source purge');
                }
                $receipts = $this->purgeAnswerReceipts($knowledgeBaseId);
                return $this->summary($knowledgeBaseId, true)
                    + ['answer_receipts_purged' => $receipts]
                    + $derived->purgeForKnowledgeBase($knowledgeBaseId, $requestId, $actorRef);
            }
            $base = $db->query(
                'SELECT id,state,status,active_version_id FROM sand_ai_knowledge_base
                 WHERE id=? AND environment_id=? AND delete_time IS NULL FOR UPDATE',
                [$knowledgeBaseId, $environmentId],
            );
            if (count($base) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge base not found');
            }
            if ($base[0]['state'] !== 'disabled' || (int) $base[0]['status'] !== 2) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Disable the knowledge base before purging sources');
            }
            $published = $db->query(
                "SELECT 1 FROM sand_ai_knowledge_publication
                 WHERE knowledge_base_id=? AND state='published' LIMIT 1",
                [$knowledgeBaseId],
            );
            if ($published !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Revoke every application publication before purging sources');
            }
            $indexes = $db->query(
                'SELECT 1 FROM sand_ai_knowledge_embedding_index i
                 JOIN sand_ai_knowledge_base_version v ON v.id=i.knowledge_base_version_id
                 WHERE v.knowledge_base_id=? LIMIT 1',
                [$knowledgeBaseId],
            );
            if ($indexes !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Purge knowledge vectors before purging sources');
            }
            $pending = $db->query(
                "SELECT 1 FROM sand_ai_knowledge_conversation_turn t
                 JOIN sand_ai_knowledge_conversation c ON c.id=t.conversation_id
                 JOIN sand_ai_knowledge_publication p ON p.id=c.publication_id
                 WHERE p.knowledge_base_id=? AND t.state='pending' LIMIT 1",
                [$knowledgeBaseId],
            );
            if ($pending !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or reconcile pending knowledge answers before purging sources');
            }
            // Change the locked owner's row version as well as taking its lock:
            // a receipt writer using an older repeatable-read snapshot must
            // abort rather than observe pre-purge source rows after waiting.
            $db->execute(
                'UPDATE sand_ai_knowledge_base SET update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND environment_id=?',
                [$knowledgeBaseId, $environmentId],
            );
            $receipts = $this->purgeAnswerReceipts($knowledgeBaseId);
            $derivedResult = $derived->purgeForKnowledgeBase($knowledgeBaseId, $requestId, $actorRef);
            $turns = $db->execute(
                'DELETE FROM sand_ai_knowledge_conversation_turn t
                 USING sand_ai_knowledge_conversation c,sand_ai_knowledge_publication p
                 WHERE t.conversation_id=c.id AND c.publication_id=p.id AND p.knowledge_base_id=?',
                [$knowledgeBaseId],
            );
            $conversations = $db->execute(
                'DELETE FROM sand_ai_knowledge_conversation c
                 USING sand_ai_knowledge_publication p
                 WHERE c.publication_id=p.id AND p.knowledge_base_id=?',
                [$knowledgeBaseId],
            );
            $sources = $db->execute(
                'DELETE FROM sand_ai_knowledge_base_source s
                 USING sand_ai_knowledge_base_version v
                 WHERE s.knowledge_base_version_id=v.id AND v.knowledge_base_id=?',
                [$knowledgeBaseId],
            );
            $db->execute(
                "INSERT INTO sand_ai_knowledge_operation
                 (environment_id,operation_code,idempotency_key,request_fingerprint,knowledge_base_id,version_id)
                 VALUES (?,'purge_sources',?,?,?,?)",
                [$environmentId, $requestId, hash('sha256', (string) $knowledgeBaseId),
                    $knowledgeBaseId, $base[0]['active_version_id']],
            );
            AuditLog::create([
                'actor_type' => 'admin',
                'actor_ref' => trim($actorRef),
                'action' => 'knowledge.sources.purge',
                'resource_type' => 'knowledge_base',
                'resource_id' => $knowledgeBaseId,
                'summary' => 'Disabled knowledge source references and conversations purged',
                'context' => [
                    'environment_id' => $environmentId,
                    'request_id' => $requestId, 'request_fingerprint' => hash('sha256', (string) $knowledgeBaseId),
                    'source_references_purged' => $sources,
                    'conversations_purged' => $conversations,
                    'turns_purged' => $turns,
                    'answer_receipts_purged' => $receipts,
                ],
            ]);
            return $this->summary($knowledgeBaseId, false)
                + ['answer_receipts_purged' => $receipts] + $derivedResult;
        });
        return $result + $derived->recoverForKnowledgeBase($knowledgeBaseId);
    }

    /**
     * Covers single answers, conversation answers and every summary call using
     * their typed publication/version facts, including after content rows vanish.
     * The caller holds the knowledge base lock in the source purge transaction.
     */
    private function purgeAnswerReceipts(int $knowledgeBaseId): int
    {
        $db = Db::connect('pgsql');
        $pending = $db->query(
            "SELECT 1 FROM sand_ai_invocation invocation
             LEFT JOIN sand_ai_knowledge_base_version version
               ON version.id=invocation.knowledge_base_version_id
             LEFT JOIN sand_ai_knowledge_publication publication
               ON publication.id=invocation.knowledge_publication_id
             LEFT JOIN sand_ai_invocation_dispatch dispatch ON dispatch.invocation_id=invocation.id
             WHERE (version.knowledge_base_id=? OR publication.knowledge_base_id=?)
               AND NOT EXISTS (
                   SELECT 1 FROM sand_ai_skill_source_suggestion suggestion
                   WHERE suggestion.invocation_id=invocation.id
               )
               AND (invocation.state NOT IN ('succeeded','failed')
                 OR dispatch.state IN ('prepared','dispatching','ambiguous'))
             LIMIT 1",
            [$knowledgeBaseId, $knowledgeBaseId],
        );
        if ($pending !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or reconcile knowledge invocations before purging their results');
        }
        $receipts = $db->query(
            "SELECT receipt.id,receipt.state,receipt.environment_id,receipt.model_id,
                    receipt.deployment_id,receipt.request_fingerprint,
                    invocation.environment_id AS invocation_environment_id,
                    invocation.model_id AS invocation_model_id,
                    invocation.deployment_id AS invocation_deployment_id,
                    invocation.request_fingerprint AS invocation_request_fingerprint,
                    invocation.knowledge_source_scope_sha256,
                    publication.consumer_environment_id,publication.knowledge_base_id AS publication_base_id,
                    version.knowledge_base_id AS version_base_id,version.source_scope_sha256
             FROM sand_ai_invocation_outcome_receipt receipt
             JOIN sand_ai_invocation invocation ON invocation.id=receipt.invocation_id
             LEFT JOIN sand_ai_knowledge_publication publication
               ON publication.id=invocation.knowledge_publication_id
             LEFT JOIN sand_ai_knowledge_base_version version
               ON version.id=invocation.knowledge_base_version_id
             WHERE receipt.capability_code='knowledge_answer'
               AND (version.knowledge_base_id=? OR publication.knowledge_base_id=?)
             ORDER BY receipt.id FOR UPDATE OF receipt",
            [$knowledgeBaseId, $knowledgeBaseId],
        );
        $count = 0;
        foreach ($receipts as $receipt) {
            if ((int) $receipt['publication_base_id'] !== $knowledgeBaseId
                || (int) $receipt['version_base_id'] !== $knowledgeBaseId
                || (int) $receipt['environment_id'] !== (int) $receipt['invocation_environment_id']
                || (int) $receipt['environment_id'] !== (int) $receipt['consumer_environment_id']
                || (int) $receipt['model_id'] !== (int) $receipt['invocation_model_id']
                || (int) $receipt['deployment_id'] !== (int) $receipt['invocation_deployment_id']
                || !is_string($receipt['source_scope_sha256'])
                || !hash_equals($receipt['source_scope_sha256'], (string) $receipt['knowledge_source_scope_sha256'])
                || !hash_equals((string) $receipt['request_fingerprint'], (string) $receipt['invocation_request_fingerprint'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer receipt ownership is unavailable');
            }
            if ($receipt['state'] === 'destroyed') continue;
            // Advance the fence for both available receipts and an older cleanup
            // claim. The immutable receipt trigger requires this two-step path.
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt
                 SET state='expired',cleanup_owner='sand-ai-knowledge-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND capability_code='knowledge_answer' AND state IN ('available','expired')",
                [(int) $receipt['id']],
            );
            $destroyed = $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND capability_code='knowledge_answer' AND state='expired'
                   AND cleanup_owner='sand-ai-knowledge-purge'",
                [(int) $receipt['id']],
            );
            if ($destroyed !== 1) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer receipt changed during purge');
            }
            $count += $destroyed;
        }
        if ($count > 0) {
            AuditLog::create([
                'actor_type' => 'system',
                'actor_ref' => 'sand_ai_knowledge_source_purge',
                'action' => 'knowledge.answer_receipts.purge',
                'resource_type' => 'knowledge_base',
                'resource_id' => $knowledgeBaseId,
                'summary' => 'Private knowledge answer receipt ciphertext destroyed',
                'context' => ['answer_receipts_purged' => $count],
            ]);
        }
        return $count;
    }

    /** @return array{knowledge_base_id:int,state:string,remaining_sources:int,remaining_conversations:int,idempotent_replay:bool} */
    private function summary(int $knowledgeBaseId, bool $replay): array
    {
        $db = Db::connect('pgsql');
        $sources = $db->query(
            'SELECT count(*) AS total FROM sand_ai_knowledge_base_source s
             JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id
             WHERE v.knowledge_base_id=?',
            [$knowledgeBaseId],
        );
        $conversations = $db->query(
            'SELECT count(*) AS total FROM sand_ai_knowledge_conversation c
             JOIN sand_ai_knowledge_publication p ON p.id=c.publication_id
             WHERE p.knowledge_base_id=?',
            [$knowledgeBaseId],
        );
        return [
            'knowledge_base_id' => $knowledgeBaseId,
            'state' => 'purged',
            'remaining_sources' => (int) ($sources[0]['total'] ?? 0),
            'remaining_conversations' => (int) ($conversations[0]['total'] ?? 0),
            'idempotent_replay' => $replay,
        ];
    }
}
