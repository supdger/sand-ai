<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use think\facade\Db;

/**
 * Stores complete private embedding snapshots and switches a ready index
 * without changing the immutable published knowledge version.
 */
final class KnowledgeEmbeddingIndexGateway
{
    /** @param array<string,mixed> $publication */
    public function hasActiveIndex(array $publication): bool
    {
        $versionId = (int) ($publication['active_version_id'] ?? 0);
        if ($versionId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge publication is invalid');
        }
        return Db::connect('pgsql')->query(
            'SELECT 1 FROM sand_ai_knowledge_embedding_active WHERE knowledge_base_version_id=?',
            [$versionId],
        ) !== [];
    }

    /**
     * Recheck live private sources against the frozen index before building,
     * recovering a result or reporting readiness. This is a source-facts check;
     * the entry controller remains responsible for current admin permission.
     * @param array<string,mixed> $index
     */
    public function assertSourceCurrent(array $index): void
    {
        $environmentId = (int) ($index['owner_environment_id'] ?? 0);
        $versionId = (int) ($index['knowledge_base_version_id'] ?? 0);
        $rows = Db::connect('pgsql')->query(
            "SELECT v.snapshot_sha256,v.source_scope_sha256,v.data_class,v.source_count
             FROM sand_ai_knowledge_base_version v
             JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id
             WHERE v.id=? AND kb.environment_id=? AND kb.state='published'
               AND kb.status=1 AND kb.delete_time IS NULL AND v.state='published'
               AND kb.active_version_id=v.id",
            [$versionId, $environmentId],
        );
        if (count($rows) !== 1
            || !is_string($index['source_snapshot_sha256'] ?? null)
            || !is_string($index['source_scope_sha256'] ?? null)
            || !hash_equals((string) $rows[0]['snapshot_sha256'], $index['source_snapshot_sha256'])
            || !hash_equals((string) $rows[0]['source_scope_sha256'], $index['source_scope_sha256'])
            || (int) $rows[0]['source_count'] !== (int) ($index['source_count'] ?? 0)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Embedding source snapshot changed');
        }
        $this->assertCurrentSourceScope([
            'owner_environment_id' => $environmentId,
            'active_version_id' => $versionId,
            'source_scope_sha256' => $index['source_scope_sha256'],
            'data_class' => $rows[0]['data_class'],
        ], $environmentId, $versionId);
        (new KnowledgeBaseQueryGateway())->queryVersion($environmentId, $versionId, 'source', ['limit' => 1]);
    }

    /** Remove private vectors for a disabled knowledge base; usage facts remain immutable. */
    public function purgeDisabled(int $environmentId, int $knowledgeBaseId, string $requestId, string $actorRef): array
    {
        if ($environmentId < 1 || $knowledgeBaseId < 1 || trim($requestId) === ''
            || strlen($requestId) > 128 || trim($actorRef) === '') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding purge request is invalid');
        }
        try {
            (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $knowledgeBaseId, $requestId, $actorRef,
        ): array {
            $db = Db::connect('pgsql');
            $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                $environmentId . ':knowledge-operation:purge_vectors:' . $requestId,
            ]);
            $previous = $db->query(
                "SELECT knowledge_base_id FROM sand_ai_knowledge_operation
                 WHERE environment_id=? AND operation_code='purge_vectors' AND idempotency_key=? FOR UPDATE",
                [$environmentId, $requestId],
            );
            if ($previous !== []) {
                if ((int) $previous[0]['knowledge_base_id'] !== $knowledgeBaseId) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Embedding purge request changed');
                }
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
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Disable knowledge base before purging vectors');
            }
            $indexes = $db->query(
                'SELECT i.id FROM sand_ai_knowledge_embedding_index i
                 JOIN sand_ai_knowledge_base_version v ON v.id=i.knowledge_base_version_id
                 WHERE v.knowledge_base_id=? ORDER BY i.id FOR UPDATE OF i',
                [$knowledgeBaseId],
            );
            if ($previous !== []) {
                // This operation committed atomically with the original index
                // deletion. Any current index belongs to a later build and must
                // receive a new explicit cleanup key.
                if ($indexes !== []) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge vectors changed after purge');
                }
                return ['knowledge_base_id' => $knowledgeBaseId, 'state' => 'purged', 'idempotent_replay' => true];
            }
            $this->assertNoUnsettledForBase($knowledgeBaseId);
            $receiptsPurged = $this->purgeVectorReceipts($knowledgeBaseId);
            $db->execute(
                'DELETE FROM sand_ai_knowledge_embedding_active a USING sand_ai_knowledge_embedding_index i,
                    sand_ai_knowledge_base_version v
                 WHERE a.index_id=i.id AND i.knowledge_base_version_id=v.id AND v.knowledge_base_id=?',
                [$knowledgeBaseId],
            );
            $db->execute(
                'DELETE FROM sand_ai_knowledge_embedding_index i USING sand_ai_knowledge_base_version v
                 WHERE i.knowledge_base_version_id=v.id AND v.knowledge_base_id=?',
                [$knowledgeBaseId],
            );
            $remaining = $db->query(
                'SELECT 1 FROM sand_ai_knowledge_embedding_index i
                 JOIN sand_ai_knowledge_base_version v ON v.id=i.knowledge_base_version_id
                 WHERE v.knowledge_base_id=? LIMIT 1',
                [$knowledgeBaseId],
            );
            if ($remaining !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge vectors remain after purge');
            }
            $db->execute(
                "INSERT INTO sand_ai_knowledge_operation
                 (environment_id,operation_code,idempotency_key,request_fingerprint,knowledge_base_id,version_id)
                 VALUES (?,'purge_vectors',?,?,?,?)",
                [$environmentId, $requestId, hash('sha256', (string) $knowledgeBaseId),
                    $knowledgeBaseId, $base[0]['active_version_id']],
            );
            AuditLog::create([
                'actor_type' => 'admin',
                'actor_ref' => trim($actorRef),
                'action' => 'knowledge.embedding.purge',
                'resource_type' => 'knowledge_base',
                'resource_id' => $knowledgeBaseId,
                'summary' => 'Disabled knowledge base vectors purged',
                'context' => ['environment_id' => $environmentId, 'vector_receipts_purged' => $receiptsPurged,
                    'request_id' => $requestId, 'request_fingerprint' => hash('sha256', (string) $knowledgeBaseId)],
            ]);
            return ['knowledge_base_id' => $knowledgeBaseId, 'state' => 'purged', 'idempotent_replay' => false];
        });
    }

    /**
     * Called with the disabled base and all its index rows locked. Index
     * dispatch identities must be resolved before those rows disappear.
     */
    private function purgeVectorReceipts(int $knowledgeBaseId): int
    {
        $db = Db::connect('pgsql');
        $invocations = $db->query(
            "SELECT invocation.id,invocation.environment_id,invocation.model_id,
                    invocation.deployment_id,invocation.request_fingerprint,
                    invocation.state AS invocation_state,dispatch.state AS dispatch_state
             FROM sand_ai_invocation invocation
             JOIN sand_ai_invocation_dispatch dispatch ON dispatch.invocation_id=invocation.id
             WHERE dispatch.execution_scope='embedding' AND dispatch.capability_code='embedding'
               AND (
                 EXISTS (
                   SELECT 1 FROM sand_ai_knowledge_embedding_index idx
                   JOIN sand_ai_knowledge_base_version version ON version.id=idx.knowledge_base_version_id
                   JOIN sand_ai_knowledge_base_source source ON source.knowledge_base_version_id=version.id
                   WHERE version.knowledge_base_id=? AND invocation.environment_id=idx.owner_environment_id
                     AND invocation.request_id='knowledge-embedding:' || encode(sha256(convert_to(
                         idx.id::text || ':' || source.source_block_id::text,'UTF8')),'hex')
                     AND dispatch.environment_id=idx.owner_environment_id
                     AND dispatch.logical_execution_id=left(encode(sha256(convert_to(
                         'embedding:' || idx.owner_environment_id::text || ':' || idx.id::text || ':'
                         || source.source_block_id::text,'UTF8')),'hex'),48)
                 )
                 OR EXISTS (
                   SELECT 1 FROM sand_ai_knowledge_base_version version
                   JOIN sand_ai_knowledge_publication publication
                     ON publication.knowledge_base_id=version.knowledge_base_id
                   WHERE version.knowledge_base_id=? AND version.id=invocation.knowledge_base_version_id
                     AND publication.id=invocation.knowledge_publication_id
                     AND publication.consumer_environment_id=invocation.environment_id
                     AND version.source_scope_sha256=invocation.knowledge_source_scope_sha256
                     AND dispatch.environment_id=invocation.environment_id
                 )
               )
             ORDER BY invocation.id",
            [$knowledgeBaseId, $knowledgeBaseId],
        );
        $count = 0;
        foreach ($invocations as $invocation) {
            if (!in_array($invocation['invocation_state'], ['succeeded', 'failed'], true)
                || in_array($invocation['dispatch_state'], ['prepared', 'dispatching', 'ambiguous'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Reconcile knowledge embedding calls before purging vectors');
            }
            $rows = $db->query(
                'SELECT id,state,environment_id,model_id,deployment_id,request_fingerprint,capability_code
                 FROM sand_ai_invocation_outcome_receipt WHERE invocation_id=? FOR UPDATE',
                [(int) $invocation['id']],
            );
            if ($rows === []) continue;
            $receipt = $rows[0];
            if ($receipt['capability_code'] !== 'embedding'
                || (int) $receipt['environment_id'] !== (int) $invocation['environment_id']
                || (int) $receipt['model_id'] !== (int) $invocation['model_id']
                || (int) $receipt['deployment_id'] !== (int) $invocation['deployment_id']
                || !hash_equals((string) $receipt['request_fingerprint'], (string) $invocation['request_fingerprint'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge vector receipt ownership is unavailable');
            }
            if ($receipt['state'] === 'destroyed') continue;
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt
                 SET state='expired',cleanup_owner='sand-ai-knowledge-vector-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND state IN ('available','expired')",
                [(int) $receipt['id']],
            );
            $destroyed = $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND state='expired' AND cleanup_owner='sand-ai-knowledge-vector-purge'",
                [(int) $receipt['id']],
            );
            if ($destroyed !== 1) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge vector receipt changed during purge');
            }
            $count += $destroyed;
        }
        return $count;
    }

    /** Called after locking the base row, before disable or vector purge. */
    public function assertNoUnsettledForBase(int $knowledgeBaseId): void
    {
        $busy = Db::connect('pgsql')->query(
            "SELECT 1 FROM sand_ai_knowledge_embedding_index i
             JOIN sand_ai_knowledge_base_version v ON v.id=i.knowledge_base_version_id
             JOIN sand_ai_knowledge_base_source s ON s.knowledge_base_version_id=v.id
             JOIN sand_ai_invocation_dispatch d ON d.environment_id=i.owner_environment_id
               AND d.execution_scope='embedding'
               AND d.logical_execution_id=left(encode(sha256(convert_to(
                   'embedding:' || i.owner_environment_id::text || ':' || i.id::text || ':' || s.source_block_id::text,
                   'UTF8'
               )),'hex'),48)
             WHERE v.knowledge_base_id=? AND d.state IN ('prepared','dispatching','ambiguous')
             LIMIT 1",
            [$knowledgeBaseId],
        );
        if ($busy !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding invocation must be reconciled before disabling or purging');
        }
    }

    /** @return array{id:int,state:string,source_count:int,indexed_count:int,dimensions:?int} */
    public function status(int $environmentId, int $indexId): array
    {
        if ($environmentId < 1 || $indexId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding index lookup is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $indexId): array {
            $rows = Db::connect('pgsql')->query(
                "SELECT i.*,
                        (SELECT count(*) FROM sand_ai_knowledge_embedding e WHERE e.index_id=i.id) indexed_count
                   FROM sand_ai_knowledge_embedding_index i
                   WHERE i.id=? AND i.owner_environment_id=?
                   FOR SHARE OF i",
                [$indexId, $environmentId],
            );
            if (count($rows) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Embedding index not found');
            }
            $row = $rows[0];
            // A persisted ready state cannot establish that the frozen sources
            // still belong to the current published version and live parse.
            $this->assertSourceCurrent($row);
            return [
                'id' => (int) $row['id'], 'state' => (string) $row['state'],
                'source_count' => (int) $row['source_count'],
                'indexed_count' => (int) $row['indexed_count'],
                'dimensions' => $row['dimensions'] === null ? null : (int) $row['dimensions'],
            ];
        });
    }

    /** Read an existing preparation by its unique original key; never reserves an index. */
    public function readByRequestKey(int $environmentId, int $versionId, string $requestId): array
    {
        if ($environmentId < 1 || $versionId < 1 || trim($requestId) === '' || strlen($requestId) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $requestId) === 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding index lookup is invalid');
        }
        $rows = Db::connect('pgsql')->query(
            'SELECT id,owner_environment_id,knowledge_base_version_id,request_id FROM sand_ai_knowledge_embedding_index WHERE request_id=? AND owner_environment_id=? AND knowledge_base_version_id=?',
            [$requestId, $environmentId, $versionId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original embedding index is unavailable; retain its request key');
        }
        // status revalidates current private source ownership and the exact
        // active version, without dispatching or changing the index.
        $result = $this->status($environmentId, (int) $rows[0]['id']);
        return $result + ['owner_environment_id' => $environmentId, 'knowledge_base_version_id' => $versionId,
            'request_id' => $requestId, 'idempotent_replay' => true];
    }

    /** @return array{id:int,state:string,source_count:int,dimensions:?int,idempotent_replay:bool} */
    public function begin(int $environmentId, int $versionId, string $requestId): array
    {
        if ($environmentId < 1 || $versionId < 1 || trim($requestId) === '' || strlen($requestId) > 160) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding index request is invalid');
        }
        $route = (new CapabilityProfileResolver())->primary($environmentId, 'embedding');
        if ($route === null || (int) ($route['deployment_id'] ?? 0) < 1
            || (int) ($route['model_id'] ?? 0) < 1) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published embedding route is available');
        }
        $deployment = ModelDeployment::where('id', (int) $route['deployment_id'])->where('status', 1)->find();
        $provider = Provider::where('id', (int) $route['provider_id'])->where('status', 1)->find();
        if ($deployment === null || $provider === null || (string) $provider->adapter !== 'openai_compatible'
            || (int) $deployment->provider_id !== (int) $provider->id
            || (int) $deployment->model_id !== (int) $route['model_id']) {
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'The embedding route has no executable provider');
        }
        $providerConfigHash = $this->providerConfigHash($provider);
        try {
            $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        // queryVersion verifies the complete current source snapshot before any
        // index row is reserved; the query text itself has no effect on readiness.
        (new KnowledgeBaseQueryGateway())->queryVersion($environmentId, $versionId, 'source', ['limit' => 1]);
        $scope = (new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $versionId, $requestId, $route, $scope, $deployment, $provider, $providerConfigHash): array {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 139))', ['sand_ai_embedding_index:' . $requestId]);
            // Old versions remain published for immutable consumers. An index
            // build is useful only for the base's current published version,
            // including an idempotent retry of an earlier start request.
            $version = Db::connect('pgsql')->query(
                "SELECT v.snapshot_sha256,v.source_scope_sha256,v.source_count,v.data_class
                   FROM sand_ai_knowledge_base_version v
                   JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id
                  WHERE v.id=? AND kb.environment_id=? AND kb.state='published'
                    AND kb.status=1 AND kb.delete_time IS NULL AND v.state='published'
                    AND kb.active_version_id=v.id
                  FOR SHARE OF v,kb",
                [$versionId, $environmentId],
            );
            if (count($version) !== 1
                || (string) $version[0]['source_scope_sha256'] !== $scope['source_scope_sha256']
                || $version[0]['data_class'] !== $scope['data_class']) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Published knowledge source changed');
            }
            $rows = Db::connect('pgsql')->query(
                'SELECT i.* FROM sand_ai_knowledge_embedding_index i WHERE i.request_id=? FOR UPDATE',
                [$requestId],
            );
            if ($rows !== []) {
                $old = $rows[0];
                if ((int) $old['owner_environment_id'] !== $environmentId
                    || (int) $old['knowledge_base_version_id'] !== $versionId
                    || (int) $old['profile_id'] !== (int) $route['profile_id']
                    || (int) $old['profile_revision'] !== (int) $route['profile_revision']
                    || (int) $old['route_id'] !== (int) $route['route_id']
                    || (int) $old['deployment_id'] !== (int) $route['deployment_id']
                    || (int) $old['deployment_revision'] !== (int) $deployment->revision
                    || (string) $old['remote_model'] !== (string) $deployment->remote_model
                    || (int) $old['provider_id'] !== (int) $provider->id
                    || (string) $old['provider_config_sha256'] !== $providerConfigHash
                    || (int) $old['model_id'] !== (int) $route['model_id']
                    || (string) $old['source_scope_sha256'] !== $scope['source_scope_sha256']) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Embedding index request changed');
                }
                return $this->summary($old, true);
            }
            $created = Db::connect('pgsql')->query(
                "INSERT INTO sand_ai_knowledge_embedding_index
                 (knowledge_base_version_id,owner_environment_id,profile_id,profile_revision,route_id,
                  deployment_id,deployment_revision,remote_model,provider_id,provider_config_sha256,
                  model_id,request_id,source_snapshot_sha256,source_scope_sha256,source_count)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *",
                [
                    $versionId, $environmentId, (int) $route['profile_id'], (int) $route['profile_revision'],
                    (int) $route['route_id'], (int) $route['deployment_id'], (int) $deployment->revision,
                    (string) $deployment->remote_model, (int) $provider->id, $providerConfigHash, (int) $route['model_id'],
                    $requestId, (string) $version[0]['snapshot_sha256'], $scope['source_scope_sha256'],
                    (int) $version[0]['source_count'],
                ],
            );
            return $this->summary($created[0], false);
        });
    }

    /**
     * The caller passes only vectors from the validated Embedding provider
     * outcome, paired with their frozen source block IDs and content digests.
     * @param list<array{source_block_id:int,content_sha256:string,embedding:list<float>}> $vectors
     */
    public function append(int $indexId, int $invocationId, array $vectors): void
    {
        if ($indexId < 1 || $invocationId < 1 || $vectors === [] || count($vectors) > 16) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding batch is invalid');
        }
        Db::connect('pgsql')->transaction(function () use ($indexId, $invocationId, $vectors): void {
            $rows = Db::connect('pgsql')->query(
                'SELECT id,state,dimensions FROM sand_ai_knowledge_embedding_index WHERE id=? FOR UPDATE',
                [$indexId],
            );
            if (count($rows) !== 1 || $rows[0]['state'] !== 'building') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index is no longer writable');
            }
            $dimensions = $rows[0]['dimensions'] === null ? null : (int) $rows[0]['dimensions'];
            foreach ($vectors as $item) {
                $blockId = $item['source_block_id'] ?? null;
                $hash = $item['content_sha256'] ?? null;
                $vector = $item['embedding'] ?? null;
                if (!is_int($blockId) || $blockId < 1 || !is_string($hash)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
                    || !is_array($vector) || !array_is_list($vector)
                    || count($vector) < 1 || count($vector) > 4096
                    || ($dimensions !== null && count($vector) !== $dimensions)) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding vector is invalid');
                }
                $dimensions ??= count($vector);
                $numbers = [];
                $norm = 0.0;
                foreach ($vector as $value) {
                    if (!is_float($value) || !is_finite($value) || abs($value) > 1.000001) {
                        throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding vector is invalid');
                    }
                    $numbers[] = $value;
                    $norm += $value * $value;
                }
                if ($norm < 0.999 || $norm > 1.001) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding vector is not normalized');
                }
                $digest = hash('sha256', json_encode($numbers, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                $existing = Db::connect('pgsql')->query(
                    'SELECT content_sha256,embedding_sha256,invocation_id FROM sand_ai_knowledge_embedding
                     WHERE index_id=? AND source_block_id=? FOR UPDATE',
                    [$indexId, $blockId],
                );
                if ($existing !== []) {
                    if ((string) $existing[0]['content_sha256'] !== $hash
                        || (string) $existing[0]['embedding_sha256'] !== $digest
                        || (int) $existing[0]['invocation_id'] !== $invocationId) {
                        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Embedding source result changed');
                    }
                    continue;
                }
                if ($rows[0]['dimensions'] === null) {
                    Db::connect('pgsql')->execute(
                        'UPDATE sand_ai_knowledge_embedding_index SET dimensions=? WHERE id=? AND dimensions IS NULL',
                        [$dimensions, $indexId],
                    );
                    $rows[0]['dimensions'] = $dimensions;
                }
                $literal = '{' . implode(',', array_map(
                    static fn (float $number): string => json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    $numbers,
                )) . '}';
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_knowledge_embedding
                     (index_id,source_block_id,invocation_id,content_sha256,embedding_sha256,embedding)
                     VALUES (?,?,?,?,?,CAST(? AS real[]))',
                    [$indexId, $blockId, $invocationId, $hash, $digest, $literal],
                );
            }
        });
    }

    /** @return array{id:int,state:string,source_count:int,dimensions:?int,idempotent_replay:bool} */
    public function activate(int $indexId): array
    {
        if ($indexId < 1) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding index is invalid');
        return Db::connect('pgsql')->transaction(function () use ($indexId): array {
            $rows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_knowledge_embedding_index WHERE id=? FOR UPDATE',
                [$indexId],
            );
            if (count($rows) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Embedding index not found');
            $index = $rows[0];
            $this->assertSourceCurrent($index);
            if ($index['state'] === 'ready') return $this->summary($index, true);
            if ($index['state'] !== 'building') throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index is not buildable');
            $route = (new CapabilityProfileResolver())->primary((int) $index['owner_environment_id'], 'embedding');
            if ($route === null || (int) ($route['profile_id'] ?? 0) !== (int) $index['profile_id']
                || (int) ($route['profile_revision'] ?? 0) !== (int) $index['profile_revision']
                || (int) ($route['route_id'] ?? 0) !== (int) $index['route_id']
                || (int) ($route['deployment_id'] ?? 0) !== (int) $index['deployment_id']) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Embedding route changed during index build');
            }
            $this->assertRuntimeCurrent($index);
            $updated = Db::connect('pgsql')->query(
                "UPDATE sand_ai_knowledge_embedding_index
                    SET state='ready',ready_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                  WHERE id=? AND state='building' RETURNING *",
                [$indexId],
            );
            if (count($updated) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Embedding index changed');
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_knowledge_embedding_active
                 (knowledge_base_version_id,index_id) VALUES (?,?)
                 ON CONFLICT (knowledge_base_version_id)
                 DO UPDATE SET index_id=EXCLUDED.index_id,update_time=CURRENT_TIMESTAMP(0)',
                [(int) $index['knowledge_base_version_id'], $indexId],
            );
            return $this->summary($updated[0], false);
        });
    }

    /**
     * Resolve the current index before creating a query vector. The caller
     * must already hold the current SandIAM knowledge grant.
     * @param array<string,mixed> $publication
     * @return array<string,mixed>
     */
    public function currentIndex(array $publication): array
    {
        $ownerEnvironmentId = (int) ($publication['owner_environment_id'] ?? 0);
        $versionId = (int) ($publication['active_version_id'] ?? 0);
        $this->assertCurrentSourceScope($publication, $ownerEnvironmentId, $versionId);
        $route = (new CapabilityProfileResolver())->primary($ownerEnvironmentId, 'embedding');
        $active = Db::connect('pgsql')->query(
            "SELECT i.id,i.owner_environment_id,i.knowledge_base_version_id,i.dimensions,i.source_scope_sha256,i.source_snapshot_sha256,i.profile_id,i.profile_revision,i.route_id,i.deployment_id,
                    i.deployment_revision,i.remote_model,i.provider_id,i.provider_config_sha256,i.model_id
               FROM sand_ai_knowledge_embedding_active a
               JOIN sand_ai_knowledge_embedding_index i ON i.id=a.index_id
               JOIN sand_ai_knowledge_base_version v ON v.id=i.knowledge_base_version_id
               JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id
               JOIN sand_ai_knowledge_publication p ON p.knowledge_base_id=kb.id
              WHERE a.knowledge_base_version_id=? AND i.knowledge_base_version_id=?
                AND i.owner_environment_id=? AND i.state='ready'
                AND v.state='published' AND kb.active_version_id=v.id
                AND kb.environment_id=? AND kb.state='published' AND kb.status=1
                AND kb.delete_time IS NULL AND p.id=? AND p.code=? AND p.state='published'
                AND p.owner_environment_id=? AND v.source_scope_sha256=?
                AND v.data_class IS NOT DISTINCT FROM ?
                AND i.source_snapshot_sha256=v.snapshot_sha256",
            [$versionId, $versionId, $ownerEnvironmentId, $ownerEnvironmentId,
                (int) ($publication['id'] ?? 0), (string) ($publication['code'] ?? ''),
                $ownerEnvironmentId, (string) ($publication['source_scope_sha256'] ?? ''),
                $publication['data_class'] ?? null],
        );
        if (count($active) !== 1 || $route === null
            || (int) $active[0]['profile_id'] !== (int) ($route['profile_id'] ?? 0)
            || (int) $active[0]['profile_revision'] !== (int) ($route['profile_revision'] ?? 0)
            || (int) $active[0]['route_id'] !== (int) ($route['route_id'] ?? 0)
            || (int) $active[0]['deployment_id'] !== (int) ($route['deployment_id'] ?? 0)
            || !hash_equals((string) $active[0]['source_scope_sha256'], (string) $publication['source_scope_sha256'])
            || (int) $active[0]['dimensions'] < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Current embedding index is unavailable');
        }
        $this->assertRuntimeCurrent($active[0]);
        return $active[0];
    }

    /**
     * Internal read for a caller that already holds the current SandIAM
     * knowledge grant. Every returned block is rechecked against live source
     * facts; the active index only supplies ranking.
     * @param array<string,mixed> $publication
     * @param list<float> $queryVector
     * @return list<array<string,mixed>>
     */
    public function search(array $publication, string $query, array $queryVector, int $limit, int $expectedIndexId): array
    {
        if ($limit < 1 || $limit > 20 || $expectedIndexId < 1
            || !array_is_list($queryVector) || $queryVector === []) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding search request is invalid');
        }
        $ownerEnvironmentId = (int) ($publication['owner_environment_id'] ?? 0);
        $versionId = (int) ($publication['active_version_id'] ?? 0);
        $base = (new KnowledgeBaseQueryGateway())->query($publication, ['query' => $query, 'limit' => 1]);
        $active = $this->currentIndex($publication);
        if ((int) $active['id'] !== $expectedIndexId || count($queryVector) !== (int) $active['dimensions']) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Current embedding index changed');
        }
        $norm = 0.0;
        foreach ($queryVector as $value) {
            if (!is_float($value) || !is_finite($value) || abs($value) > 1.000001) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding query vector is invalid');
            }
            $norm += $value * $value;
        }
        if ($norm < 0.999 || $norm > 1.001) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Embedding query vector is not normalized');
        }
        $literal = '{' . implode(',', array_map(
            static fn (float $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $queryVector,
        )) . '}';
        $rows = Db::connect('pgsql')->query(
            "SELECT s.file_id,s.parse_id,s.source_block_id,s.locator_type,s.locator,b.content,
                    sand_ai_embedding_cosine(e.embedding,CAST(? AS real[])) AS score
               FROM sand_ai_knowledge_embedding e
               JOIN sand_ai_knowledge_base_source s ON s.source_block_id=e.source_block_id
                    AND s.knowledge_base_version_id=?
               JOIN sand_ai_file f ON f.id=s.file_id
               JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=f.id
               JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=f.id AND b.parse_id=p.id
              WHERE e.index_id=? AND e.content_sha256=s.content_sha256
                AND f.environment_id=? AND f.state='parsed' AND f.status=1 AND f.delete_time IS NULL
                AND p.state='succeeded' AND p.status=1 AND p.delete_time IS NULL
                AND b.status=1 AND b.delete_time IS NULL
                AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest
                                 WHERE latest.file_id=f.id AND latest.state='succeeded'
                                   AND latest.status=1 AND latest.delete_time IS NULL AND latest.id>p.id)
                AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator
                AND b.content_sha256=s.content_sha256
                AND encode(sha256(convert_to(b.content,'UTF8')),'hex')=s.content_sha256
              ORDER BY score DESC,s.source_block_id ASC LIMIT " . $limit,
            [$literal, $versionId, $expectedIndexId, $ownerEnvironmentId],
        );
        $citations = [];
        foreach ($rows as $row) {
            $locator = is_string($row['locator']) ? json_decode($row['locator'], true) : $row['locator'];
            $citations[] = [
                'knowledge_base_code' => (string) $base['knowledge_base']['code'],
                'knowledge_base_version' => (int) $base['knowledge_base']['version'],
                'file_id' => (int) $row['file_id'], 'parse_id' => (int) $row['parse_id'],
                'source_block_id' => (int) $row['source_block_id'],
                'locator_type' => (string) $row['locator_type'],
                'locator' => is_array($locator) ? $locator : [],
                'excerpt' => mb_strcut(trim((string) $row['content']), 0, 500, 'UTF-8'),
                'score' => round((float) $row['score'], 6),
            ];
        }
        if ((int) $this->currentIndex($publication)['id'] !== $expectedIndexId) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Current embedding index changed');
        }
        return $citations;
    }

    /** @param array<string,mixed> $publication */
    private function assertCurrentSourceScope(array $publication, int $ownerEnvironmentId, int $versionId): void
    {
        if ($ownerEnvironmentId < 1 || $versionId < 1
            || !is_string($publication['source_scope_sha256'] ?? null)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source scope is unavailable');
        }
        try {
            $owner = (new HostSandIamEnvironmentFacts())->resolve($ownerEnvironmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        $scope = (new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
        if (!hash_equals($publication['source_scope_sha256'], $scope['source_scope_sha256'])
            || ($publication['data_class'] ?? null) !== $scope['data_class']) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source ownership changed');
        }
    }

    /** @param array<string,mixed> $row @return array{id:int,state:string,source_count:int,dimensions:?int,idempotent_replay:bool} */
    private function summary(array $row, bool $replay): array
    {
        return [
            'id' => (int) $row['id'], 'state' => (string) $row['state'],
            'source_count' => (int) $row['source_count'],
            'dimensions' => $row['dimensions'] === null ? null : (int) $row['dimensions'],
            'idempotent_replay' => $replay,
        ];
    }

    /** @param array<string,mixed> $index */
    private function assertRuntimeCurrent(array $index): void
    {
        $deployment = ModelDeployment::where('id', (int) $index['deployment_id'])->where('status', 1)->find();
        $provider = Provider::where('id', (int) $index['provider_id'])->where('status', 1)->find();
        if ($deployment === null || $provider === null
            || (int) $deployment->provider_id !== (int) $provider->id
            || (int) $deployment->model_id !== (int) $index['model_id']
            || (int) $deployment->revision !== (int) $index['deployment_revision']
            || (string) $deployment->remote_model !== (string) $index['remote_model']
            || (string) $provider->adapter !== 'openai_compatible'
            || $this->providerConfigHash($provider) !== (string) $index['provider_config_sha256']) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'Embedding model configuration changed');
        }
    }

    private function providerConfigHash(Provider $provider): string
    {
        $config = is_object($provider->encrypted_config) ? (array) $provider->encrypted_config : $provider->encrypted_config;
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Embedding provider configuration is unavailable');
        }
        // Must match DispatchRouteSnapshot::freeze()'s canonical provider_config_sha256
        // so a claimed knowledge-query dispatch's frozen route snapshot can be matched
        // back to the embedding index it was built against.
        return DispatchRouteSnapshot::configHash($config);
    }
}
