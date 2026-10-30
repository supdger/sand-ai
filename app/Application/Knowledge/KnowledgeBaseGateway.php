<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\KnowledgeBase;
use app\Infrastructure\Persistence\Model\KnowledgeBaseSource;
use app\Infrastructure\Persistence\Model\KnowledgeBaseVersion;
use app\Infrastructure\Persistence\Model\SourceBlock;
use think\facade\Db;

final class KnowledgeBaseGateway
{
    public function __construct(private readonly ?EnvironmentReferenceVerifier $environmentReferences = null) {}

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function index(int $environmentId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge list pagination is invalid');
        }
        $database = Db::connect('pgsql');
        $total = $database->query(
            'SELECT count(*) AS total FROM sand_ai_knowledge_base WHERE environment_id=? AND delete_time IS NULL',
            [$environmentId],
        );
        $rows = $database->query(
            "SELECT kb.id,kb.code,kb.name,kb.description,kb.state,kb.active_version_id,
                    active.version_no AS active_version_no,draft.id AS draft_version_id,
                    draft.version_no AS draft_version_no,draft.source_count AS draft_source_count,
                    draft.snapshot_sha256 AS draft_snapshot_sha256,
                    EXISTS (SELECT 1 FROM sand_ai_knowledge_operation op
                            WHERE op.knowledge_base_id=kb.id AND op.operation_code='purge_sources')
                        AS sources_purged
             FROM sand_ai_knowledge_base kb
             LEFT JOIN sand_ai_knowledge_base_version active ON active.id=kb.active_version_id
             LEFT JOIN sand_ai_knowledge_base_version draft ON draft.knowledge_base_id=kb.id AND draft.state=? 
             WHERE kb.environment_id=? AND kb.delete_time IS NULL
             ORDER BY kb.id DESC LIMIT ? OFFSET ?",
            ['draft', $environmentId, $limit, ($page - 1) * $limit],
        );
        $items = [];
        try {
            $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        foreach ($rows as $row) {
            $versionId = (int) ($row['draft_version_id'] ?? $row['active_version_id'] ?? 0);
            $sourceState = 'unavailable';
            if (in_array($row['sources_purged'], [true, 1, '1', 't', 'true'], true)) {
                $sourceState = 'purged';
            } elseif ($versionId > 0) {
                try {
                    $this->assertSnapshotCurrent($versionId);
                    $scope = (new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
                    $version = KnowledgeBaseVersion::where('id', $versionId)->find();
                    if ($version === null || ((string) $version->state === 'published'
                        && (!hash_equals((string) $version->source_scope_sha256, $scope['source_scope_sha256'])
                            || $version->data_class !== $scope['data_class']))) {
                        throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source ownership changed');
                    }
                    $sourceState = 'ready';
                } catch (ApiProblem) {
                    $sourceState = 'source_changed';
                }
            }
            $items[] = [
                'id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
                'description' => $row['description'], 'state' => (string) $row['state'],
                'active_version_id' => $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
                'active_version_no' => $row['active_version_no'] === null ? null : (int) $row['active_version_no'],
                'draft_version_id' => $row['draft_version_id'] === null ? null : (int) $row['draft_version_id'],
                'draft_version_no' => $row['draft_version_no'] === null ? null : (int) $row['draft_version_no'],
                'draft_source_count' => $row['draft_source_count'] === null ? null : (int) $row['draft_source_count'],
                'draft_snapshot_sha256' => $row['draft_snapshot_sha256'] === null ? null : (string) $row['draft_snapshot_sha256'],
                'source_state' => $sourceState,
                'sources_purged' => $sourceState === 'purged',
            ];
        }
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array{files:list<array{id:int,name:string,sha256:string,source_block_count:int,data_class:?string}>,next_before:?int} */
    public function eligibleFiles(int $environmentId, ?int $beforeId = null): array
    {
        $this->requireEnvironment($environmentId);
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge file cursor is invalid');
        }
        try {
            $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        $cursorSql = $beforeId === null ? '' : ' AND f.id < ?';
        $rows = Db::connect('pgsql')->query(
            "SELECT f.id,f.original_name,f.sha256,f.status,f.delete_time,f.state,f.environment_id,
                    f.owner_scope,f.owner_attribution,p.id AS parse_id,p.source_block_count,
                    (SELECT count(*) FROM sand_ai_source_block b
                     WHERE b.file_id=f.id AND b.parse_id=p.id AND b.status=1 AND b.delete_time IS NULL
                       AND encode(sha256(convert_to(b.content,'UTF8')),'hex')=b.content_sha256) AS block_count
             FROM sand_ai_file f
             JOIN LATERAL (
                 SELECT id,source_block_count FROM sand_ai_file_parse p
                 WHERE p.file_id=f.id AND p.state='succeeded' AND p.status=1 AND p.delete_time IS NULL
                 ORDER BY p.id DESC LIMIT 1
             ) p ON true
             WHERE f.environment_id=? AND f.state='parsed' AND f.status=1 AND f.delete_time IS NULL
               {$cursorSql}
             ORDER BY f.id DESC LIMIT 101",
            $beforeId === null ? [$environmentId] : [$environmentId, $beforeId],
        );
        $items = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            if ((int) $row['block_count'] < 1 || (int) $row['block_count'] !== (int) $row['source_block_count']) continue;
            try {
                $scope = KnowledgeVersionSourceScope::fromFiles([$row], $owner);
            } catch (ApiProblem) {
                continue;
            }
            $items[] = [
                'id' => (int) $row['id'], 'name' => (string) $row['original_name'],
                'sha256' => (string) $row['sha256'],
                'source_block_count' => (int) $row['block_count'],
                'data_class' => $scope['data_class'],
            ];
        }
        return [
            'files' => $items,
            'next_before' => count($rows) > 100 ? (int) $rows[99]['id'] : null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createDraft(int $environmentId, array $input, string $idempotencyKey, string $actorRef): array
    {
        $this->requireEnvironment($environmentId);
        $actorRef = trim($actorRef);
        $idempotencyKey = trim($idempotencyKey);
        if ($actorRef === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Administrator and Idempotency-Key are required');
        }
        $input = KnowledgeBasePolicy::normalizeDraft($input);
        $fingerprintInput = $input;
        if (!$fingerprintInput['replace_draft']) unset($fingerprintInput['replace_draft']);
        $fingerprint = KnowledgeBasePolicy::fingerprint($fingerprintInput);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $input, $idempotencyKey, $actorRef, $fingerprint): array {
            Db::connect('pgsql')->execute("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", [$environmentId . ':knowledge-operation:create_draft:' . $idempotencyKey]);
            Db::connect('pgsql')->execute("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", [$environmentId . ':knowledge:' . $input['code']]);
            $existing = Db::connect('pgsql')->query('SELECT * FROM sand_ai_knowledge_operation WHERE environment_id = ? AND operation_code = ? AND idempotency_key = ? FOR UPDATE', [$environmentId, 'create_draft', $idempotencyKey]);
            if ($existing !== []) {
                if ((string) $existing[0]['request_fingerprint'] !== $fingerprint) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request');
                if ((string) KnowledgeBaseVersion::where('id', (int) $existing[0]['version_id'])->value('state') === 'abandoned') {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original draft was replaced');
                }
                return $this->summary((int) $existing[0]['knowledge_base_id'], (int) $existing[0]['version_id'], true);
            }
            $base = KnowledgeBase::where('environment_id', $environmentId)->where('code', $input['code'])->lock(true)->find();
            if ($base === null) $base = KnowledgeBase::create(['environment_id' => $environmentId, 'code' => $input['code'], 'name' => $input['name'], 'description' => $input['description'], 'state' => 'draft', 'status' => 1]);
            if ((string) $base->state === 'disabled' || (int) $base->status !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Disabled knowledge base cannot create a draft');
            $draft = KnowledgeBaseVersion::where('knowledge_base_id', (int) $base->id)->where('state', 'draft')->lock(true)->find();
            if ($draft !== null) {
                if (!$input['replace_draft']) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge base already has a draft version');
                $draft->save(['state' => 'abandoned']);
            }
            $nextVersion = (int) KnowledgeBaseVersion::where('knowledge_base_id', (int) $base->id)->max('version_no') + 1;
            $version = KnowledgeBaseVersion::create(['knowledge_base_id' => (int) $base->id, 'version_no' => $nextVersion, 'state' => 'draft', 'source_count' => 0, 'snapshot_sha256' => str_repeat('0', 64)]);
            $sources = $this->freezeSources($environmentId, (int) $version->id, $input['files']);
            $snapshot = KnowledgeBasePolicy::fingerprint(['code' => $input['code'], 'version' => $nextVersion, 'sources' => $sources]);
            Db::connect('pgsql')->execute('UPDATE sand_ai_knowledge_base_version SET source_count = ?, snapshot_sha256 = ? WHERE id = ?', [count($sources), $snapshot, (int) $version->id]);
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_knowledge_operation (environment_id, operation_code, idempotency_key, request_fingerprint, knowledge_base_id, version_id) VALUES (?, ?, ?, ?, ?, ?)', [$environmentId, 'create_draft', $idempotencyKey, $fingerprint, (int) $base->id, (int) $version->id]);
            $this->audit($actorRef, 'knowledge_base.draft', (int) $base->id, ['version' => $nextVersion, 'source_count' => count($sources)]);
            return $this->summary((int) $base->id, (int) $version->id, false);
        });
    }

    /** Original association proof and current metadata are deliberately separate.
     * @return array<string,mixed>
     */
    public function draftByRequestKey(int $environmentId, string $key, string $fingerprint, string $actorRef): array
    {
        $this->requireEnvironment($environmentId);
        $key = trim($key);
        if ($actorRef === '' || $key === '' || strlen($key) > 128
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge draft lookup is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $key, $fingerprint, $actorRef): array {
            $sql = "SELECT op.knowledge_base_id,op.version_id,op.request_fingerprint,v.version_no,v.source_count
                    FROM sand_ai_knowledge_operation op
                    INNER JOIN sand_ai_knowledge_base kb ON kb.id=op.knowledge_base_id AND kb.environment_id=op.environment_id
                    INNER JOIN sand_ai_knowledge_base_version v ON v.id=op.version_id AND v.knowledge_base_id=kb.id
                    WHERE op.environment_id=? AND op.operation_code='create_draft' AND op.idempotency_key=?
                      AND kb.delete_time IS NULL AND v.delete_time IS NULL AND v.status=1";
            $parameters = [$environmentId, $key];
            $rows = Db::connect('pgsql')->query($sql, $parameters);
            $row = count($rows) === 1 ? $rows[0] : null;
            if ($row === null) {
                throw new ApiProblem('SAND_AI_FACTS_UNVERIFIED', 'Original knowledge draft proof is unavailable', 404);
            }
            if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original draft input does not match');
            }
            $proof = $this->draftActorProof((int) $row['knowledge_base_id'], (int) $row['version_no'], (int) $row['source_count']);
            if (count($proof) !== 1 || $proof[0]['actor_ref'] !== $actorRef) {
                throw new ApiProblem('SAND_AI_FACTS_UNVERIFIED', 'Original knowledge draft actor proof is unavailable', 404);
            }
            $this->assertSnapshotCurrent((int) $row['version_id']);
            try {
                $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
            } catch (IdentityContextException $exception) {
                throw IdentityContextProblem::from($exception);
            }
            $scope = (new KnowledgeVersionSourceScope())->resolve((int) $row['version_id'], $owner);
            $version = KnowledgeBaseVersion::where('id', (int) $row['version_id'])->find();
            if ($version === null || ((string) $version->state === 'published'
                && (!hash_equals((string) $version->source_scope_sha256, $scope['source_scope_sha256'])
                    || $version->data_class !== $scope['data_class']))) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source ownership changed');
            }
            $current = $this->summary((int) $row['knowledge_base_id'], (int) $row['version_id'], true);
            $this->requireEnvironment($environmentId);
            $this->assertSnapshotCurrent((int) $row['version_id']);
            try {
                $currentOwner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
            } catch (IdentityContextException $exception) {
                throw IdentityContextProblem::from($exception);
            }
            if ($currentOwner !== $owner || Db::connect('pgsql')->query($sql, $parameters) !== $rows
                || $this->draftActorProof((int) $row['knowledge_base_id'], (int) $row['version_no'], (int) $row['source_count']) !== $proof
                || (new KnowledgeVersionSourceScope())->resolve((int) $row['version_id'], $owner) !== $scope) {
                throw new ApiProblem('SAND_AI_FACTS_UNVERIFIED', 'Original knowledge draft proof changed', 404);
            }
            return ['operation' => 'create_draft', 'environment_id' => $environmentId, 'request_key' => $key,
                'request_fingerprint' => $fingerprint, 'actor_ref' => $actorRef,
                'knowledge_base_id' => (int) $row['knowledge_base_id'], 'version_id' => (int) $row['version_id'],
                'idempotent_replay' => true, 'current' => $current];
        });
    }

    /** @return list<array<string,mixed>> */
    private function draftActorProof(int $baseId, int $version, int $sourceCount): array
    {
        return Db::connect('pgsql')->query(
            "SELECT id,actor_ref FROM sand_ai_audit_log
             WHERE actor_type='admin' AND action='knowledge_base.draft' AND resource_type='knowledge_base'
               AND resource_id=? AND context->>'version'=? AND context->>'source_count'=?
               AND delete_time IS NULL ORDER BY id",
            [$baseId, (string) $version, (string) $sourceCount],
        );
    }

    /** @return array<string,mixed> */
    /** Recover only a new fenced operation owned by the current formal administrator. */
    public function publishByRequestKey(int $environmentId, int $knowledgeBaseId, string $key, string $actorRef, ?string $expectedSnapshotSha256 = null): array
    {
        $this->requireEnvironment($environmentId);
        if ($knowledgeBaseId < 1 || $actorRef === '' || trim($key) === '' || strlen($key) > 128) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge publish lookup is invalid');
        }
        if ($expectedSnapshotSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Expected snapshot SHA256 is invalid');
        }
        $parameters = [$environmentId, 'publish', trim($key), $knowledgeBaseId];
        $sql = "SELECT op.request_fingerprint,op.version_id,v.snapshot_sha256,kb.state AS base_state,v.state AS version_state
                FROM sand_ai_knowledge_operation op
                INNER JOIN sand_ai_knowledge_base kb ON kb.id=op.knowledge_base_id AND kb.environment_id=op.environment_id
                LEFT JOIN sand_ai_knowledge_base_version v ON v.id=op.version_id AND v.knowledge_base_id=kb.id
                WHERE op.environment_id=? AND op.operation_code=? AND op.idempotency_key=? AND op.knowledge_base_id=?
                  AND kb.delete_time IS NULL";
        $rows = Db::connect('pgsql')->query($sql, $parameters);
        $row = $rows[0] ?? null;
        if ($row !== null && $row['version_id'] === null && $expectedSnapshotSha256 !== null
            && hash_equals((string) $row['request_fingerprint'], self::publishRejectedFingerprint($knowledgeBaseId, $expectedSnapshotSha256, $actorRef))) {
            $this->requireEnvironment($environmentId);
            $current = Db::connect('pgsql')->query($sql, $parameters);
            if (count($current) !== 1 || $current[0] !== $row) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge publish operation not found');
            }
            return self::publishRejectedResult($environmentId, $knowledgeBaseId, trim($key), $expectedSnapshotSha256, true);
        }
        if ($row === null || !is_string($row['snapshot_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $row['snapshot_sha256']) !== 1
            || ($expectedSnapshotSha256 !== null && !hash_equals($expectedSnapshotSha256, $row['snapshot_sha256']))
            || !hash_equals((string) $row['request_fingerprint'], self::publishFingerprint($knowledgeBaseId, $row['snapshot_sha256'], $actorRef))) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge publish operation not found');
        }
        $result = $this->summary($knowledgeBaseId, (int) $row['version_id'], true);
        // Recheck the exact operation and resource association before releasing metadata.
        $this->requireEnvironment($environmentId);
        $current = Db::connect('pgsql')->query($sql, $parameters);
        if (count($current) !== 1 || $current[0] !== $row
            || !hash_equals($row['snapshot_sha256'], (string) $result['version']['snapshot_sha256'])
            || $result['knowledge_base']['state'] !== $row['base_state']
            || $result['version']['state'] !== $row['version_state']) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge publish operation not found');
        }
        return $result;
    }

    private static function publishRejectedFingerprint(int $baseId, string $snapshotSha256, string $actorRef): string
    {
        return hash('sha256', json_encode([
            'state' => 'rejected', 'reason' => 'draft_snapshot_changed', 'baseId' => $baseId,
            'expected_snapshot_sha256' => $snapshotSha256, 'actorRef' => $actorRef,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Receipt is backed by a committed, caller-bound rejection operation, never by an HTTP status. */
    private static function publishRejectedResult(int $environmentId, int $baseId, string $key, string $snapshotSha256, bool $replay): array
    {
        return [
            'state' => 'rejected', 'submitted' => false, 'reason' => 'draft_snapshot_changed',
            'environment_id' => $environmentId, 'knowledge_base_id' => $baseId,
            'request_key' => $key, 'expected_snapshot_sha256' => $snapshotSha256, 'idempotent_replay' => $replay,
        ];
    }

    private static function publishFingerprint(int $baseId, ?string $snapshotSha256, string $actorRef): string
    {
        return $snapshotSha256 === null ? hash('sha256', (string) $baseId)
            : hash('sha256', json_encode([
                'baseId' => $baseId, 'expected_snapshot_sha256' => $snapshotSha256, 'actorRef' => $actorRef,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function publish(int $environmentId, int $knowledgeBaseId, string $idempotencyKey, string $actorRef, ?string $expectedSnapshotSha256 = null): array
    {
        $this->requireEnvironment($environmentId);
        if ($knowledgeBaseId <= 0 || trim($idempotencyKey) === '' || trim($actorRef) === '') throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base, administrator and Idempotency-Key are required');
        if ($expectedSnapshotSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Expected snapshot SHA256 is invalid');
        }
        $fingerprint = self::publishFingerprint($knowledgeBaseId, $expectedSnapshotSha256, $actorRef);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $knowledgeBaseId, $idempotencyKey, $actorRef, $expectedSnapshotSha256, $fingerprint): array {
            Db::connect('pgsql')->execute("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", [$environmentId . ':knowledge-operation:publish:' . $idempotencyKey]);
            $previous = Db::connect('pgsql')->query('SELECT * FROM sand_ai_knowledge_operation WHERE environment_id = ? AND operation_code = ? AND idempotency_key = ? FOR UPDATE', [$environmentId, 'publish', $idempotencyKey]);
            if ($previous !== []) {
                if ((int) $previous[0]['knowledge_base_id'] !== $knowledgeBaseId) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request');
                if ($previous[0]['version_id'] === null) {
                    if ($expectedSnapshotSha256 !== null && hash_equals((string) $previous[0]['request_fingerprint'],
                        self::publishRejectedFingerprint($knowledgeBaseId, $expectedSnapshotSha256, $actorRef))) {
                        return self::publishRejectedResult($environmentId, $knowledgeBaseId, $idempotencyKey, $expectedSnapshotSha256, true);
                    }
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original operation does not match this publish request');
                }
                $original = $this->summary((int) $previous[0]['knowledge_base_id'], (int) $previous[0]['version_id'], true);
                if (!hash_equals((string) $previous[0]['request_fingerprint'], $fingerprint)
                    || ($expectedSnapshotSha256 !== null && !hash_equals($expectedSnapshotSha256, (string) $original['version']['snapshot_sha256']))) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The original publish request has different snapshot or administrator');
                }
                return $original;
            }
            $base = KnowledgeBase::where('id', $knowledgeBaseId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($base === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge base not found');
            if ((string) $base->state === 'disabled' || (int) $base->status !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Disabled knowledge base cannot be published');
            $version = KnowledgeBaseVersion::where('knowledge_base_id', $knowledgeBaseId)->where('state', 'draft')->lock(true)->find();
            if ($version === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge base has no publishable version');
            if ($expectedSnapshotSha256 !== null && !hash_equals($expectedSnapshotSha256, (string) $version->snapshot_sha256)) {
                // This branch is reached only after proving this key has no prior operation.
                // Commit the rejection while the draft remains locked; never overwrite an unknown/successful operation.
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_knowledge_operation (environment_id, operation_code, idempotency_key, request_fingerprint, knowledge_base_id, version_id) VALUES (?, ?, ?, ?, ?, NULL)',
                    [$environmentId, 'publish', $idempotencyKey, self::publishRejectedFingerprint($knowledgeBaseId, $expectedSnapshotSha256, $actorRef), $knowledgeBaseId],
                );
                return self::publishRejectedResult($environmentId, $knowledgeBaseId, $idempotencyKey, $expectedSnapshotSha256, false);
            }
            $rows = Db::connect('pgsql')->query('SELECT DISTINCT file_id FROM sand_ai_knowledge_base_source WHERE knowledge_base_version_id=? ORDER BY file_id', [(int) $version->id]);
            $fileIds = array_map(static fn (array $row): int => (int) $row['file_id'], $rows);
            (new KnowledgeSourceReferenceGuard())->lockFiles($fileIds);
            $this->assertSnapshotCurrent((int) $version->id);
            try {
                $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
            } catch (IdentityContextException $exception) {
                throw IdentityContextProblem::from($exception);
            }
            $scope = (new KnowledgeVersionSourceScope())->resolve((int) $version->id, $owner);
            $version->save([
                'state' => 'published',
                'published_time' => date('Y-m-d H:i:s'),
                'source_scope_sha256' => $scope['source_scope_sha256'],
                'data_class' => $scope['data_class'],
            ]);
            $base->save(['state' => 'published', 'active_version_id' => (int) $version->id]);
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_knowledge_operation (environment_id, operation_code, idempotency_key, request_fingerprint, knowledge_base_id, version_id) VALUES (?, ?, ?, ?, ?, ?)', [$environmentId, 'publish', $idempotencyKey, $fingerprint, $knowledgeBaseId, (int) $version->id]);
            $this->audit(trim($actorRef), 'knowledge_base.publish', $knowledgeBaseId, ['version' => (int) $version->version_no]);
            return $this->summary($knowledgeBaseId, (int) $version->id, false);
        });
    }

    /** @return array<string,mixed> */
    public function disable(int $environmentId, int $knowledgeBaseId, string $idempotencyKey, string $actorRef): array
    {
        $this->requireEnvironment($environmentId);
        if ($knowledgeBaseId <= 0 || trim($idempotencyKey) === '' || trim($actorRef) === '') throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base, administrator and Idempotency-Key are required');
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $knowledgeBaseId, $idempotencyKey, $actorRef): array {
            Db::connect('pgsql')->execute("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", [$environmentId . ':knowledge-operation:disable:' . $idempotencyKey]);
            $previous = Db::connect('pgsql')->query('SELECT * FROM sand_ai_knowledge_operation WHERE environment_id=? AND operation_code=? AND idempotency_key=? FOR UPDATE', [$environmentId, 'disable', $idempotencyKey]);
            if ($previous !== []) { if ((int) $previous[0]['knowledge_base_id'] !== $knowledgeBaseId) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request'); return $this->summary($knowledgeBaseId, (int) $previous[0]['version_id'], true); }
            $base = KnowledgeBase::where('id', $knowledgeBaseId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($base === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge base not found');
            $versionId = (int) $base->active_version_id;
            if ($versionId <= 0) {
                // An unpublished draft still owns source references. Retire it
                // through the same guarded lifecycle without publishing it.
                $draft = KnowledgeBaseVersion::where('knowledge_base_id', $knowledgeBaseId)
                    ->where('state', 'draft')->lock(true)->find();
                if ($draft === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Knowledge base has no version to disable');
                }
                $versionId = (int) $draft->id;
            }
            $pending = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_knowledge_conversation_turn t
                 JOIN sand_ai_knowledge_conversation c ON c.id=t.conversation_id
                 JOIN sand_ai_knowledge_publication p ON p.id=c.publication_id
                 WHERE p.knowledge_base_id=? AND t.state='pending' LIMIT 1",
                [$knowledgeBaseId],
            );
            if ($pending !== []) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or reconcile pending knowledge answers before disabling');
            (new KnowledgeEmbeddingIndexGateway())->assertNoUnsettledForBase($knowledgeBaseId);
            $base->save(['state' => 'disabled', 'status' => 2]);
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_knowledge_operation (environment_id, operation_code, idempotency_key, request_fingerprint, knowledge_base_id, version_id) VALUES (?, ?, ?, ?, ?, ?)', [$environmentId, 'disable', $idempotencyKey, hash('sha256', (string) $knowledgeBaseId), $knowledgeBaseId, $versionId]);
            $this->audit(trim($actorRef), 'knowledge_base.disable', $knowledgeBaseId, ['version' => $versionId, 'environment_id' => $environmentId, 'request_id' => $idempotencyKey, 'request_fingerprint' => hash('sha256', (string) $knowledgeBaseId)]);
            return $this->summary($knowledgeBaseId, $versionId, false);
        });
    }

    /** Read a committed retirement/cleanup receipt without repeating deletion or accounting recovery. */
    public function lifecycleByRequestKey(int $environmentId, int $knowledgeBaseId, string $operation, string $key, string $actorRef): array
    {
        $this->requireEnvironment($environmentId);
        $actions = ['disable' => 'knowledge_base.disable', 'purge_vectors' => 'knowledge.embedding.purge',
            'purge_sources' => 'knowledge.sources.purge'];
        if (!isset($actions[$operation]) || $knowledgeBaseId < 1 || trim($key) === '' || strlen($key) > 128
            || trim($key) !== $key || trim($actorRef) === '') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge lifecycle receipt query is invalid');
        }
        $db = Db::connect('pgsql');
        $rows = $db->query(
            'SELECT op.knowledge_base_id,op.version_id,op.request_fingerprint FROM sand_ai_knowledge_operation op
             JOIN sand_ai_knowledge_base kb ON kb.id=op.knowledge_base_id AND kb.environment_id=op.environment_id
             WHERE op.environment_id=? AND op.operation_code=? AND op.idempotency_key=?
               AND op.knowledge_base_id=? AND kb.delete_time IS NULL',
            [$environmentId, $operation, $key, $knowledgeBaseId],
        );
        if (count($rows) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original knowledge lifecycle receipt is unavailable; retain its request key');
        $fingerprint = hash('sha256', (string) $knowledgeBaseId);
        if (!is_string($rows[0]['request_fingerprint']) || !hash_equals($fingerprint, $rows[0]['request_fingerprint'])) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original knowledge lifecycle target proof is unavailable');
        }
        // The operation commits with the original deletion; current list state cannot prove who sent its key.
        $proof = $db->query(
            "SELECT id FROM sand_ai_audit_log WHERE actor_type='admin' AND actor_ref=? AND action=?
             AND resource_type='knowledge_base' AND resource_id=? AND context->>'environment_id'=?
             AND context->>'request_id'=? AND context->>'request_fingerprint'=? LIMIT 1",
            [trim($actorRef), $actions[$operation], $knowledgeBaseId, (string) $environmentId, $key, $fingerprint],
        );
        if ($proof === []) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original administrator and knowledge request proof are unavailable; retain its request key');
        return ['environment_id' => $environmentId, 'knowledge_base_id' => $knowledgeBaseId,
            'operation' => $operation, 'request_id' => $key, 'actor_ref' => trim($actorRef),
            'state' => $operation === 'disable' ? 'disabled' : 'purged', 'idempotent_replay' => true];
    }

    /** @param list<array{id:int,sha256:string}> $files @return list<array<string,mixed>> */
    private function freezeSources(int $environmentId, int $versionId, array $files): array
    {
        $snapshot = [];
        $expectedAttribution = null;
        foreach ($files as $fileInput) {
            (new KnowledgeSourceReferenceGuard())->lockFile($fileInput['id']);
            $file = AiFile::where('id', $fileInput['id'])->where('environment_id', $environmentId)->lock(true)->find();
            if ($file === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
            if ((string) $file->state !== 'parsed' || (int) $file->status !== 1 || (string) $file->sha256 !== $fileInput['sha256']) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'File is not an eligible parsed source');
            $attribution = $this->sourceAttribution($file, $environmentId);
            if ($expectedAttribution === null) $expectedAttribution = $attribution;
            else KnowledgeSourceAttribution::assertCompatible($expectedAttribution, $attribution);
            $parse = AiFileParse::where('file_id', (int) $file->id)->where('state', 'succeeded')
                ->where('status', 1)->order('id', 'desc')->lock(true)->find();
            if ($parse === null) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'File has no successful parse');
            $blocks = SourceBlock::where('file_id', (int) $file->id)->where('parse_id', (int) $parse->id)
                ->where('status', 1)->order('sequence_no')->lock(true)->select();
            if ($blocks->isEmpty() || count($blocks) !== (int) $parse->source_block_count) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'File parse source blocks are incomplete');
            }
            // Import the same intact sources offered by eligibleFiles; reject
            // stale content before a draft version and its references commit.
            foreach ($blocks as $block) {
                if (!is_string($block->content) || !is_string($block->content_sha256)
                    || !hash_equals($block->content_sha256, hash('sha256', $block->content))) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'File parse source content changed');
                }
                KnowledgeBaseSource::create(['knowledge_base_version_id' => $versionId, 'file_id' => (int) $file->id, 'parse_id' => (int) $parse->id, 'source_block_id' => (int) $block->id, 'sequence_no' => (int) $block->sequence_no, 'locator_type' => (string) $block->locator_type, 'locator' => $block->locator, 'content_sha256' => (string) $block->content_sha256]);
                $locator = is_array($block->locator) ? $block->locator : [];
                $snapshot[] = ['file_id' => (int) $file->id, 'parse_id' => (int) $parse->id, 'source_block_id' => (int) $block->id, 'sequence_no' => (int) $block->sequence_no, 'locator_type' => (string) $block->locator_type, 'locator' => $locator, 'content_sha256' => (string) $block->content_sha256];
            }
        }
        return $snapshot;
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,data_class:?string} */
    private function sourceAttribution(AiFile $file, int $environmentId): array
    {
        return KnowledgeSourceAttribution::fromFile([
            'id' => $file->id,
            'status' => $file->status,
            'delete_time' => $file->delete_time,
            'state' => $file->state,
            'environment_id' => $file->environment_id,
            'owner_scope' => $file->owner_scope,
            'owner_attribution' => $file->owner_attribution,
        ], $environmentId);
    }

    private function assertSnapshotCurrent(int $versionId): void
    {
        Db::connect('pgsql')->query("SELECT s.id FROM sand_ai_knowledge_base_source s INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id INNER JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id INNER JOIN sand_ai_file f ON f.id=s.file_id INNER JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id INNER JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id WHERE s.knowledge_base_version_id=? AND kb.delete_time IS NULL AND f.delete_time IS NULL AND p.delete_time IS NULL AND b.delete_time IS NULL ORDER BY s.file_id,s.parse_id,s.sequence_no,s.source_block_id FOR UPDATE OF s,f,p,b", [$versionId]);
        $count = Db::connect('pgsql')->query("SELECT count(*) AS total FROM sand_ai_knowledge_base_source s INNER JOIN sand_ai_knowledge_base_version v ON v.id=s.knowledge_base_version_id INNER JOIN sand_ai_knowledge_base kb ON kb.id=v.knowledge_base_id INNER JOIN sand_ai_file f ON f.id=s.file_id INNER JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id INNER JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id WHERE s.knowledge_base_version_id=? AND kb.delete_time IS NULL AND f.delete_time IS NULL AND p.delete_time IS NULL AND b.delete_time IS NULL AND f.status=1 AND f.state='parsed' AND p.status=1 AND p.state='succeeded' AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest WHERE latest.file_id=p.file_id AND latest.delete_time IS NULL AND latest.status=1 AND latest.state='succeeded' AND latest.id>p.id) AND b.status=1 AND b.sequence_no=s.sequence_no AND b.locator_type=s.locator_type AND b.locator=s.locator AND b.content_sha256=s.content_sha256 AND encode(sha256(convert_to(b.content, 'UTF8')), 'hex')=b.content_sha256", [$versionId]);
        $expected = KnowledgeBaseSource::where('knowledge_base_version_id', $versionId)->count();
        if ($expected === 0 || (int) ($count[0]['total'] ?? 0) !== $expected) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source snapshot is no longer available');
        $version = KnowledgeBaseVersion::where('id', $versionId)->find();
        $sources = KnowledgeBaseSource::where('knowledge_base_version_id', $versionId)->order('file_id')->order('parse_id')->order('sequence_no')->order('source_block_id')->select()->map(static fn (KnowledgeBaseSource $source): array => ['file_id' => (int) $source->file_id, 'parse_id' => (int) $source->parse_id, 'source_block_id' => (int) $source->source_block_id, 'sequence_no' => (int) $source->sequence_no, 'locator_type' => (string) $source->locator_type, 'locator' => is_array($source->locator) ? $source->locator : [], 'content_sha256' => (string) $source->content_sha256])->all();
        if ($version === null || KnowledgeBasePolicy::fingerprint(['code' => (string) KnowledgeBase::where('id', (int) $version->knowledge_base_id)->value('code'), 'version' => (int) $version->version_no, 'sources' => $sources]) !== (string) $version->snapshot_sha256) throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source snapshot is no longer available');
    }

    /** @return array<string,mixed> */
    private function summary(int $knowledgeBaseId, int $versionId, bool $replay): array
    {
        $base = KnowledgeBase::where('id', $knowledgeBaseId)->find(); $version = KnowledgeBaseVersion::where('id', $versionId)->find();
        return ['idempotent_replay' => $replay, 'knowledge_base' => ['id' => $knowledgeBaseId, 'code' => (string) $base->code, 'name' => (string) $base->getAttr('name'), 'state' => (string) $base->state], 'version' => ['id' => $versionId, 'version' => (int) $version->version_no, 'state' => (string) $version->state, 'snapshot_sha256' => (string) $version->snapshot_sha256, 'source_count' => (int) $version->source_count]];
    }

    /** @param array<string,mixed> $context */
    private function audit(string $actorRef, string $action, int $resourceId, array $context): void { AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => $action, 'resource_type' => 'knowledge_base', 'resource_id' => $resourceId, 'summary' => 'SandAI knowledge base changed', 'context' => $context]); }
    private function requireEnvironment(int $environmentId): void { if ($environmentId <= 0) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Environment is required'); try { ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); } catch (IdentityContextException $exception) { throw IdentityContextProblem::from($exception); } }
}
