<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class KnowledgePublicationGateway
{
    public function __construct(private readonly ?HostSandIamEnvironmentFacts $environmentFacts = null)
    {
    }

    /** @return array<string,mixed> */
    public function publish(int $ownerEnvironmentId, int $knowledgeBaseId, int $consumerEnvironmentId, string $code, string $idempotencyKey, string $actorRef): array
    {
        $code = trim($code);
        $idempotencyKey = trim($idempotencyKey);
        if ($ownerEnvironmentId < 1 || $knowledgeBaseId < 1 || $consumerEnvironmentId < 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1
            || $idempotencyKey === '' || strlen($idempotencyKey) > 128 || trim($actorRef) === '') {
            self::invalid();
        }
        $owner = $this->environment($ownerEnvironmentId);
        $consumer = $this->environment($consumerEnvironmentId);
        $fingerprint = hash('sha256', json_encode([$knowledgeBaseId, $consumerEnvironmentId, $code], JSON_THROW_ON_ERROR));

        return Db::connect('pgsql')->transaction(function () use ($ownerEnvironmentId, $knowledgeBaseId, $consumerEnvironmentId, $code, $idempotencyKey, $actorRef, $owner, $consumer, $fingerprint): array {
            $this->lock('publish:' . $ownerEnvironmentId . ':' . $idempotencyKey);
            $previous = Db::connect('pgsql')->query(
                'SELECT publication_id,request_fingerprint,result_state FROM sand_ai_knowledge_publication_operation
                 WHERE owner_environment_id=? AND operation_code=? AND idempotency_key=? FOR UPDATE',
                [$ownerEnvironmentId, 'publish', $idempotencyKey],
            );
            if ($previous !== []) {
                if (!hash_equals((string) $previous[0]['request_fingerprint'], $fingerprint)) self::conflict();
                return ['publication_id' => (int) $previous[0]['publication_id'], 'state' => (string) $previous[0]['result_state'], 'idempotent_replay' => true];
            }
            $this->lock('consumer:' . $consumerEnvironmentId . ':' . $code);
            $this->lock('base:' . $knowledgeBaseId . ':' . $consumerEnvironmentId);
            $base = Db::connect('pgsql')->query(
                "SELECT kb.id,kb.active_version_id,v.source_scope_sha256,v.data_class
                 FROM sand_ai_knowledge_base kb
                 JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
                 WHERE kb.id=? AND kb.environment_id=? AND kb.delete_time IS NULL
                   AND kb.status=1 AND kb.state='published' AND v.state='published'
                 FOR UPDATE OF kb",
                [$knowledgeBaseId, $ownerEnvironmentId],
            );
            if ($base === [] || !is_string($base[0]['source_scope_sha256']) || $base[0]['source_scope_sha256'] === '') {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Published knowledge version has no verified source scope');
            }
            $sourceScope = (new KnowledgeVersionSourceScope())->resolve((int) $base[0]['active_version_id'], $owner);
            if (!hash_equals((string) $base[0]['source_scope_sha256'], $sourceScope['source_scope_sha256'])
                || $base[0]['data_class'] !== $sourceScope['data_class']) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Published knowledge source ownership changed');
            }
            $alias = Db::connect('pgsql')->query(
                'SELECT id,knowledge_base_id FROM sand_ai_knowledge_publication
                 WHERE consumer_environment_id=? AND code=? FOR UPDATE',
                [$consumerEnvironmentId, $code],
            );
            if ($alias !== [] && (int) $alias[0]['knowledge_base_id'] !== $knowledgeBaseId) self::conflict();
            $existing = Db::connect('pgsql')->query(
                'SELECT id,code,owner_environment_id FROM sand_ai_knowledge_publication
                 WHERE knowledge_base_id=? AND consumer_environment_id=? FOR UPDATE',
                [$knowledgeBaseId, $consumerEnvironmentId],
            );
            if ($existing !== []) {
                if ($existing[0]['code'] !== $code || (int) $existing[0]['owner_environment_id'] !== $ownerEnvironmentId) self::conflict();
                $publicationId = (int) $existing[0]['id'];
                Db::connect('pgsql')->execute(
                    "UPDATE sand_ai_knowledge_publication SET consumer_organization_id=?,consumer_application_id=?,state='published',update_time=CURRENT_TIMESTAMP WHERE id=?",
                    [$consumer['organization_id'], $consumer['application_id'], $publicationId],
                );
            } else {
                Db::connect('pgsql')->execute(
                    "INSERT INTO sand_ai_knowledge_publication
                     (knowledge_base_id,owner_environment_id,consumer_organization_id,consumer_application_id,consumer_environment_id,code,state)
                     VALUES (?,?,?,?,?,?,'published')",
                    [$knowledgeBaseId, $ownerEnvironmentId, $consumer['organization_id'], $consumer['application_id'], $consumerEnvironmentId, $code],
                );
                $created = Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_knowledge_publication WHERE knowledge_base_id=? AND consumer_environment_id=?',
                    [$knowledgeBaseId, $consumerEnvironmentId],
                );
                if (count($created) !== 1) self::conflict();
                $publicationId = (int) $created[0]['id'];
            }
            Db::connect('pgsql')->execute(
                "INSERT INTO sand_ai_knowledge_publication_operation
                 (owner_environment_id,operation_code,idempotency_key,request_fingerprint,publication_id,result_state)
                 VALUES (?,'publish',?,?,?,'published')",
                [$ownerEnvironmentId, $idempotencyKey, $fingerprint, $publicationId],
            );
            $this->audit($actorRef, 'knowledge_base.publication.publish', $publicationId, $knowledgeBaseId, $consumerEnvironmentId, ['owner_environment_id' => $ownerEnvironmentId, 'request_id' => $idempotencyKey, 'request_fingerprint' => $fingerprint]);
            return ['publication_id' => $publicationId, 'state' => 'published', 'idempotent_replay' => false];
        });
    }

    /** @return array<string,mixed> */
    public function revoke(int $ownerEnvironmentId, int $publicationId, string $idempotencyKey, string $actorRef): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($ownerEnvironmentId < 1 || $publicationId < 1 || $idempotencyKey === ''
            || strlen($idempotencyKey) > 128 || trim($actorRef) === '') self::invalid();
        $fingerprint = hash('sha256', (string) $publicationId);
        return Db::connect('pgsql')->transaction(function () use ($ownerEnvironmentId, $publicationId, $idempotencyKey, $actorRef, $fingerprint): array {
            $this->lock('revoke:' . $ownerEnvironmentId . ':' . $idempotencyKey);
            $previous = Db::connect('pgsql')->query(
                'SELECT publication_id,request_fingerprint,result_state FROM sand_ai_knowledge_publication_operation
                 WHERE owner_environment_id=? AND operation_code=? AND idempotency_key=? FOR UPDATE',
                [$ownerEnvironmentId, 'revoke', $idempotencyKey],
            );
            if ($previous !== []) {
                if (!hash_equals((string) $previous[0]['request_fingerprint'], $fingerprint)) self::conflict();
                return ['publication_id' => (int) $previous[0]['publication_id'], 'state' => (string) $previous[0]['result_state'], 'idempotent_replay' => true];
            }
            $row = Db::connect('pgsql')->query(
                'SELECT knowledge_base_id,consumer_environment_id FROM sand_ai_knowledge_publication
                 WHERE id=? AND owner_environment_id=? FOR UPDATE',
                [$publicationId, $ownerEnvironmentId],
            );
            if ($row === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge publication not found');
            Db::connect('pgsql')->execute(
                "UPDATE sand_ai_knowledge_publication SET state='revoked',update_time=CURRENT_TIMESTAMP WHERE id=?",
                [$publicationId],
            );
            Db::connect('pgsql')->execute(
                "INSERT INTO sand_ai_knowledge_publication_operation
                 (owner_environment_id,operation_code,idempotency_key,request_fingerprint,publication_id,result_state)
                 VALUES (?,'revoke',?,?,?,'revoked')",
                [$ownerEnvironmentId, $idempotencyKey, $fingerprint, $publicationId],
            );
            $this->audit($actorRef, 'knowledge_base.publication.revoke', $publicationId, (int) $row[0]['knowledge_base_id'], (int) $row[0]['consumer_environment_id'], ['owner_environment_id' => $ownerEnvironmentId, 'request_id' => $idempotencyKey, 'request_fingerprint' => $fingerprint]);
            return ['publication_id' => $publicationId, 'state' => 'revoked', 'idempotent_replay' => false];
        });
    }

    /** Original sharing receipt; never republishes or reopens a revoked source. */
    public function readOperation(int $ownerEnvironmentId, string $operation, string $key, string $actorRef, array $target): array
    {
        if (!in_array($operation, ['publish', 'revoke'], true) || trim($key) === '' || trim($key) !== $key
            || strlen($key) > 128 || trim($actorRef) === '') self::invalid();
        $this->environment($ownerEnvironmentId);
        $rows = Db::connect('pgsql')->query(
            'SELECT op.publication_id,op.request_fingerprint,op.result_state,p.knowledge_base_id,
                    p.consumer_environment_id,p.consumer_organization_id,p.consumer_application_id,p.code
             FROM sand_ai_knowledge_publication_operation op
             JOIN sand_ai_knowledge_publication p ON p.id=op.publication_id AND p.owner_environment_id=op.owner_environment_id
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             WHERE op.owner_environment_id=? AND op.operation_code=? AND op.idempotency_key=? AND kb.delete_time IS NULL',
            [$ownerEnvironmentId, $operation, $key],
        );
        if (count($rows) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original publication receipt is unavailable; retain its request key');
        $row = $rows[0];
        $consumer = $this->environment((int) $row['consumer_environment_id']);
        if ((int) $row['consumer_organization_id'] !== $consumer['organization_id']
            || (int) $row['consumer_application_id'] !== $consumer['application_id']) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original publication scope is no longer available');
        }
        if ($operation === 'publish') {
            if ((int) ($target['knowledge_base_id'] ?? 0) !== (int) $row['knowledge_base_id']
                || (int) ($target['consumer_environment_id'] ?? 0) !== (int) $row['consumer_environment_id']
                || ($target['code'] ?? null) !== $row['code']) self::conflict();
            $fingerprint = hash('sha256', json_encode([(int) $row['knowledge_base_id'], (int) $row['consumer_environment_id'], (string) $row['code']], JSON_THROW_ON_ERROR));
        } else {
            if ((int) ($target['publication_id'] ?? 0) !== (int) $row['publication_id']) self::conflict();
            $fingerprint = hash('sha256', (string) $row['publication_id']);
        }
        $state = $operation === 'publish' ? 'published' : 'revoked';
        if (!is_string($row['request_fingerprint']) || !hash_equals($fingerprint, $row['request_fingerprint'])
            || $row['result_state'] !== $state) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original publication result proof is unavailable');
        }
        $proof = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_audit_log WHERE actor_type='admin' AND actor_ref=? AND action=?
             AND resource_type='knowledge_publication' AND resource_id=? AND context->>'owner_environment_id'=?
             AND context->>'request_id'=? AND context->>'request_fingerprint'=? LIMIT 1",
            [trim($actorRef), 'knowledge_base.publication.' . $operation, (int) $row['publication_id'],
                (string) $ownerEnvironmentId, $key, $fingerprint],
        );
        if ($proof === []) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original administrator and publication request proof are unavailable; retain its request key');
        return ['operation' => $operation, 'request_id' => $key, 'actor_ref' => trim($actorRef),
            'owner_environment_id' => $ownerEnvironmentId, 'publication_id' => (int) $row['publication_id'],
            'knowledge_base_id' => (int) $row['knowledge_base_id'], 'consumer_environment_id' => (int) $row['consumer_environment_id'],
            'code' => (string) $row['code'], 'state' => $state, 'idempotent_replay' => true];
    }

    /** @return array<string,mixed> */
    public function findForConsumer(int $consumerEnvironmentId, string $code): array
    {
        if ($consumerEnvironmentId < 1 || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) self::invalid();
        $rows = Db::connect('pgsql')->query(
            "SELECT p.id,p.knowledge_base_id,p.owner_environment_id,p.consumer_organization_id,
                    p.consumer_application_id,p.consumer_environment_id,p.code,kb.active_version_id,
                    kb.code AS owner_code,v.version_no,v.source_scope_sha256,v.data_class
             FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE p.consumer_environment_id=? AND p.code=? AND p.state='published'
               AND kb.delete_time IS NULL AND kb.status=1 AND kb.state='published' AND v.state='published'",
            [$consumerEnvironmentId, $code],
        );
        if (count($rows) !== 1 || !is_string($rows[0]['source_scope_sha256']) || $rows[0]['source_scope_sha256'] === '') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge base not found');
        }
        return $rows[0];
    }

    /** Source reads authorize the requested published version, including a still-live Skill pin.
     *  @return array<string,mixed>
     */
    public function findSourceForConsumer(int $consumerEnvironmentId, string $code, int $versionNo): array
    {
        if ($consumerEnvironmentId < 1 || $versionNo < 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) self::invalid();
        $rows = Db::connect('pgsql')->query(
            "SELECT p.id,p.knowledge_base_id,p.owner_environment_id,p.consumer_organization_id,
                    p.consumer_application_id,p.consumer_environment_id,p.code,v.id active_version_id,
                    kb.code AS owner_code,v.version_no,v.source_scope_sha256,v.data_class
             FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.knowledge_base_id=kb.id AND v.version_no=?
             WHERE p.consumer_environment_id=? AND p.code=? AND p.state='published'
               AND kb.delete_time IS NULL AND kb.status=1 AND kb.state='published' AND v.state='published'",
            [$versionNo, $consumerEnvironmentId, $code],
        );
        if (count($rows) !== 1 || !is_string($rows[0]['source_scope_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $rows[0]['source_scope_sha256']) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge source version not found');
        }
        return $rows[0];
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function listKnowledgeBases(int $ownerEnvironmentId, int $page, int $limit): array
    {
        self::page($ownerEnvironmentId, $page, $limit);
        $where = "kb.environment_id=? AND kb.delete_time IS NULL AND kb.status=1
                  AND kb.state='published' AND v.state='published'
                  AND v.source_scope_sha256 IS NOT NULL";
        $total = Db::connect('pgsql')->query(
            'SELECT count(*) total FROM sand_ai_knowledge_base kb
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE ' . $where,
            [$ownerEnvironmentId],
        );
        $rows = Db::connect('pgsql')->query(
            'SELECT kb.id,kb.code,kb.name,kb.environment_id,v.id version_id,v.version_no,v.data_class
             FROM sand_ai_knowledge_base kb
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE ' . $where . ' ORDER BY kb.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$ownerEnvironmentId],
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'environment_id' => (int) $row['environment_id'],
            'version_id' => (int) $row['version_id'],
            'version_no' => (int) $row['version_no'],
            'data_class' => $row['data_class'] === null ? null : (string) $row['data_class'],
        ], $rows);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function listPublications(int $ownerEnvironmentId, int $knowledgeBaseId, int $page, int $limit): array
    {
        self::page($ownerEnvironmentId, $page, $limit);
        if ($knowledgeBaseId < 1) self::invalid();
        $where = 'p.owner_environment_id=? AND p.knowledge_base_id=?';
        $params = [$ownerEnvironmentId, $knowledgeBaseId];
        $total = Db::connect('pgsql')->query(
            'SELECT count(*) total FROM sand_ai_knowledge_publication p WHERE ' . $where,
            $params,
        );
        $rows = Db::connect('pgsql')->query(
            'SELECT p.id,p.knowledge_base_id,p.owner_environment_id,p.consumer_organization_id,
                    p.consumer_application_id,p.consumer_environment_id,p.code,p.state,p.create_time,p.update_time
             FROM sand_ai_knowledge_publication p WHERE ' . $where .
            ' ORDER BY p.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            $params,
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'knowledge_base_id' => (int) $row['knowledge_base_id'],
            'owner_environment_id' => (int) $row['owner_environment_id'],
            'consumer_organization_id' => (int) $row['consumer_organization_id'],
            'consumer_application_id' => (int) $row['consumer_application_id'],
            'consumer_environment_id' => (int) $row['consumer_environment_id'],
            'code' => (string) $row['code'],
            'state' => (string) $row['state'],
            'create_time' => (string) $row['create_time'],
            'update_time' => (string) $row['update_time'],
        ], $rows);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function listForConsumer(int $consumerEnvironmentId, int $page, int $limit): array
    {
        self::page($consumerEnvironmentId, $page, $limit);
        $where = "p.consumer_environment_id=? AND p.state='published'
                  AND kb.delete_time IS NULL AND kb.status=1 AND kb.state='published'
                  AND v.state='published' AND v.source_scope_sha256 IS NOT NULL
                  AND v.source_scope_sha256<>''";
        $from = ' FROM sand_ai_knowledge_publication p
                  JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
                  JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
                  WHERE ' . $where;
        $total = Db::connect('pgsql')->query('SELECT count(*) total' . $from, [$consumerEnvironmentId]);
        $rows = Db::connect('pgsql')->query(
            'SELECT p.id,p.code,p.knowledge_base_id,p.owner_environment_id,p.consumer_environment_id,
                    kb.code AS owner_code,kb.name AS owner_name,v.id AS version_id,v.version_no,v.data_class'
            . $from . ' ORDER BY p.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$consumerEnvironmentId],
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'knowledge_base_id' => (int) $row['knowledge_base_id'],
            'owner_environment_id' => (int) $row['owner_environment_id'],
            'consumer_environment_id' => (int) $row['consumer_environment_id'],
            'owner_code' => (string) $row['owner_code'],
            'owner_name' => (string) $row['owner_name'],
            'version_id' => (int) $row['version_id'],
            'version_no' => (int) $row['version_no'],
            'data_class' => $row['data_class'] === null ? null : (string) $row['data_class'],
        ], $rows);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int} */
    private function environment(int $environmentId): array
    {
        try {
            return ($this->environmentFacts ?? new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function lock(string $key): void
    {
        Db::connect('pgsql')->execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sand-ai-knowledge-publication:' . $key]);
    }

    private function audit(string $actorRef, string $action, int $publicationId, int $knowledgeBaseId, int $consumerEnvironmentId, array $receipt = []): void
    {
        AuditLog::create([
            'actor_type' => 'admin',
            'actor_ref' => trim($actorRef),
            'action' => $action,
            'resource_type' => 'knowledge_publication',
            'resource_id' => $publicationId,
            'summary' => 'SandAI knowledge publication changed',
            'context' => ['knowledge_base_id' => $knowledgeBaseId, 'consumer_environment_id' => $consumerEnvironmentId] + $receipt,
        ]);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge publication request is invalid');
    }

    private static function page(int $ownerEnvironmentId, int $page, int $limit): void
    {
        if ($ownerEnvironmentId < 1 || $page < 1 || $limit < 1 || $limit > 100
            || ($page - 1) > intdiv(PHP_INT_MAX, $limit)) self::invalid();
    }

    private static function conflict(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge publication conflicts with an existing alias or request');
    }
}
