<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class KnowledgeConversationGateway
{
    /** @param array<string,mixed> $publication @return array<string,mixed> */
    public function create(array $publication, IdentityContext $identity, string $requestId): array
    {
        $ownerScope = KnowledgeConversationPolicy::ownerScope($identity);
        return Db::connect('pgsql')->transaction(function () use ($publication, $identity, $requestId, $ownerScope): array {
            $this->assertPublicationCurrent($publication);
            Db::connect('pgsql')->execute(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['sand-ai-conversation-create:' . $requestId],
            );
            $existing = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_knowledge_conversation WHERE create_request_id=? FOR UPDATE',
                [$requestId],
            );
            if ($existing !== []) {
                KnowledgeConversationPolicy::assertReadable($existing[0], $publication, $identity);
                return $this->summary($existing[0]) + ['idempotent_replay' => true];
            }
            $publicId = bin2hex(random_bytes(16));
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_knowledge_conversation
                 (public_id,environment_id,owner_scope,publication_id,knowledge_base_version_id,source_scope_sha256,data_class,create_request_id)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $publicId, $identity->environmentId, $ownerScope, (int) $publication['id'],
                    (int) $publication['active_version_id'], (string) $publication['source_scope_sha256'],
                    $publication['data_class'] ?? null, $requestId,
                ],
            );
            $created = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_knowledge_conversation WHERE public_id=?',
                [$publicId],
            );
            if (count($created) !== 1) throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge conversation could not be created');
            return $this->summary($created[0]) + ['idempotent_replay' => false];
        });
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    public function readCreated(array $publication, IdentityContext $identity, string $requestId): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_conversation WHERE create_request_id=?',
            [$requestId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
        }
        KnowledgeConversationPolicy::assertReadable($rows[0], $publication, $identity);
        return $this->summary($rows[0]);
    }

    /**
     * Reserve one turn while holding the conversation row lock. A repeated
     * pending key resumes the same invocation; a new key cannot race it.
     * @param array<string,mixed> $publication
     * @param array{query:string,model:string,max_output_tokens:int,rerank:bool} $input
     * @return array{turn_id:int,claim_token:?string,history:list<array{query:string,answer:string}>,earlier_questions:list<array{sequence_no:int,query:string,answer_excerpt:string}>,context_coverage:array<string,mixed>,result:?array<string,mixed>}
     */
    public function prepare(string $publicId, array $publication, IdentityContext $identity, string $requestId, array $input): array
    {
        $fingerprintInput = $input;
        if (!$fingerprintInput['rerank']) unset($fingerprintInput['rerank']);
        $fingerprint = RequestPayloadFingerprint::fingerprint($fingerprintInput, []);
        return Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity, $requestId, $input, $fingerprint): array {
            $this->assertPublicationCurrent($publication);
            $conversation = $this->load($publicId, $publication, $identity, true);
            $conversationId = (int) $conversation['id'];
            $existing = Db::connect('pgsql')->query(
                'SELECT *,CASE WHEN claim_until > CURRENT_TIMESTAMP(0) THEN 1 ELSE 0 END AS claim_active
                 FROM sand_ai_knowledge_conversation_turn WHERE conversation_id=? AND request_id=? FOR UPDATE',
                [$conversationId, $requestId],
            );
            if ($existing !== []) {
                if (!hash_equals((string) $existing[0]['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The conversation turn key belongs to another question');
                }
                if ((string) $existing[0]['state'] !== 'pending') {
                    $result = $this->decodeResult($existing[0]['result']);
                    if ($result['answer_state'] === 'failed') {
                        throw new ApiProblem((string) $result['error_code'], 'This conversation question failed; use a new key for a new question');
                    }
                    return ['turn_id' => (int) $existing[0]['id'], 'claim_token' => null, 'history' => [], 'earlier_questions' => [],
                        'context_coverage' => [], 'result' => $result];
                }
                $turnId = (int) $existing[0]['id'];
                $sequenceNo = (int) $existing[0]['sequence_no'];
                $invocation = Db::connect('pgsql')->query(
                    'SELECT state FROM sand_ai_invocation WHERE request_id=?',
                    [$requestId],
                );
                $terminalInvocation = $invocation !== [] && in_array((string) $invocation[0]['state'], ['succeeded', 'failed'], true);
                if (!$terminalInvocation && (int) $existing[0]['claim_active'] === 1) {
                    throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'This question is still running', true);
                }
                $claimToken = bin2hex(random_bytes(16));
                Db::connect('pgsql')->execute(
                    "UPDATE sand_ai_knowledge_conversation_turn
                     SET claim_token=?,claim_until=CURRENT_TIMESTAMP(0)+INTERVAL '1 hour'
                     WHERE id=? AND state='pending'",
                    [$claimToken, $turnId],
                );
            } else {
                $pending = Db::connect('pgsql')->query(
                    "SELECT id FROM sand_ai_knowledge_conversation_turn WHERE conversation_id=? AND state='pending' LIMIT 1",
                    [$conversationId],
                );
                if ($pending !== []) {
                    throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'Another question is still running in this conversation', true);
                }
                $last = Db::connect('pgsql')->query(
                    'SELECT COALESCE(MAX(sequence_no),0) AS sequence_no FROM sand_ai_knowledge_conversation_turn WHERE conversation_id=?',
                    [$conversationId],
                );
                $sequenceNo = (int) $last[0]['sequence_no'] + 1;
                $claimToken = bin2hex(random_bytes(16));
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_knowledge_conversation_turn
                     (conversation_id,sequence_no,request_id,request_fingerprint,query,model,max_output_tokens,rerank,stream_segments,claim_token,claim_until)
                     VALUES (?,?,?,?,?,?,?,?::boolean,?::boolean,?,CURRENT_TIMESTAMP(0)+INTERVAL \'1 hour\')',
                    [$conversationId, $sequenceNo, $requestId, $fingerprint, $input['query'], $input['model'], $input['max_output_tokens'], $input['rerank'] ? 'true' : 'false', ($input['stream_segments'] ?? false) ? 'true' : 'false', $claimToken],
                );
                $created = Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_knowledge_conversation_turn WHERE conversation_id=? AND request_id=?',
                    [$conversationId, $requestId],
                );
                if (count($created) !== 1) throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge question could not be reserved');
                $turnId = (int) $created[0]['id'];
            }
            $history = Db::connect('pgsql')->query(
                "SELECT sequence_no,query,answer,state FROM sand_ai_knowledge_conversation_turn
                 WHERE conversation_id=? AND sequence_no<? AND state='answered'
                   AND answer IS NOT NULL AND btrim(answer)<>''
                 ORDER BY sequence_no DESC LIMIT 13",
                [$conversationId, $sequenceNo],
            );
            $answeredCount = Db::connect('pgsql')->query(
                "SELECT count(*) AS total FROM sand_ai_knowledge_conversation_turn
                 WHERE conversation_id=? AND sequence_no<? AND state='answered'
                   AND answer IS NOT NULL AND btrim(answer)<>''",
                [$conversationId, $sequenceNo],
            );
            $context = KnowledgeConversationPolicy::context(
                array_reverse($history), (int) ($answeredCount[0]['total'] ?? 0),
            );
            return ['turn_id' => $turnId, 'claim_token' => $claimToken, 'history' => $context['history'],
                'earlier_questions' => $context['earlier_questions'], 'context_coverage' => $context['coverage'], 'result' => null];
        });
    }

    /**
     * Finds the original scoped request behind a pending turn. The public
     * idempotency header cannot be reconstructed from its persisted hash.
     *
     * @param array<string,mixed> $publication
     * @return array{request_id:string,input:array{query:string,model:string,max_output_tokens:int,rerank:bool}}
     */
    public function pendingForResume(string $publicId, array $publication, IdentityContext $identity, int $sequenceNo): array
    {
        if ($sequenceNo < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation turn number is invalid');
        }
        $conversation = $this->load($publicId, $publication, $identity, false);
        $rows = Db::connect('pgsql')->query(
            'SELECT request_id,query,model,max_output_tokens,rerank,stream_segments,state
             FROM sand_ai_knowledge_conversation_turn WHERE conversation_id=? AND sequence_no=?',
            [(int) $conversation['id'], $sequenceNo],
        );
        if (count($rows) !== 1 || (string) $rows[0]['state'] !== 'pending'
            || !is_string($rows[0]['request_id'] ?? null)
            || preg_match('/\Aknowledge-turn:[a-f0-9]{64}\z/D', $rows[0]['request_id']) !== 1) {
            throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'The pending conversation turn is unavailable');
        }
        $input = KnowledgeAnswerPolicy::normalize([
            'query' => $rows[0]['query'],
            'model' => $rows[0]['model'],
            'max_output_tokens' => (int) $rows[0]['max_output_tokens'],
            'rerank' => in_array($rows[0]['rerank'], [true, 1, '1', 't', 'true'], true),
            'stream_segments' => in_array($rows[0]['stream_segments'], [true, 1, '1', 't', 'true'], true),
        ], true);
        return ['request_id' => $rows[0]['request_id'], 'input' => $input];
    }

    /** @return array{sequence_no:int,state:string,stream_segments:bool} */
    public function readTurnByRequest(
        string $publicId, array $publication, IdentityContext $identity, string $requestId,
    ): array {
        $conversation = $this->load($publicId, $publication, $identity, false, true);
        $rows = Db::connect('pgsql')->query(
            'SELECT sequence_no,state,stream_segments FROM sand_ai_knowledge_conversation_turn
             WHERE conversation_id=? AND request_id=?',
            [(int) $conversation['id'], $requestId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Conversation turn not found');
        }
        return ['sequence_no' => (int) $rows[0]['sequence_no'], 'state' => (string) $rows[0]['state'],
            'stream_segments' => in_array($rows[0]['stream_segments'], [true, 1, '1', 't', 'true'], true)];
    }

    /** @param list<int> $sourceBlockIds */
    public function appendSegment(
        string $publicId, array $publication, IdentityContext $identity, int $turnId,
        string $requestId, string $claimToken, int $invocationId, int $dispatchId,
        string $text, array $sourceBlockIds,
    ): void {
        Db::connect('pgsql')->transaction(function () use (
            $publicId, $publication, $identity, $turnId, $requestId, $claimToken,
            $invocationId, $dispatchId, $text, $sourceBlockIds,
        ): void {
            $conversation = $this->load($publicId, $publication, $identity, true);
            $rows = Db::connect('pgsql')->query(
                'SELECT id,stream_segments,state,claim_token FROM sand_ai_knowledge_conversation_turn
                 WHERE id=? AND conversation_id=? AND request_id=? FOR UPDATE',
                [$turnId, (int) $conversation['id'], $requestId],
            );
            if (count($rows) !== 1 || (string) $rows[0]['state'] !== 'pending'
                || !in_array($rows[0]['stream_segments'], [true, 1, '1', 't', 'true'], true)
                || !hash_equals((string) $rows[0]['claim_token'], $claimToken)) {
                throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'Conversation segment claim is no longer current');
            }
            $dispatch = Db::connect('pgsql')->query(
                'SELECT d.id FROM sand_ai_invocation_dispatch d
                 JOIN sand_ai_invocation i ON i.id=d.invocation_id
                 WHERE d.id=? AND i.id=? AND i.request_id=? AND d.state=?',
                [$dispatchId, $invocationId, $requestId, 'dispatching'],
            );
            if (count($dispatch) !== 1) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Conversation segment dispatch is invalid');
            }
            $last = Db::connect('pgsql')->query(
                'SELECT COALESCE(MAX(segment_no),0) AS n FROM sand_ai_knowledge_conversation_segment WHERE turn_id=?',
                [$turnId],
            );
            $segmentNo = (int) $last[0]['n'] + 1;
            if ($segmentNo > 16) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Conversation segment limit exceeded', true);
            }
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_knowledge_conversation_segment
                 (turn_id,segment_no,invocation_id,dispatch_id,claim_token_sha256,text,source_block_ids)
                 VALUES (?,?,?,?,?,?,CAST(? AS jsonb))',
                [$turnId, $segmentNo, $invocationId, $dispatchId, hash('sha256', $claimToken),
                    $text, json_encode($sourceBlockIds, JSON_THROW_ON_ERROR)],
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function readSegments(
        string $publicId, array $publication, IdentityContext $identity, int $sequenceNo, int $after,
    ): array {
        $conversation = $this->load($publicId, $publication, $identity, false, true);
        $rows = Db::connect('pgsql')->query(
            'SELECT s.segment_no,s.text,s.source_block_ids
             FROM sand_ai_knowledge_conversation_segment s
             JOIN sand_ai_knowledge_conversation_turn t ON t.id=s.turn_id
             JOIN sand_ai_invocation i ON i.id=s.invocation_id AND i.request_id=t.request_id
             JOIN sand_ai_invocation_dispatch d ON d.id=s.dispatch_id AND d.invocation_id=i.id
             WHERE t.conversation_id=? AND t.sequence_no=? AND t.stream_segments=true
               AND s.segment_no>? ORDER BY s.segment_no LIMIT 17',
            [(int) $conversation['id'], $sequenceNo, $after],
        );
        if (count($rows) > 16) {
            throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Conversation segment history is invalid');
        }
        return array_map(static function (array $row): array {
            $ids = is_string($row['source_block_ids'])
                ? json_decode($row['source_block_ids'], true, 8, JSON_THROW_ON_ERROR)
                : $row['source_block_ids'];
            return ['segment_no' => (int) $row['segment_no'], 'text' => (string) $row['text'],
                'source_block_ids' => $ids];
        }, $rows);
    }

    /** @param array<string,mixed> $publication @param array<string,mixed> $result @return array<string,mixed> */
    public function complete(string $publicId, array $publication, IdentityContext $identity, int $turnId, string $requestId, string $claimToken, array $result): array
    {
        $state = (string) ($result['answer_state'] ?? '');
        if (!in_array($state, ['answered', 'needs_review', 'no_sources'], true)) {
            throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge answer state is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity, $turnId, $requestId, $claimToken, $result, $state): array {
            $conversation = $this->load($publicId, $publication, $identity, true);
            $turn = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_knowledge_conversation_turn WHERE id=? AND conversation_id=? AND request_id=? FOR UPDATE',
                [$turnId, (int) $conversation['id'], $requestId],
            );
            if (count($turn) !== 1) throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge question is unavailable');
            if ((string) $turn[0]['state'] !== 'pending') {
                $stored = $this->decodeResult($turn[0]['result']);
                if ($stored['answer_state'] === 'failed') {
                    throw new ApiProblem((string) $stored['error_code'], 'This conversation question failed; use a new key for a new question');
                }
                return $stored;
            }
            if (!is_string($turn[0]['claim_token'] ?? null)
                || !hash_equals($turn[0]['claim_token'], $claimToken)) {
                throw new ApiProblem('SAND_AI_CONVERSATION_CLAIM_LOST', 'Knowledge conversation claim changed before completion');
            }
            $changed = Db::connect('pgsql')->execute(
                'UPDATE sand_ai_knowledge_conversation_turn SET state=?,answer=?,result=CAST(? AS jsonb),invocation_request_id=?,complete_time=CURRENT_TIMESTAMP(0),claim_token=NULL,claim_until=NULL
                 WHERE id=? AND state=\'pending\' AND claim_token=?',
                [
                    $state, $result['answer'] ?? null,
                    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $result['invocation']['request_id'] ?? null, $turnId, $claimToken,
                ],
            );
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_CONVERSATION_CLAIM_LOST', 'Knowledge conversation claim changed before completion');
            }
            Db::connect('pgsql')->execute(
                'UPDATE sand_ai_knowledge_conversation SET update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                [(int) $conversation['id']],
            );
            return $result;
        });
    }

    /**
     * Release a failed turn only when the invocation is absent or its dispatch
     * ledger proves a terminal rejection. Ambiguous/succeeded outcomes stay
     * pending so they cannot be mistaken for a free retry.
     * @param array<string,mixed> $publication
     */
    public function failIfDefinitive(string $publicId, array $publication, IdentityContext $identity, int $turnId, string $requestId, string $claimToken, ApiProblem $problem): void
    {
        Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity, $turnId, $requestId, $claimToken, $problem): void {
            $conversation = $this->load($publicId, $publication, $identity, true);
            $turn = Db::connect('pgsql')->query(
                'SELECT state,claim_token,summary_request_id,request_id,rerank FROM sand_ai_knowledge_conversation_turn WHERE id=? AND conversation_id=? AND request_id=? FOR UPDATE',
                [$turnId, (int) $conversation['id'], $requestId],
            );
            if (count($turn) !== 1 || $turn[0]['state'] !== 'pending'
                || !hash_equals((string) $turn[0]['claim_token'], $claimToken)) return;
            $childStates = $this->childInvocationStates($turn, $publication, $identity, ['rerank']);
            $rerank = $childStates[$requestId]['rerank'] ?? null;
            // An unknown rerank outcome must keep its original turn
            // recoverable even when the answer call does not exist yet.
            if ($rerank !== null && (!in_array($rerank['invocation_state'], ['failed', 'succeeded'], true)
                || ($rerank['dispatch_state'] !== null
                    && !in_array($rerank['dispatch_state'], ['not_dispatched', 'failed', 'succeeded'], true))
                || ($rerank['invocation_state'] === 'failed' && $rerank['dispatch_state'] === 'succeeded')
                || ($rerank['invocation_state'] === 'succeeded' && $rerank['dispatch_state'] !== 'succeeded'))) return;
            if (is_string($turn[0]['summary_request_id'] ?? null)) {
                $summaryInvocation = Db::connect('pgsql')->query(
                    'SELECT state FROM sand_ai_invocation WHERE request_id=? FOR UPDATE',
                    [$turn[0]['summary_request_id']],
                );
                $savedSummary = Db::connect('pgsql')->query(
                    'SELECT 1 FROM sand_ai_knowledge_conversation_summary_call
                     WHERE turn_id=? AND request_id=?',
                    [$turnId, $turn[0]['summary_request_id']],
                );
                if ($savedSummary === [] && $summaryInvocation !== []
                    && (string) $summaryInvocation[0]['state'] !== 'failed') return;
            }
            $embeddingRequestId = KnowledgeQueryEmbeddingGateway::childRequestId(
                $identity->environmentId, (int) $publication['id'], $requestId,
            );
            $embedding = Db::connect('pgsql')->query(
                'SELECT id,state,environment_id,organization_id,application_id,
                        workload_client_id,knowledge_publication_id,knowledge_base_version_id,
                        knowledge_source_scope_sha256,data_class
                   FROM sand_ai_invocation WHERE request_id=? FOR UPDATE',
                [$embeddingRequestId],
            );
            if ($embedding !== []) {
                if ((int) $embedding[0]['environment_id'] !== $identity->environmentId
                    || (string) $embedding[0]['organization_id'] !== $identity->organizationId
                    || (string) $embedding[0]['application_id'] !== $identity->applicationId
                    || (string) $embedding[0]['workload_client_id'] !== $identity->workloadClientId
                    || (int) $embedding[0]['knowledge_publication_id'] !== (int) $publication['id']
                    || (int) $embedding[0]['knowledge_base_version_id'] !== (int) $publication['active_version_id']
                    || (string) $embedding[0]['knowledge_source_scope_sha256'] !== (string) $publication['source_scope_sha256']
                    || $embedding[0]['data_class'] !== ($publication['data_class'] ?? null)) return;
                if ((string) $embedding[0]['state'] === 'running'
                    || (string) $embedding[0]['state'] === 'ambiguous') return;
                $embeddingDispatch = Db::connect('pgsql')->query(
                    'SELECT state FROM sand_ai_invocation_dispatch
                     WHERE invocation_id=? ORDER BY id DESC LIMIT 1',
                    [(int) $embedding[0]['id']],
                );
                if ($embeddingDispatch === [] && (string) $embedding[0]['state'] !== 'failed') return;
                if ($embeddingDispatch !== []
                    && !in_array((string) $embeddingDispatch[0]['state'], [
                        'not_dispatched', 'failed', 'succeeded',
                    ], true)) return;
            }
            $invocation = Db::connect('pgsql')->query(
                'SELECT id,state FROM sand_ai_invocation WHERE request_id=? FOR UPDATE',
                [$requestId],
            );
            if ($invocation !== []) {
                if ((string) $invocation[0]['state'] !== 'failed') return;
                $dispatch = Db::connect('pgsql')->query(
                    'SELECT state FROM sand_ai_invocation_dispatch WHERE invocation_id=? ORDER BY id DESC LIMIT 1',
                    [(int) $invocation[0]['id']],
                );
                if ($dispatch !== [] && !in_array((string) $dispatch[0]['state'], ['not_dispatched', 'failed'], true)) return;
            }
            // A completed rerank without a terminal answer still needs
            // recovery; a proven answer failure may seal the turn normally.
            if ($invocation === [] && ($rerank['invocation_state'] ?? null) === 'succeeded') return;
            $result = json_encode([
                'answer_state' => 'failed', 'answer' => null, 'citations' => [],
                'review_required' => true, 'error_code' => $problem->errorCode,
                'invocation' => $invocation === [] ? null : ['request_id' => $requestId, 'state' => 'failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            Db::connect('pgsql')->execute(
                "UPDATE sand_ai_knowledge_conversation_turn
                 SET state='failed',result=CAST(? AS jsonb),invocation_request_id=?,complete_time=CURRENT_TIMESTAMP(0),
                     claim_token=NULL,claim_until=NULL
                 WHERE id=? AND state='pending'",
                [$result, $invocation === [] ? null : $requestId, $turnId],
            );
            Db::connect('pgsql')->execute(
                'UPDATE sand_ai_knowledge_conversation SET update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                [(int) $conversation['id']],
            );
        });
    }

    /** @param array<string,mixed> $publication */
    public function assertClaim(string $publicId, array $publication, IdentityContext $identity, int $turnId, string $requestId, string $claimToken): void
    {
        Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity, $turnId, $requestId, $claimToken): void {
            $conversation = $this->load($publicId, $publication, $identity, true);
            $turn = Db::connect('pgsql')->query(
                'SELECT state,claim_token FROM sand_ai_knowledge_conversation_turn
                 WHERE id=? AND conversation_id=? AND request_id=? FOR UPDATE',
                [$turnId, (int) $conversation['id'], $requestId],
            );
            if (count($turn) !== 1 || $turn[0]['state'] !== 'pending'
                || !hash_equals((string) $turn[0]['claim_token'], $claimToken)) {
                throw new ApiProblem('SAND_AI_CONVERSATION_CLAIM_LOST', 'Knowledge conversation claim changed before dispatch');
            }
        });
    }

    /** @param array<string,mixed> $publication @return array{sequence_no:int,answer_state:string,idempotent_replay:bool} */
    public function cancelPending(string $publicId, array $publication, IdentityContext $identity, int $sequenceNo): array
    {
        if ($sequenceNo < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation turn number is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity, $sequenceNo): array {
            $this->assertPublicationCurrent($publication);
            $conversation = $this->load($publicId, $publication, $identity, true);
            $rows = Db::connect('pgsql')->query(
                'SELECT id,state,request_id,summary_started FROM sand_ai_knowledge_conversation_turn
                 WHERE conversation_id=? AND sequence_no=? FOR UPDATE',
                [(int) $conversation['id'], $sequenceNo],
            );
            if (count($rows) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Conversation turn not found');
            }
            if ($rows[0]['state'] === 'cancelled') {
                return ['sequence_no' => $sequenceNo, 'answer_state' => 'cancelled', 'idempotent_replay' => true];
            }
            if ($rows[0]['state'] !== 'pending') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This conversation turn has already finished');
            }
            if (in_array($rows[0]['summary_started'], [true, 1, '1', 't', 'true'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Conversation summary work has started; read or recover this turn');
            }
            $requestId = (string) $rows[0]['request_id'];
            $childIds = [
                $requestId,
                KnowledgeQueryEmbeddingGateway::childRequestId(
                    $identity->environmentId, (int) $publication['id'], $requestId,
                ),
                KnowledgeRerankGateway::childRequestId($requestId),
            ];
            $invocations = Db::connect('pgsql')->query(
                'SELECT id FROM sand_ai_invocation WHERE request_id IN (?,?,?) LIMIT 1',
                $childIds,
            );
            if ($invocations !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A service call has already started; read or recover this turn');
            }
            $result = json_encode([
                'answer_state' => 'cancelled', 'answer' => null, 'citations' => [],
                'review_required' => false, 'invocation' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $updated = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_knowledge_conversation_turn
                 SET state='cancelled',result=CAST(? AS jsonb),complete_time=CURRENT_TIMESTAMP(0),
                     claim_token=NULL,claim_until=NULL
                 WHERE id=? AND state='pending'",
                [$result, (int) $rows[0]['id']],
            );
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Conversation cancellation did not finish');
            }
            Db::connect('pgsql')->execute(
                'UPDATE sand_ai_knowledge_conversation SET update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                [(int) $conversation['id']],
            );
            AuditLog::create([
                'environment_id' => $identity->environmentId,
                'actor_type' => 'api_credential',
                'actor_ref' => (string) $identity->environmentId,
                'action' => 'knowledge_conversation.turn.cancel',
                'resource_type' => 'knowledge_conversation',
                'resource_id' => (int) $conversation['id'],
                'summary' => 'Knowledge conversation turn cancelled before service invocation',
                'context' => ['sequence_no' => $sequenceNo],
            ]);
            return ['sequence_no' => $sequenceNo, 'answer_state' => 'cancelled', 'idempotent_replay' => false];
        });
    }

    /** @param array<string,mixed> $publication @return array{conversations:list<array<string,mixed>>,next_before:string|null} */
    public function list(array $publication, IdentityContext $identity, ?string $before = null): array
    {
        $scope = KnowledgeConversationPolicy::ownerScope($identity);
        $db = Db::connect('pgsql');
        $params = [
            $identity->environmentId, $scope, (int) $publication['id'],
            (int) $publication['active_version_id'], (string) $publication['source_scope_sha256'],
            $publication['data_class'] ?? null,
        ];
        $cursor = '';
        if ($before !== null) {
            $previous = $db->query(
                'SELECT id FROM sand_ai_knowledge_conversation
                 WHERE public_id=? AND environment_id=? AND owner_scope=? AND publication_id=?
                   AND knowledge_base_version_id=? AND source_scope_sha256=? AND data_class IS NOT DISTINCT FROM ?',
                [$before, ...$params],
            );
            if (count($previous) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
            }
            $cursor = ' AND id<?';
            $params[] = (int) $previous[0]['id'];
        }
        $rows = $db->query(
            'SELECT public_id,knowledge_base_version_id,state,create_time,update_time
             FROM sand_ai_knowledge_conversation
             WHERE environment_id=? AND owner_scope=? AND publication_id=?
               AND knowledge_base_version_id=? AND source_scope_sha256=?
               AND data_class IS NOT DISTINCT FROM ?'
             . $cursor . ' ORDER BY id DESC LIMIT 21',
            $params,
        );
        $hasMore = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $conversations = array_map(fn (array $row): array => $this->summary($row), $rows);
        return [
            'conversations' => $conversations,
            'next_before' => $hasMore ? (string) $rows[19]['public_id'] : null,
        ];
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    public function read(string $publicId, array $publication, IdentityContext $identity, ?int $beforeSequence = null): array
    {
        $conversation = $this->load($publicId, $publication, $identity, false, true);
        if ($beforeSequence !== null && $beforeSequence < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation cursor is invalid');
        }
        $rows = Db::connect('pgsql')->query(
            'SELECT t.sequence_no,t.request_id,t.query,t.state,t.result,t.rerank,t.stream_segments,t.create_time,t.complete_time,
                    CASE WHEN t.claim_until>CURRENT_TIMESTAMP(0) THEN 1 ELSE 0 END AS claim_active,
                    i.state AS invocation_state,d.state AS dispatch_state,
                    si.state AS summary_invocation_state,sd.state AS summary_dispatch_state
             FROM sand_ai_knowledge_conversation_turn t
             LEFT JOIN sand_ai_invocation i ON i.request_id=t.request_id AND i.environment_id=?
             LEFT JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
             LEFT JOIN sand_ai_invocation si ON si.request_id=t.summary_request_id
               AND si.environment_id=? AND si.organization_id=? AND si.application_id=?
               AND si.workload_client_id=? AND si.knowledge_publication_id=?
               AND si.knowledge_base_version_id=? AND si.knowledge_source_scope_sha256=?
               AND si.data_class IS NOT DISTINCT FROM ?
             LEFT JOIN sand_ai_invocation_dispatch sd ON sd.invocation_id=si.id
             WHERE t.conversation_id=?'
             . ($beforeSequence === null ? '' : ' AND t.sequence_no<?')
             . ' ORDER BY t.sequence_no DESC LIMIT 21',
            $beforeSequence === null
                ? [$identity->environmentId, $identity->environmentId, $identity->organizationId,
                    $identity->applicationId, $identity->workloadClientId, (int) $publication['id'],
                    (int) $publication['active_version_id'], (string) $publication['source_scope_sha256'],
                    $publication['data_class'] ?? null, (int) $conversation['id']]
                : [$identity->environmentId, $identity->environmentId, $identity->organizationId,
                    $identity->applicationId, $identity->workloadClientId, (int) $publication['id'],
                    (int) $publication['active_version_id'], (string) $publication['source_scope_sha256'],
                    $publication['data_class'] ?? null, (int) $conversation['id'], $beforeSequence],
        );
        $hasMore = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $childStates = $this->childInvocationStates($rows, $publication, $identity);
        $turns = array_reverse(array_map(function (array $row) use ($childStates): array {
            $result = $row['result'] === null ? null : $this->decodeResult($row['result']);
            foreach (['embedding', 'rerank'] as $stage) {
                $child = $childStates[(string) $row['request_id']][$stage] ?? null;
                $row[$stage . '_invocation_state'] = $child['invocation_state'] ?? null;
                $row[$stage . '_dispatch_state'] = $child['dispatch_state'] ?? null;
            }
            return [
                'sequence_no' => (int) $row['sequence_no'], 'query' => (string) $row['query'],
                'state' => (string) $row['state'],
                'stream_segments' => in_array($row['stream_segments'], [true, 1, '1', 't', 'true'], true),
                'execution_state' => KnowledgeConversationPolicy::executionState($row),
                'result' => $result,
                'create_time' => $row['create_time'], 'complete_time' => $row['complete_time'],
            ];
        }, $rows));
        return $this->summary($conversation) + [
            'turns' => $turns,
            'next_before_sequence' => $hasMore ? (int) $rows[19]['sequence_no'] : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $turns
     * @param array<string,mixed> $publication
     * @param list<string> $stages
     * @return array<string,array<string,array{invocation_state:string,dispatch_state:?string}>>
     */
    private function childInvocationStates(
        array $turns, array $publication, IdentityContext $identity, array $stages = ['embedding', 'rerank'],
    ): array
    {
        $requestIds = [];
        $children = [];
        foreach ($turns as $turn) {
            if ((string) $turn['state'] !== 'pending' || ($turn['invocation_state'] ?? null) !== null) continue;
            $parent = (string) $turn['request_id'];
            foreach ($stages as $stage) {
                if ($stage === 'rerank' && !in_array($turn['rerank'] ?? false, [true, 1, '1', 't', 'true'], true)) continue;
                $child = match ($stage) {
                    'embedding' => KnowledgeQueryEmbeddingGateway::childRequestId(
                        $identity->environmentId, (int) $publication['id'], $parent,
                    ),
                    'rerank' => KnowledgeRerankGateway::childRequestId($parent),
                    default => throw new \LogicException('Unsupported conversation child stage'),
                };
                $requestIds[] = $child;
                $children[$child] = ['parent' => $parent, 'stage' => $stage];
            }
        }
        if ($requestIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $rows = Db::connect('pgsql')->query(
            'SELECT i.request_id,i.state AS invocation_state,i.environment_id,i.organization_id,
                    i.application_id,i.workload_client_id,i.knowledge_publication_id,
                    i.knowledge_base_version_id,i.knowledge_source_scope_sha256,i.data_class,
                    d.state AS dispatch_state,d.execution_scope,d.source_kind,d.service_action,
                    d.environment_id AS dispatch_environment_id,d.application_id AS dispatch_application_id,
                    d.logical_request_id
             FROM sand_ai_invocation i
             LEFT JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
             WHERE i.request_id IN (' . $placeholders . ')',
            $requestIds,
        );
        $states = [];
        foreach ($rows as $row) {
            $requestId = (string) $row['request_id'];
            $child = $children[$requestId];
            $isEmbedding = $child['stage'] === 'embedding';
            $executionScope = $isEmbedding ? 'embedding' : 'chat';
            $sourceKind = $isEmbedding ? 'knowledge_query' : 'chat';
            $serviceAction = $isEmbedding ? 'sand_ai.knowledge.query' : 'sand_ai.chat.complete';
            if ((int) $row['environment_id'] !== $identity->environmentId
                || (string) $row['organization_id'] !== $identity->organizationId
                || (string) $row['application_id'] !== $identity->applicationId
                || (string) $row['workload_client_id'] !== $identity->workloadClientId
                || (int) $row['knowledge_publication_id'] !== (int) $publication['id']
                || (int) $row['knowledge_base_version_id'] !== (int) $publication['active_version_id']
                || (string) $row['knowledge_source_scope_sha256'] !== (string) $publication['source_scope_sha256']
                || $row['data_class'] !== ($publication['data_class'] ?? null)
                || ($row['dispatch_state'] !== null
                    && ((int) $row['dispatch_environment_id'] !== $identity->environmentId
                        || (string) $row['dispatch_application_id'] !== $identity->applicationId
                        || (string) $row['logical_request_id'] !== hash('sha256', $requestId)
                        || (string) $row['execution_scope'] !== $executionScope
                        || (string) $row['source_kind'] !== $sourceKind
                        || (string) $row['service_action'] !== $serviceAction))
                || isset($states[$child['parent']][$child['stage']])) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Conversation child invocation facts changed');
            }
            $states[$child['parent']][$child['stage']] = [
                'invocation_state' => (string) $row['invocation_state'],
                'dispatch_state' => $row['dispatch_state'] === null ? null : (string) $row['dispatch_state'],
            ];
        }
        return $states;
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    public function close(string $publicId, array $publication, IdentityContext $identity): array
    {
        return Db::connect('pgsql')->transaction(function () use ($publicId, $publication, $identity): array {
            $conversation = $this->load($publicId, $publication, $identity, true, true);
            if ((string) $conversation['state'] === 'closed') {
                return $this->summary($conversation) + ['idempotent_replay' => true];
            }
            $pending = Db::connect('pgsql')->query(
                "SELECT id FROM sand_ai_knowledge_conversation_turn
                 WHERE conversation_id=? AND state='pending' LIMIT 1",
                [(int) $conversation['id']],
            );
            if ($pending !== []) {
                throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'This conversation still has a pending question', true);
            }
            $closed = Db::connect('pgsql')->query(
                "UPDATE sand_ai_knowledge_conversation SET state='closed',update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND state='active' RETURNING *",
                [(int) $conversation['id']],
            );
            if (count($closed) !== 1) {
                throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge conversation could not be closed');
            }
            AuditLog::create([
                'environment_id' => $identity->environmentId,
                'actor_type' => 'api_credential',
                'actor_ref' => (string) $identity->environmentId,
                'action' => 'knowledge_conversation.close',
                'resource_type' => 'knowledge_conversation',
                'resource_id' => (int) $conversation['id'],
                'summary' => 'Knowledge conversation closed',
                'context' => [],
            ]);
            return $this->summary($closed[0]) + ['idempotent_replay' => false];
        });
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    private function load(string $publicId, array $publication, IdentityContext $identity, bool $lock, bool $allowClosed = false): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
        }
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_conversation WHERE public_id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$publicId],
        );
        if (count($rows) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
        if ($allowClosed) KnowledgeConversationPolicy::assertReadable($rows[0], $publication, $identity);
        else KnowledgeConversationPolicy::assertBound($rows[0], $publication, $identity);
        return $rows[0];
    }

    /** @param array<string,mixed> $publication */
    private function assertPublicationCurrent(array $publication): void
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT p.id FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id
             WHERE p.id=? AND p.state='published'
               AND kb.state='published' AND kb.status=1 AND kb.delete_time IS NULL
               AND v.state='published' AND v.id=? AND v.source_scope_sha256=?
               AND v.data_class IS NOT DISTINCT FROM ?
             FOR SHARE OF p,kb",
            [
                (int) $publication['id'], (int) $publication['active_version_id'],
                (string) $publication['source_scope_sha256'], $publication['data_class'] ?? null,
            ],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge base not found');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function summary(array $row): array
    {
        return [
            'conversation_id' => (string) $row['public_id'],
            'knowledge_base_version_id' => (int) $row['knowledge_base_version_id'],
            'state' => (string) $row['state'],
            'create_time' => $row['create_time'],
            'update_time' => $row['update_time'],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeResult(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 16) : $value;
        if (!is_array($decoded) || !isset($decoded['answer_state'])) {
            throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Stored knowledge answer is invalid');
        }
        // Reads, original-key recovery and turn events also cover answers sealed
        // before the final-answer display policy. Never rewrite their source receipt.
        if (is_string($decoded['answer'] ?? null)) {
            $decoded['answer'] = KnowledgeConversationContextMask::text($decoded['answer']);
        }
        if (is_string($decoded['context_coverage']['summary_text'] ?? null)) {
            $decoded['context_coverage']['summary_text'] = KnowledgeConversationContextMask::text(
                $decoded['context_coverage']['summary_text'],
            );
        }
        return $decoded;
    }
}
