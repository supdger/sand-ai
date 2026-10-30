<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Contract\IdentityContext;
use think\facade\Db;

/** Immutable public query intent; never stores query text, bearer or source excerpts. */
final class KnowledgeQueryIntentGateway
{
    /**
     * Read the original immutable intent without admission, row locks or binding.
     * The caller supplies the original body because no query text is retained.
     * @param array<string,mixed> $publication
     * @param array{query:string,limit:int,rerank:bool,model:?string,rerank_mode?:string} $input
     */
    public function readOriginal(array $publication, IdentityContext $identity, array $input, string $requestId, ?int $indexId): string
    {
        $facts = $this->facts($publication, $identity, $input, $requestId, $indexId);
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_query_intent WHERE organization_id=? AND application_id=?
             AND environment_id=? AND workload_client_id=? AND request_id=?',
            [$facts['organization_id'], $facts['application_id'], $facts['environment_id'], $facts['workload_client_id'], $facts['request_id']],
        );
        if ($rows === []) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original knowledge query intent is unavailable');
        }
        if (count($rows) !== 1) $this->conflict();
        $this->assertSame($rows[0], $facts);
        $snapshot = $rows[0]['retrieval_snapshot_sha256'] ?? null;
        if ($snapshot === null) {
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original knowledge query retrieval is not complete', true);
        }
        if (!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/D', $snapshot) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Original knowledge query snapshot is unavailable');
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $publication @param array{query:string,limit:int,rerank:bool,model:?string,rerank_mode?:string} $input */
    public function admit(array $publication, IdentityContext $identity, array $input, string $requestId, ?int $indexId, callable $revalidate): void
    {
        $facts = $this->facts($publication, $identity, $input, $requestId, $indexId);
        Db::connect('pgsql')->transaction(function () use ($facts, $publication, $requestId, $revalidate): void {
            $db = Db::connect('pgsql');
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sand-ai-knowledge-query-intent:' . $requestId]);
            $rows = $this->find($facts);
            $revalidate();
            if ($rows !== []) {
                $this->assertSame($rows[0], $facts);
                return;
            }
            // Historical embedding records have no complete public query intent.
            // Match the original child derivation across publications in this environment,
            // so a different alias cannot silently add a paid stage under the original key.
            $legacyEmbedding = $db->query(
                "SELECT id FROM sand_ai_invocation WHERE environment_id=? AND knowledge_publication_id IS NOT NULL
                 AND request_id='knowledge-query-embedding:' || encode(sha256(convert_to(
                     environment_id::text || ':' || knowledge_publication_id::text || ':' || ?, 'UTF8')), 'hex') LIMIT 1",
                [$facts['environment_id'], $requestId],
            );
            $rerankId = KnowledgeRerankGateway::childRequestId($requestId);
            $legacyRerank = $db->query('SELECT id FROM sand_ai_invocation WHERE request_id=? LIMIT 1', [$rerankId]);
            $legacyRejection = $db->query(
                'SELECT id FROM sand_ai_pre_dispatch_rejection WHERE request_id IN (?,?) LIMIT 1',
                [$rerankId, KnowledgeQueryEmbeddingGateway::childRequestId($facts['environment_id'], (int) $publication['id'], $requestId)],
            );
            if ($legacyEmbedding !== [] || $legacyRerank !== [] || $legacyRejection !== []) {
                $this->conflict();
            }
            $db->execute(
                'INSERT INTO sand_ai_knowledge_query_intent
                 (organization_id,application_id,environment_id,workload_client_id,request_id,
                  publication_id,knowledge_base_version_id,source_scope_sha256,data_class,request_fingerprint)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                array_values($facts),
            );
            $revalidate();
        });
    }

    /**
     * Bind the first complete ordered retrieval before any rerank call. Each replay
     * must reproduce exactly those current authorized citations, including scores.
     * @param array<string,mixed> $publication
     * @param array{query:string,limit:int,rerank:bool,model:?string,rerank_mode?:string} $input
     * @param list<array<string,mixed>> $citations
     */
    public function bindRetrieval(array $publication, IdentityContext $identity, array $input, string $requestId, ?int $indexId, array $citations, callable $revalidate): void
    {
        $facts = $this->facts($publication, $identity, $input, $requestId, $indexId);
        $snapshot = KnowledgeBasePolicy::fingerprint($citations);
        Db::connect('pgsql')->transaction(function () use ($facts, $snapshot, $revalidate): void {
            $rows = $this->find($facts);
            if (count($rows) !== 1) $this->conflict();
            $row = $rows[0];
            $this->assertSame($row, $facts);
            $revalidate();
            if ($row['retrieval_snapshot_sha256'] !== null) {
                if (!is_string($row['retrieval_snapshot_sha256']) || !hash_equals($row['retrieval_snapshot_sha256'], $snapshot)) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Original knowledge query sources changed');
                }
                return;
            }
            $updated = Db::connect('pgsql')->execute(
                'UPDATE sand_ai_knowledge_query_intent SET retrieval_snapshot_sha256=? WHERE id=? AND retrieval_snapshot_sha256 IS NULL',
                [$snapshot, (int) $row['id']],
            );
            if ($updated !== 1) $this->conflict();
            $revalidate();
        });
    }

    /** @param array<string,mixed> $facts @return list<array<string,mixed>> */
    private function find(array $facts): array
    {
        return Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_query_intent WHERE organization_id=? AND application_id=?
             AND environment_id=? AND workload_client_id=? AND request_id=? FOR UPDATE',
            [$facts['organization_id'], $facts['application_id'], $facts['environment_id'], $facts['workload_client_id'], $facts['request_id']],
        );
    }

    /** @param array<string,mixed> $publication @param array<string,mixed> $input @return array<string,mixed> */
    private function facts(array $publication, IdentityContext $identity, array $input, string $requestId, ?int $indexId): array
    {
        $scope = [];
        foreach (['organization_id' => $identity->organizationId, 'application_id' => $identity->applicationId,
            'environment_id' => $identity->environmentId, 'workload_client_id' => $identity->workloadClientId] as $name => $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Knowledge query scope is invalid');
            $scope[$name] = (int) $id;
        }
        if ($scope['environment_id'] !== (int) ($publication['consumer_environment_id'] ?? 0)
            || $scope['organization_id'] !== (int) ($publication['consumer_organization_id'] ?? 0)
            || $scope['application_id'] !== (int) ($publication['consumer_application_id'] ?? 0)) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Knowledge query publication scope is invalid');
        }
        return $scope + [
            'request_id' => $requestId,
            'publication_id' => (int) $publication['id'],
            'knowledge_base_version_id' => (int) $publication['active_version_id'],
            'source_scope_sha256' => (string) $publication['source_scope_sha256'],
            'data_class' => $publication['data_class'] ?? null,
            // Keep the legacy normalized input unchanged. Explicit professional
            // mode participates in the intent fingerprint and cannot reuse a chat intent.
            'request_fingerprint' => RequestPayloadFingerprint::fingerprint([
                'version' => 1, 'input' => $input, 'scope' => $scope,
                'active_embedding_index_id' => $indexId,
                'canonical_scope' => RequestIdempotencyKey::trustedScope($identity),
                'organization_ref' => $identity->organizationId, 'audience' => $identity->audience,
                'publication_id' => (int) $publication['id'],
                'knowledge_base_version_id' => (int) $publication['active_version_id'],
                'source_scope_sha256' => (string) $publication['source_scope_sha256'],
                'data_class' => $publication['data_class'] ?? null,
            ]),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $facts */
    private function assertSame(array $row, array $facts): void
    {
        foreach ($facts as $field => $value) {
            if (is_int($value)) {
                if ((int) ($row[$field] ?? 0) !== $value) $this->conflict();
            } elseif (($row[$field] ?? null) !== $value) {
                $this->conflict();
            }
        }
    }

    private function conflict(): never
    {
        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original knowledge query intent is different or unverifiable');
    }
}
