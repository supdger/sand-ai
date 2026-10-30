<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Tool;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolEnvironmentBinding;
use app\Infrastructure\Persistence\Model\ToolOperation;
use app\Infrastructure\Persistence\Model\ToolVersion;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshot;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshotItem;
use think\facade\Db;

final class ToolAdminGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly ?ToolEndpointVerifier $endpointPolicy = null,
        private readonly ?ToolConnectionSecretCipher $connectionCipher = null,
    ) {
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function available(int $environmentId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($page < 1 || $limit < 1 || $limit > 100) $this->invalid();
        $from = "FROM sand_ai_tool_environment_binding b
            JOIN sand_ai_tool t ON t.id=b.tool_id AND t.state='published' AND t.status=1 AND t.delete_time IS NULL
            JOIN sand_ai_tool_version v ON v.id=b.tool_version_id AND v.tool_id=t.id AND v.state='published'
            JOIN sand_ai_tool_connection c ON c.id=b.tool_connection_id AND c.environment_id=b.environment_id
                AND c.state='published' AND c.status=1 AND c.delete_time IS NULL
            JOIN sand_ai_tool_connection_revision r ON r.id=b.tool_connection_revision_id
                AND r.tool_connection_id=c.id AND r.state='published'
            WHERE b.environment_id=? AND b.state='published' AND b.status=1 AND b.delete_time IS NULL
                AND r.data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'";
        $db = Db::connect('pgsql');
        $total = $db->query('SELECT count(*) AS total ' . $from, [$environmentId]);
        $rows = $db->query(
            'SELECT t.code,t.name,t.adapter,v.version_no,v.input_schema ' . $from
            . ' ORDER BY t.code,v.version_no DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$environmentId],
        );
        $items = array_map(static function (array $row): array {
            $schema = is_array($row['input_schema']) ? $row['input_schema']
                : json_decode((string) $row['input_schema'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || array_is_list($schema)) {
                throw new ApiProblem('SAND_AI_TOOL_SCHEMA_INVALID', 'Published tool schema is invalid');
            }
            return [
                'code' => (string) $row['code'], 'name' => (string) $row['name'],
                'version' => (int) $row['version_no'], 'adapter' => (string) $row['adapter'],
                'input_schema' => $schema,
            ];
        }, $rows);
        return ['current_page' => $page, 'per_page' => $limit,
            'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function connections(int $environmentId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($page < 1 || $limit < 1 || $limit > 100) $this->invalid();
        $from = "FROM sand_ai_tool_connection c
            JOIN sand_ai_tool_connection_revision r ON r.id=c.active_revision_id
                AND r.tool_connection_id=c.id AND r.state='published'
            WHERE c.environment_id=? AND c.state='published' AND c.status=1
                AND c.delete_time IS NULL AND r.data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'";
        $db = Db::connect('pgsql');
        $total = $db->query('SELECT count(*) AS total ' . $from, [$environmentId]);
        $rows = $db->query(
            'SELECT c.id,c.active_revision_id,c.code,c.name,r.data_class ' . $from
            . ' ORDER BY c.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$environmentId],
        );
        $items = array_map(static fn (array $row): array => [
            'connection_id' => (int) $row['id'],
            'connection_revision_id' => (int) $row['active_revision_id'],
            'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'data_class' => (string) $row['data_class'],
        ], $rows);
        return ['current_page' => $page, 'per_page' => $limit,
            'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array<string,mixed> */
    public function connection(int $environmentId, int $connectionId): array
    {
        $this->requireEnvironment($environmentId);
        if ($connectionId < 1) $this->invalid();
        $rows = Db::connect('pgsql')->query(
            "SELECT c.id,c.active_revision_id,c.code,c.name,r.data_class
             FROM sand_ai_tool_connection c
             JOIN sand_ai_tool_connection_revision r ON r.id=c.active_revision_id
                AND r.tool_connection_id=c.id AND r.state='published'
             WHERE c.id=? AND c.environment_id=? AND c.state='published' AND c.status=1
                AND c.delete_time IS NULL AND r.data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'",
            [$connectionId, $environmentId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Managed connection not found');
        }
        $row = $rows[0];
        return [
            'connection_id' => (int) $row['id'],
            'connection_revision_id' => (int) $row['active_revision_id'],
            'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'data_class' => (string) $row['data_class'],
        ];
    }

    /** @return array<string,mixed> */
    public function connectionByCode(int $environmentId, string $code): array
    {
        $this->requireEnvironment($environmentId);
        $code = $this->code($code);
        $rows = Db::connect('pgsql')->query(
            "SELECT c.id FROM sand_ai_tool_connection c
             JOIN sand_ai_tool_connection_revision r ON r.id=c.active_revision_id
                AND r.tool_connection_id=c.id AND r.state='published'
             WHERE c.environment_id=? AND c.code=? AND c.state='published' AND c.status=1
                AND c.delete_time IS NULL AND r.data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$'",
            [$environmentId, $code],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Managed connection not found');
        }
        return $this->connection($environmentId, (int) $rows[0]['id']);
    }

    /** @return array<string,mixed> */
    public function workspace(int $environmentId, int $connectionId, string $code): array
    {
        $connection = $this->connection($environmentId, $connectionId);
        $code = $this->code($code);
        $tool = Tool::where('code', $code)->where('status', 1)
            ->whereNull('delete_time')->find();
        if ($tool === null || (string) $tool->state === 'revoked') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Managed tool not found');
        }
        $draftVersion = ToolVersion::where('tool_id', (int) $tool->id)
            ->where('state', 'draft')->find();
        $activeVersion = $tool->active_version_id === null ? null
            : ToolVersion::where('id', (int) $tool->active_version_id)
                ->where('tool_id', (int) $tool->id)->where('state', 'published')->find();
        $draft = $draftVersion === null ? null
            : $this->workspaceVersion($tool, $draftVersion, $environmentId, $connection);
        $published = $activeVersion === null ? null
            : $this->workspaceVersion($tool, $activeVersion, $environmentId, $connection);
        $binding = null;
        if ($published !== null) {
            $bound = ToolEnvironmentBinding::where('environment_id', $environmentId)
                ->where('tool_id', (int) $tool->id)
                ->where('tool_version_id', (int) $published['tool_version_id'])
                ->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find();
            if ($bound !== null) {
                $binding = [
                    'binding_id' => (int) $bound->id,
                    'environment_id' => $environmentId,
                    'tool_id' => (int) $tool->id,
                    'tool_version_id' => (int) $bound->tool_version_id,
                    'connection_id' => (int) $bound->tool_connection_id,
                    'connection_revision_id' => (int) $bound->tool_connection_revision_id,
                    'state' => 'published',
                ];
            }
        }
        return [
            'tool' => [
                'tool_id' => (int) $tool->id, 'code' => (string) $tool->code,
                'name' => (string) $tool->name, 'adapter' => (string) $tool->adapter,
                'state' => (string) $tool->state,
            ],
            'draft' => $draft, 'published' => $published, 'binding' => $binding,
        ];
    }

    /** @param array<string,mixed> $connection @return array<string,mixed>|null */
    private function workspaceVersion(Tool $tool, ToolVersion $version, int $environmentId, array $connection): ?array
    {
        if ((string) $tool->adapter === 'mcp_streamable_http') {
            $item = ToolMcpSnapshotItem::where('id', (int) $version->mcp_snapshot_item_id)->find();
            $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)
                ->where('environment_id', $environmentId)
                ->where('tool_connection_id', (int) $connection['connection_id'])
                ->where('tool_connection_revision_id', (int) $connection['connection_revision_id'])
                ->where('state', 'tested')->find();
            if ($snapshot === null) return null;
            McpSnapshotPolicy::assertFrozen($this->snapshotItem($item), new McpReadonlyToolRegistry());
        } elseif ((string) $tool->adapter !== 'standard_readonly_http' || $version->mcp_snapshot_item_id !== null) {
            return null;
        }
        return [
            'tool_id' => (int) $tool->id, 'tool_version_id' => (int) $version->id,
            'code' => (string) $tool->code, 'version' => (int) $version->version_no,
            'adapter' => (string) $tool->adapter, 'state' => (string) $version->state,
        ];
    }

    /** Original immutable receipt only; current resource state never proves whether a request committed. */
    public function readOperation(string $operation, int $environmentId, string $requestKey, string $targetSha256, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $key = $this->key($requestKey);
        if (!in_array($operation, ['create_connection', 'create_draft', 'publish', 'bind_environment', 'revoke', 'mcp_discovery'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $targetSha256) !== 1 || $actor === '') {
            $this->invalid();
        }
        $query = ToolOperation::where('operation', $operation)->where('idempotency_key', $key);
        if (in_array($operation, ['create_connection', 'bind_environment', 'mcp_discovery'], true)) {
            $query = $query->where('scope_key', 'environment:' . $environmentId);
        }
        $rows = $query->limit(101)->select();
        if (count($rows) > 100) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original tool operation cannot be uniquely confirmed');
        }
        $matches = [];
        foreach ($rows as $row) {
            $stored = $row->result;
            $metadata = is_array($stored) ? ($stored['_recovery'] ?? null) : null;
            // Legacy receipts do not establish an administrator or selected environment.
            if (!is_array($metadata) || ($metadata['version'] ?? null) !== 1
                || ($metadata['actor_ref'] ?? null) !== $actor
                || ($metadata['environment_id'] ?? null) !== $environmentId
                || ($metadata['operation'] ?? null) !== $operation
                || ($metadata['scope_key'] ?? null) !== (string) $row->scope_key
                || !is_string($metadata['target_sha256'] ?? null)
                || !hash_equals($targetSha256, $metadata['target_sha256'])) {
                continue;
            }
            unset($stored['_recovery']);
            $target = $metadata['target'] ?? null;
            if (!is_array($target) || !is_string($metadata['request_fingerprint'] ?? null)
                || !hash_equals((string) $row->request_fingerprint, $metadata['request_fingerprint'])
                || !hash_equals($targetSha256, hash('sha256', ToolFingerprint::canonicalJson($target)))
                || !is_string($metadata['result_sha256'] ?? null)
                || !hash_equals($metadata['result_sha256'], hash('sha256', ToolFingerprint::canonicalJson($stored)))) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original tool operation receipt changed');
            }
            if (!$this->isRejectedReceipt($stored)) {
                if ($operation === 'mcp_discovery') throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original MCP discovery is unavailable');
                $this->assertOriginalResource($operation, $environmentId, $target, $stored, (string) $row->scope_key);
            }
            $matches[] = $stored;
        }
        if (count($matches) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
        }
        $this->requireEnvironment($environmentId);
        return [
            'operation' => $operation, 'environment_id' => $environmentId,
            'request_key' => $key, 'target_sha256' => $targetSha256,
            'result' => array_replace($matches[0], ['idempotent_replay' => true]),
        ];
    }

    /** Relations may be revoked now; the original receipt is not a new publication or binding. */
    private function assertOriginalResource(string $operation, int $environmentId, array $target, array $result, string $scope): void
    {
        if ($operation === 'create_connection') {
            $connection = ToolConnection::where('id', (int) ($result['connection_id'] ?? 0))
                ->where('environment_id', $environmentId)->whereNull('delete_time')->find();
            $revision = $connection === null ? null : ToolConnectionRevision::where('id', (int) ($result['connection_revision_id'] ?? 0))
                ->where('tool_connection_id', (int) $connection->id)->find();
            if ($connection === null || $revision === null || $scope !== 'environment:' . $environmentId
                || ($target['code'] ?? null) !== (string) $connection->code
                || ($result['code'] ?? null) !== (string) $connection->code) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
            }
            return;
        }
        $tool = Tool::where('id', (int) ($result['tool_id'] ?? 0))->whereNull('delete_time')->find();
        $version = $tool === null ? null : ToolVersion::where('id', (int) ($result['tool_version_id'] ?? 0))
            ->where('tool_id', (int) $tool->id)->find();
        if ($tool === null || $version === null || ($result['code'] ?? (string) $tool->code) !== (string) $tool->code) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
        }
        if ($operation === 'create_draft') {
            if (!in_array($scope, ['tool-code:' . (string) $tool->code,
                    'environment:' . $environmentId . ':draft:' . hash('sha256', ToolFingerprint::canonicalJson($target))], true)
                || ($target['adapter'] ?? null) !== (string) $tool->adapter) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
            }
            if ((string) $tool->adapter === 'mcp_streamable_http') {
                $item = ToolMcpSnapshotItem::where('id', (int) ($target['mcp_snapshot_item_id'] ?? 0))->find();
                $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)
                    ->where('environment_id', $environmentId)->find();
                if ($snapshot === null || (int) $version->mcp_snapshot_item_id !== (int) $item->id) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
                }
            } elseif (($target['code'] ?? null) !== (string) $tool->code) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
            }
            return;
        }
        if (in_array($operation, ['publish', 'revoke'], true)) {
            if ($scope !== 'tool:' . (int) $tool->id || ($target['tool_id'] ?? null) !== (int) $tool->id) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
            }
            return;
        }
        $binding = ToolEnvironmentBinding::where('id', (int) ($result['binding_id'] ?? 0))
            ->where('environment_id', $environmentId)->where('tool_id', (int) $tool->id)
            ->where('tool_version_id', (int) $version->id)->whereNull('delete_time')->find();
        $connection = $binding === null ? null : ToolConnection::where('id', (int) $binding->tool_connection_id)
            ->where('environment_id', $environmentId)->whereNull('delete_time')->find();
        $revision = $connection === null ? null : ToolConnectionRevision::where('id', (int) $binding->tool_connection_revision_id)
            ->where('tool_connection_id', (int) $connection->id)->find();
        if ($binding === null || $connection === null || $revision === null || $scope !== 'environment:' . $environmentId
            || ($result['environment_id'] ?? null) !== $environmentId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
        }
        foreach (['tool_id' => (int) $tool->id, 'tool_version_id' => (int) $version->id,
            'connection_id' => (int) $connection->id, 'connection_revision_id' => (int) $revision->id] as $field => $expected) {
            if (($target[$field] ?? null) !== $expected || ($result[$field] ?? null) !== $expected) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
            }
        }
    }

    /** @param array<string,mixed> $dto @return array<string,mixed> */
    public function createConnection(int $environmentId, array $dto, string $idempotencyKey, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $key = $this->key($idempotencyKey);
        $target = ['code' => $this->code($dto['code'] ?? null)];
        $scope = 'environment:' . $environmentId;
        // A stable keyed digest establishes the actual input before validation without retaining credentials.
        $fingerprint = ToolFingerprint::for('create_connection', ['environment_id' => $environmentId,
            'raw_input_hmac' => ($this->connectionCipher ?? new ToolConnectionSecretCipher())->idempotencyFingerprint($dto)]);
        return $this->localOperation($scope, 'create_connection', $key, $fingerprint, $actor, $environmentId, $target,
            function () use ($environmentId, $key, $actor, $dto, $scope, $fingerprint, $target): array {
            $normalized = $this->connectionInput($dto);
            $legacyFingerprint = ToolFingerprint::for('create_connection', ['environment_id' => $environmentId] + $this->redactedConnectionFingerprint($normalized));
            if (($replay = $this->operationReplay($scope, 'create_connection', $key, $fingerprint, $actor, $legacyFingerprint)) !== null) return $replay;
            ($this->endpointPolicy ?? new ToolEndpointPolicy())->verify($normalized['origin'], $normalized['path']);
            $this->lock($scope . ':connection:' . $normalized['code']);
            if (ToolConnection::where('environment_id', $environmentId)->where('code', $normalized['code'])->lock(true)->find() !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Managed connection code already exists in this environment');
            }
            $connection = ToolConnection::create(['environment_id' => $environmentId, 'code' => $normalized['code'], 'name' => $normalized['name'], 'state' => 'published', 'status' => 1]);
            $config = ['origin' => $normalized['origin'], 'path' => $normalized['path'], 'authorization' => $normalized['authorization']];
            $cipher = $this->connectionCipher ?? new ToolConnectionSecretCipher();
            $encryptedConfig = $cipher->encrypt($config);
            $revision = ToolConnectionRevision::create([
                'tool_connection_id' => (int) $connection->id, 'revision_no' => 1,
                'encryption_key_id' => $encryptedConfig['key_id'], 'encrypted_config' => $encryptedConfig,
                'config_fingerprint' => $cipher->fingerprint($config, $encryptedConfig['key_id']),
                'data_class' => $normalized['data_class'], 'state' => 'published',
            ]);
            $connection->save(['active_revision_id' => (int) $revision->id]);
            $result = ['connection_id' => (int) $connection->id, 'connection_revision_id' => (int) $revision->id, 'code' => (string) $connection->code, 'name' => (string) $connection->getAttr('name'), 'revision' => 1, 'state' => 'published', 'data_class' => $normalized['data_class'], 'has_credential' => $normalized['authorization']['mode'] !== 'none', 'idempotent_replay' => false];
            $this->recordOperation($scope, 'create_connection', $key, $fingerprint, $result, $actor, $environmentId, ['code' => $normalized['code']]);
            $this->audit($actor, 'sand_ai.tool.connection.create', 'tool_connection', (int) $connection->id, ['environment_id' => $environmentId, 'revision_id' => (int) $revision->id]);
            return $result;
        });
    }

    /** @param array<string,mixed> $dto @return array<string,mixed> */
    public function createDraft(array $dto, string $idempotencyKey, string $actor, ?int $environmentId = null): array
    {
        $key = $this->key($idempotencyKey);
        if ($environmentId !== null) {
            $this->requireEnvironment($environmentId);
            $target = $this->draftTarget($dto);
            $scope = 'environment:' . $environmentId . ':draft:' . hash('sha256', ToolFingerprint::canonicalJson($target));
            $fingerprint = ToolFingerprint::for('create_draft', ['environment_id' => $environmentId, 'raw_input' => $dto]);
            $input = null;
        } else {
            // Preserve the original API fingerprint for legacy callers without an environment.
            $input = $this->toolInput($dto);
            $scope = 'tool-code:' . $input['code'];
            $fingerprint = ToolFingerprint::for('create_draft', $input);
            $target = $this->draftTarget($input);
        }
        return $this->localOperation($scope, 'create_draft', $key, $fingerprint, $actor, $environmentId, $target,
            function () use ($key, $actor, $dto, $input, $scope, $fingerprint, $environmentId, $target): array {
            if (($replay = $this->operationReplay($scope, 'create_draft', $key, $fingerprint, $actor)) !== null) return $replay;
            $input ??= $this->toolInput($dto);
            $this->lock('tool-code:' . $input['code']);
            if ($input['adapter'] === 'mcp_streamable_http') {
                $item = ToolMcpSnapshotItem::where('id', $input['mcp_snapshot_item_id'])->lock(true)->find();
                $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)->where('state', 'tested')->lock(true)->find();
                if ($item === null || $snapshot === null || ($environmentId !== null && (int) $snapshot->environment_id !== $environmentId)) throw new ApiProblem('SAND_AI_MCP_SNAPSHOT_NOT_TESTED', 'MCP tool draft requires a tested exact snapshot item');
                McpSnapshotPolicy::assertFrozen($this->snapshotItem($item), new McpReadonlyToolRegistry());
            }
            $tool = Tool::where('code', $input['code'])->lock(true)->find();
            if ($tool !== null && (string) $tool->state === 'revoked') throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Revoked tool code cannot be reused');
            if ($tool !== null && ToolVersion::where('tool_id', (int) $tool->id)->where('state', 'draft')->lock(true)->find() !== null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Tool already has a draft version');
            if ($tool === null) {
                $tool = Tool::create(['code' => $input['code'], 'name' => $input['name'], 'description' => $input['description'], 'adapter' => $input['adapter'], 'effect_class' => 'read_only', 'state' => 'draft', 'status' => 1]);
            }
            if ((string) $tool->adapter !== $input['adapter']) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Tool code is already assigned to another adapter');
            $versionNo = (int) ToolVersion::where('tool_id', (int) $tool->id)->max('version_no') + 1;
            $version = ToolVersion::create([
                'tool_id' => (int) $tool->id, 'version_no' => $versionNo, 'name' => $input['name'], 'description' => $input['description'],
                'input_schema' => $input['input_schema'], 'output_schema' => $input['output_schema'], 'review_policy' => $input['review_policy'],
                'timeout_ms' => $input['timeout_ms'], 'response_max_bytes' => $input['response_max_bytes'], 'state' => 'draft',
                'mcp_snapshot_item_id' => $input['mcp_snapshot_item_id'],
            ]);
            $result = $this->toolResult($tool, $version, 'draft', false);
            $this->recordOperation($scope, 'create_draft', $key, $fingerprint, $result, $actor, $environmentId, $target);
            $this->audit($actor, 'sand_ai.tool.create_draft', 'tool', (int) $tool->id, ['version_id' => (int) $version->id]);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function publish(int $toolId, string $idempotencyKey, string $actor, ?int $environmentId = null): array
    {
        return $this->transition($toolId, 'publish', $idempotencyKey, $actor, $environmentId);
    }

    /** @return array<string,mixed> */
    public function revoke(int $toolId, string $idempotencyKey, string $actor, ?int $environmentId = null): array
    {
        return $this->transition($toolId, 'revoke', $idempotencyKey, $actor, $environmentId);
    }

    /** @return array<string,mixed> */
    public function bind(int $environmentId, int $toolId, int $toolVersionId, int $connectionId, int $connectionRevisionId, string $idempotencyKey, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        foreach ([$toolId, $toolVersionId, $connectionId, $connectionRevisionId] as $id) if ($id < 1) $this->invalid();
        $key = $this->key($idempotencyKey); $scope = 'environment:' . $environmentId;
        $payload = compact('environmentId', 'toolId', 'toolVersionId', 'connectionId', 'connectionRevisionId');
        $fingerprint = ToolFingerprint::for('bind_environment', $payload);
        $target = ['tool_id' => $toolId, 'tool_version_id' => $toolVersionId,
            'connection_id' => $connectionId, 'connection_revision_id' => $connectionRevisionId];
        return $this->localOperation($scope, 'bind_environment', $key, $fingerprint, $actor, $environmentId, $target,
            function () use ($environmentId, $toolId, $toolVersionId, $connectionId, $connectionRevisionId, $key, $actor, $scope, $fingerprint): array {
            if (($replay = $this->operationReplay($scope, 'bind_environment', $key, $fingerprint, $actor)) !== null) return $replay;
            $tool = Tool::where('id', $toolId)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $version = ToolVersion::where('id', $toolVersionId)->where('tool_id', $toolId)->where('state', 'published')->lock(true)->find();
            $connection = ToolConnection::where('id', $connectionId)->where('environment_id', $environmentId)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $revision = ToolConnectionRevision::where('id', $connectionRevisionId)->where('tool_connection_id', $connectionId)->where('state', 'published')->lock(true)->find();
            if ($tool === null || $version === null || $connection === null || $revision === null) throw new ApiProblem('SAND_AI_TOOL_BINDING_INVALID', 'Tool binding references are not current published resources');
            if ((string) $tool->adapter === 'mcp_streamable_http') {
                $item = ToolMcpSnapshotItem::where('id', (int) $version->mcp_snapshot_item_id)->lock(true)->find();
                $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)->where('state', 'tested')->lock(true)->find();
                if ($snapshot === null || (int) $snapshot->environment_id !== $environmentId
                    || (int) $snapshot->tool_connection_id !== $connectionId
                    || (int) $snapshot->tool_connection_revision_id !== $connectionRevisionId) {
                    throw new ApiProblem('SAND_AI_TOOL_BINDING_INVALID', 'MCP Tool must bind to its discovered environment and exact connection revision');
                }
            }
            $existing = ToolEnvironmentBinding::where('environment_id', $environmentId)->where('tool_version_id', $toolVersionId)->lock(true)->find();
            if ($existing !== null) {
                if ((int) $existing->tool_connection_id !== $connectionId || (int) $existing->tool_connection_revision_id !== $connectionRevisionId || (int) $existing->status !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Tool version is already bound differently in this environment');
                $binding = $existing;
            } else {
                $binding = ToolEnvironmentBinding::create(['environment_id' => $environmentId, 'tool_id' => $toolId, 'tool_version_id' => $toolVersionId, 'tool_connection_id' => $connectionId, 'tool_connection_revision_id' => $connectionRevisionId, 'state' => 'published', 'status' => 1]);
            }
            $result = ['binding_id' => (int) $binding->id, 'environment_id' => $environmentId, 'tool_id' => $toolId, 'tool_version_id' => $toolVersionId, 'connection_id' => $connectionId, 'connection_revision_id' => $connectionRevisionId, 'state' => 'published', 'idempotent_replay' => false];
            $this->recordOperation($scope, 'bind_environment', $key, $fingerprint, $result, $actor, $environmentId, [
                'tool_id' => $toolId, 'tool_version_id' => $toolVersionId,
                'connection_id' => $connectionId, 'connection_revision_id' => $connectionRevisionId,
            ]);
            $this->audit($actor, 'sand_ai.tool.bind_environment', 'tool_environment_binding', (int) $binding->id, ['environment_id' => $environmentId, 'tool_version_id' => $toolVersionId, 'connection_revision_id' => $connectionRevisionId]);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function transition(int $toolId, string $operation, string $idempotencyKey, string $actor, ?int $environmentId = null): array
    {
        if ($toolId < 1) $this->invalid();
        $key = $this->key($idempotencyKey); $scope = 'tool:' . $toolId;
        if ($environmentId !== null) $this->requireEnvironment($environmentId);
        $fingerprint = ToolFingerprint::for($operation, $environmentId === null ? ['tool_id' => $toolId] : ['tool_id' => $toolId, 'environment_id' => $environmentId]);
        return $this->localOperation($scope, $operation, $key, $fingerprint, $actor, $environmentId, ['tool_id' => $toolId],
            function () use ($toolId, $operation, $key, $actor, $scope, $fingerprint, $environmentId): array {
            if (($replay = $this->operationReplay($scope, $operation, $key, $fingerprint, $actor)) !== null) return $replay;
            $tool = Tool::where('id', $toolId)->lock(true)->find();
            if ($tool === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Tool not found');
            if ($operation === 'publish') {
                if ((string) $tool->state === 'revoked') throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Revoked tool cannot be published again');
                $version = ToolVersion::where('tool_id', $toolId)->where('state', 'draft')->lock(true)->find();
                if ($version === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Tool has no publishable draft');
                $version->save(['state' => 'published', 'published_time' => gmdate('Y-m-d H:i:s')]);
                $tool->save(['active_version_id' => (int) $version->id, 'state' => 'published', 'status' => 1]);
                $state = 'published';
            } else {
                $version = ToolVersion::where('id', (int) $tool->active_version_id)->where('tool_id', $toolId)->lock(true)->find();
                if ($version === null || (string) $tool->state !== 'published') throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Tool is not published');
                $tool->save(['state' => 'revoked', 'status' => 2]); $state = 'revoked';
            }
            $result = $this->toolResult($tool, $version, $state, false);
            $this->recordOperation($scope, $operation, $key, $fingerprint, $result, $actor, $environmentId, ['tool_id' => $toolId]);
            $this->audit($actor, 'sand_ai.tool.' . $operation, 'tool', $toolId, ['version_id' => (int) $version->id]);
            return $result;
        });
    }

    /** @param array<string,mixed> $dto @return array<string,mixed> */
    private function connectionInput(array $dto): array
    {
        $allowed = ['code', 'name', 'origin', 'path', 'authorization', 'data_class'];
        if (array_diff(array_keys($dto), $allowed) !== [] || array_diff($allowed, array_keys($dto)) !== []) $this->invalid();
        $code = $this->code($dto['code']); $name = $this->text($dto['name'], 128, false);
        if (!is_array($dto['authorization']) || array_is_list($dto['authorization'])) $this->invalid();
        $auth = $dto['authorization']; $mode = $auth['mode'] ?? null;
        if (!in_array($mode, ['none', 'bearer'], true)) $this->invalid();
        $expected = $mode === 'none' ? ['mode'] : ['credential', 'mode'];
        $actual = array_keys($auth); sort($actual, SORT_STRING);
        if ($actual !== $expected) $this->invalid();
        $credential = $mode === 'bearer' ? $this->text($auth['credential'] ?? null, 2048, false) : null;
        if ($credential !== null && preg_match('/[\r\n]/', $credential) === 1) $this->invalid();
        $dataClass = $dto['data_class'];
        if (!is_string($dataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1) $this->invalid();
        return ['code' => $code, 'name' => $name, 'origin' => $this->text($dto['origin'], 2048, false), 'path' => $this->text($dto['path'], 512, false), 'data_class' => $dataClass, 'authorization' => ['mode' => $mode, 'credential' => $credential]];
    }

    /** @param array<string,mixed> $dto @return array<string,mixed> */
    private function toolInput(array $dto): array
    {
        $allowed = ['adapter', 'mcp_snapshot_item_id', 'code', 'name', 'description', 'input_schema', 'output_schema', 'review_policy', 'timeout_ms', 'response_max_bytes'];
        if (array_diff(array_keys($dto), $allowed) !== []) $this->invalid();
        $review = $dto['review_policy'] ?? ['review_required' => false];
        if (!is_array($review) || array_keys($review) !== ['review_required'] || !is_bool($review['review_required'])) $this->invalid();
        $timeout = $dto['timeout_ms'] ?? 10000; $maxBytes = $dto['response_max_bytes'] ?? 262144;
        if (!is_int($timeout) || $timeout < 100 || $timeout > 30000 || !is_int($maxBytes) || $maxBytes < 1024 || $maxBytes > 1048576) $this->invalid();
        $adapter = $dto['adapter'] ?? 'standard_readonly_http';
        if (!in_array($adapter, ['standard_readonly_http', 'mcp_streamable_http'], true)) $this->invalid();
        if ($adapter === 'mcp_streamable_http') {
            $itemId = $dto['mcp_snapshot_item_id'] ?? null;
            if (!is_int($itemId) || $itemId < 1) $this->invalid();
            $item = ToolMcpSnapshotItem::find($itemId);
            $snapshot = $item === null ? null : ToolMcpSnapshot::where('id', (int) $item->mcp_snapshot_id)->where('state', 'tested')->find();
            if ($item === null || $snapshot === null) throw new ApiProblem('SAND_AI_MCP_SNAPSHOT_NOT_TESTED', 'MCP tool draft requires a tested exact snapshot item');
            $frozen = $this->snapshotItem($item);
            $registry = new McpReadonlyToolRegistry();
            McpSnapshotPolicy::assertFrozen($frozen, $registry);
            $definition = $registry->require((string) $item->local_tool_code);
            foreach (['code' => $definition['code'], 'name' => $definition['name'], 'description' => $definition['description']] as $field => $expected) {
                if (array_key_exists($field, $dto) && $dto[$field] !== $expected) $this->invalid();
            }
            foreach (['input_schema', 'output_schema'] as $field) {
                if (array_key_exists($field, $dto) && ToolFingerprint::canonicalJson(ToolSchemaPolicy::normalize($dto[$field])) !== ToolFingerprint::canonicalJson($definition[$field])) $this->invalid();
            }
            return ['adapter' => $adapter, 'mcp_snapshot_item_id' => $itemId, 'code' => $definition['code'], 'name' => $definition['name'], 'description' => $definition['description'], 'input_schema' => $definition['input_schema'], 'output_schema' => $definition['output_schema'], 'review_policy' => $review, 'timeout_ms' => $timeout, 'response_max_bytes' => $maxBytes];
        }
        if (array_key_exists('mcp_snapshot_item_id', $dto) || !array_key_exists('code', $dto) || !array_key_exists('name', $dto) || !array_key_exists('input_schema', $dto) || !array_key_exists('output_schema', $dto)) $this->invalid();
        $code = $this->code($dto['code']);
        $inputSchema = ToolSchemaPolicy::normalize($dto['input_schema']);
        $outputSchema = ToolSchemaPolicy::normalize($dto['output_schema']);
        if ($code === 'external_search') {
            $definition = (new McpReadonlyToolRegistry())->require($code);
            if (ToolFingerprint::canonicalJson($inputSchema) !== ToolFingerprint::canonicalJson($definition['input_schema'])
                || ToolFingerprint::canonicalJson($outputSchema) !== ToolFingerprint::canonicalJson($definition['output_schema'])) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'External search requires the managed search schemas');
            }
        }
        return ['adapter' => $adapter, 'mcp_snapshot_item_id' => null, 'code' => $code, 'name' => $this->text($dto['name'], 128, false), 'description' => $this->text($dto['description'] ?? '', 1000, true), 'input_schema' => $inputSchema, 'output_schema' => $outputSchema, 'review_policy' => $review, 'timeout_ms' => $timeout, 'response_max_bytes' => $maxBytes];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function redactedConnectionFingerprint(array $input): array
    {
        $cipher = $this->connectionCipher ?? new ToolConnectionSecretCipher();
        return ['code' => $input['code'], 'name' => $input['name'], 'origin' => $input['origin'], 'path' => $input['path'], 'data_class' => $input['data_class'], 'authorization' => ['mode' => $input['authorization']['mode'], 'credential_hmac' => $input['authorization']['credential'] === null ? null : $cipher->idempotencyFingerprint(['credential' => $input['authorization']['credential']])]];
    }

    /** @return array<string,mixed>|null */
    public function operationReplay(string $scope, string $operation, string $key, string $fingerprint, string $actor, ?string $legacyFingerprint = null): ?array
    {
        $this->lock($scope . ':' . $operation . ':' . $key);
        $operationRow = ToolOperation::where('scope_key', $scope)->where('operation', $operation)->where('idempotency_key', $key)->lock(true)->find();
        if ($operationRow === null) return null;
        $matchesFingerprint = hash_equals((string) $operationRow->request_fingerprint, $fingerprint)
            || ($legacyFingerprint !== null && !isset($operationRow->result['_recovery'])
                && hash_equals((string) $operationRow->request_fingerprint, $legacyFingerprint));
        if (!$matchesFingerprint || !is_array($operationRow->result)) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Tool operation idempotency key belongs to another request');
        $stored = $operationRow->result;
        if (isset($stored['_recovery']) && (!is_array($stored['_recovery'])
            || ($stored['_recovery']['actor_ref'] ?? null) !== $actor)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original tool operation is unavailable');
        }
        unset($stored['_recovery']);
        if ($this->isRejectedReceipt($stored)) {
            throw new ApiProblem($stored['error_code'], 'Original tool request was rejected; confirm its receipt before correcting the request');
        }
        return [...$stored, 'idempotent_replay' => true];
    }

    /**
     * Only these local management operations run here. No discovery, provider send or
     * uncertain transport result may be recorded as not submitted.
     * A rejection is persisted only after transaction() has returned its rollback.
     */
    private function localOperation(string $scope, string $operation, string $key, string $fingerprint, string $actor, ?int $environmentId, array $target, \Closure $mutation): array
    {
        try {
            return Db::connect('pgsql')->transaction(function () use ($scope, $operation, $key, $mutation): array {
                $this->lock($scope . ':' . $operation . ':' . $key);
                return $mutation();
            });
        } catch (ApiProblem $problem) {
            if ($environmentId === null || !$this->localRejectionCode($problem->errorCode)) throw $problem;
            // A racing request may have committed after this transaction rolled back.
            // Resolve under the original operation lock; never overwrite its receipt.
            $committed = Db::connect('pgsql')->transaction(function () use ($scope, $operation, $key, $fingerprint, $actor, $environmentId, $target, $problem): ?array {
                if (($replay = $this->operationReplay($scope, $operation, $key, $fingerprint, $actor)) !== null) return $replay;
                $this->recordOperation($scope, $operation, $key, $fingerprint, [
                    'state' => 'rejected', 'submission_state' => 'not_submitted',
                    'error_code' => $problem->errorCode,
                ], $actor, $environmentId, $target);
                return null;
            });
            if ($committed !== null) return $committed;
            throw $problem;
        }
    }

    private function localRejectionCode(string $code): bool
    {
        return in_array($code, [
            'SAND_AI_RESOURCE_CONFLICT', 'SAND_AI_RESOURCE_NOT_FOUND',
            'SAND_AI_TOOL_BINDING_INVALID', 'SAND_AI_MCP_SNAPSHOT_NOT_TESTED',
            'SAND_AI_MCP_REGISTRY_DRIFTED', 'SAND_AI_MCP_SCHEMA_DRIFTED',
            'SAND_AI_TOOL_ENDPOINT_FORBIDDEN', 'SAND_AI_TOOL_DNS_UNAVAILABLE',
            'SAND_AI_VALIDATION_ERROR', 'SAND_AI_SCHEMA_UNSUPPORTED',
            'SAND_AI_TOOL_CONFIGURATION_DRIFTED',
        ], true);
    }

    private function isRejectedReceipt(array $result): bool
    {
        if (($result['state'] ?? null) !== 'rejected') return false;
        if (($result['submission_state'] ?? null) !== 'not_submitted'
            || !is_string($result['error_code'] ?? null) || !$this->localRejectionCode($result['error_code'])
            || count($result) !== 3) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original tool rejection receipt changed');
        }
        return true;
    }

    /** Called only by the locked MCP claim before any snapshot or protocol request exists. */
    public function recordRejectedMcpClaim(int $environmentId, string $key, string $fingerprint, string $actor, array $target): void
    {
        $this->recordOperation('environment:' . $environmentId, 'mcp_discovery', $key, $fingerprint, [
            'state' => 'rejected', 'submission_state' => 'not_submitted',
            'error_code' => 'SAND_AI_TOOL_CONFIGURATION_DRIFTED',
        ], $actor, $environmentId, $target);
    }

    /** A trustworthy opaque draft target must exist before a validation rejection can be retained. */
    private function draftTarget(array $dto): array
    {
        $adapter = $dto['adapter'] ?? 'standard_readonly_http';
        if ($adapter === 'mcp_streamable_http') {
            $itemId = $dto['mcp_snapshot_item_id'] ?? null;
            if (!is_int($itemId) || $itemId < 1) $this->invalid();
            return ['adapter' => $adapter, 'mcp_snapshot_item_id' => $itemId];
        }
        if ($adapter !== 'standard_readonly_http') $this->invalid();
        return ['adapter' => $adapter, 'code' => $this->code($dto['code'] ?? null)];
    }

    /** @param array<string,mixed> $result */
    private function recordOperation(string $scope, string $operation, string $key, string $fingerprint, array $result, string $actor, ?int $environmentId, array $target): void
    {
        if ($actor === '' || trim($actor) !== $actor) $this->invalid();
        $metadata = [
            'version' => 1, 'actor_ref' => $actor, 'environment_id' => $environmentId,
            'operation' => $operation, 'scope_key' => $scope, 'request_fingerprint' => $fingerprint,
            'target' => $target, 'target_sha256' => hash('sha256', ToolFingerprint::canonicalJson($target)),
            'result_sha256' => hash('sha256', ToolFingerprint::canonicalJson($result)),
        ];
        ToolOperation::create(['scope_key' => $scope, 'operation' => $operation, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'result' => $result + ['_recovery' => $metadata]]);
    }

    /** @return array<string,mixed> */
    private function toolResult(Tool $tool, ToolVersion $version, string $state, bool $replay): array
    {
        return ['tool_id' => (int) $tool->id, 'tool_version_id' => (int) $version->id, 'code' => (string) $tool->code, 'version' => (int) $version->version_no, 'adapter' => (string) $tool->adapter, 'effect_class' => 'read_only', 'state' => $state, 'idempotent_replay' => $replay];
    }

    /** @return array<string,mixed> */
    private function snapshotItem(ToolMcpSnapshotItem $item): array
    {
        return [
            'local_tool_code' => (string) $item->local_tool_code,
            'remote_name' => (string) $item->remote_name,
            'remote_description' => (string) $item->remote_description,
            'remote_input_schema' => (array) $item->remote_input_schema,
            'remote_output_schema' => is_array($item->remote_output_schema) ? $item->remote_output_schema : null,
            'input_schema' => (array) $item->input_schema,
            'output_schema' => (array) $item->output_schema,
            'citation_mode' => (string) $item->citation_mode,
            'registry_definition_hash' => (string) $item->registry_definition_hash,
            'item_hash' => (string) $item->item_hash,
        ];
    }

    private function requireEnvironment(int $environmentId): void
    {
        if ($environmentId < 1) $this->invalid();
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    /** @param array<string,mixed> $context */
    private function audit(string $actor, string $action, string $type, int $id, array $context): void
    {
        AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actor, 'action' => $action, 'resource_type' => $type, 'resource_id' => $id, 'summary' => 'SandAI managed external tool lifecycle changed', 'context' => $context]);
    }

    private function lock(string $scope): void
    {
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope]);
    }

    private function code(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $value) !== 1) $this->invalid();
        return $value;
    }

    private function text(mixed $value, int $max, bool $empty): string
    {
        if (!is_string($value)) $this->invalid();
        $value = trim($value);
        if ((!$empty && $value === '') || mb_strlen($value, 'UTF-8') > $max) $this->invalid();
        return $value;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128) $this->invalid();
        return $value;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Managed external tool request is invalid');
    }
}
