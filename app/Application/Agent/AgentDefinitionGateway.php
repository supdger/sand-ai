<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class AgentDefinitionGateway
{
    /** Recover an original admin operation without recreating drafts or changing publication state. */
    public function readOperationByRequestKey(int $environmentId, string $operation, string $code, string $key,
        string $actor, \Closure $revalidateAdmin): array
    {
        $this->requireEnvironment($environmentId);
        $this->requireMutation($key, $actor);
        if (!in_array($operation, ['draft', 'publish', 'disable', 'rollback'], true)
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) self::invalid();
        $revalidateAdmin();
        $record = $this->operationForRead($environmentId, $operation, $key, $actor);
        $response = $this->operationSnapshot($record, $operation, $code);
        $proof = $this->operationResourceProof($environmentId, $code, $record, $response, $operation);
        $revalidateAdmin();
        $this->requireEnvironment($environmentId);
        $current = $this->operationForRead($environmentId, $operation, $key, $actor);
        if (!RequestPayloadFingerprint::same($record, $current)
            || !hash_equals($proof, $this->operationResourceProof($environmentId, $code, $current, $response, $operation))) self::operationMissing();
        $revalidateAdmin();
        return [...$response, 'idempotent_replay' => true];
    }

    /** @return array<string,mixed> */
    private function operationForRead(int $environmentId, string $operation, string $key, string $actor): array
    {
        $rows = Db::connect('pgsql')->query('SELECT environment_id,operation_code,idempotency_key,request_fingerprint,
            agent_definition_id,agent_version_id,response_snapshot,created_by FROM sand_ai_agent_operation
            WHERE environment_id=? AND operation_code=? AND idempotency_key=?', [$environmentId, $operation, $key]);
        if (count($rows) !== 1) self::operationMissing();
        $row = $rows[0];
        if ((int) $row['environment_id'] !== $environmentId || (string) $row['operation_code'] !== $operation
            || (string) $row['idempotency_key'] !== $key || (string) $row['created_by'] !== $actor
            || !is_string($row['request_fingerprint']) || preg_match('/^[a-f0-9]{64}$/D', $row['request_fingerprint']) !== 1) self::operationMissing();
        return $row;
    }

    /** @return array<string,mixed> */
    private function operationSnapshot(array $record, string $operation, string $code): array
    {
        try { $response = is_string($record['response_snapshot'])
            ? json_decode($record['response_snapshot'], true, 16, JSON_THROW_ON_ERROR) : $record['response_snapshot']; }
        catch (\JsonException) { self::operationMissing(); }
        if (is_array($response) && ($response['operation_state'] ?? null) === 'rejected') {
            $keys = ['operation_state', 'reason', 'agent_definition_id', 'code', 'expected_snapshot_sha256'];
            // 拒绝原因枚举：configuration_changed 是确认期快照漂移；其余是发布前置校验的确定性拒绝。
            $reasons = ['configuration_changed', 'no_draft_version', 'model_unavailable',
                'knowledge_environment_mismatch', 'validation_failed'];
            $expectedSha = $response['expected_snapshot_sha256'] ?? null;
            // expected_snapshot_sha256 为空表示调用方没带期望快照，回执指纹退化为请求本身的 sha(definitionId)。
            $fingerprintOk = $expectedSha === null
                ? hash_equals((string) $record['request_fingerprint'], hash('sha256', (string) ($response['agent_definition_id'] ?? 0)))
                : is_string($expectedSha) && preg_match('/^[a-f0-9]{64}$/D', $expectedSha) === 1
                    && hash_equals((string) $record['request_fingerprint'], RequestPayloadFingerprint::fingerprint([
                        'agent_definition_id' => $response['agent_definition_id'] ?? null,
                        'expected_snapshot_sha256' => $expectedSha]));
            if ($operation !== 'publish' || array_diff(array_keys($response), $keys) !== [] || count($response) !== count($keys)
                || !in_array($response['reason'] ?? null, $reasons, true) || !is_int($response['agent_definition_id'] ?? null)
                || $response['agent_definition_id'] < 1 || $response['agent_definition_id'] !== (int) $record['agent_definition_id']
                || ($response['code'] ?? null) !== $code
                || !($expectedSha === null || (is_string($expectedSha) && preg_match('/^[a-f0-9]{64}$/D', $expectedSha) === 1))
                || (int) $record['agent_version_id'] < 1
                || !$fingerprintOk) self::operationMissing();
            return $response;
        }
        $keys = ['agent_definition_id', 'agent_version_id', 'code', 'state'];
        if ($operation !== 'disable') $keys = [...$keys, 'version', 'snapshot_sha256'];
        $expectedState = $operation === 'draft' ? 'draft' : ($operation === 'disable' ? 'disabled' : 'published');
        if (!is_array($response) || array_is_list($response) || array_diff(array_keys($response), $keys) !== []
            || count($response) !== count($keys) || !is_int($response['agent_definition_id'] ?? null) || $response['agent_definition_id'] < 1
            || !is_int($response['agent_version_id'] ?? null) || $response['agent_version_id'] < 1
            || $response['agent_definition_id'] !== (int) $record['agent_definition_id']
            || $response['agent_version_id'] !== (int) $record['agent_version_id'] || ($response['code'] ?? null) !== $code
            || ($response['state'] ?? null) !== $expectedState) self::operationMissing();
        if ($operation !== 'disable' && (!is_int($response['version'] ?? null) || $response['version'] < 1
            || !is_string($response['snapshot_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $response['snapshot_sha256']) !== 1)) self::operationMissing();
        $fingerprint = (string) $record['request_fingerprint'];
        if ($operation === 'disable' && !hash_equals($fingerprint, hash('sha256', (string) $response['agent_definition_id']))) self::operationMissing();
        if ($operation === 'rollback' && !hash_equals($fingerprint, RequestPayloadFingerprint::fingerprint([
            'agent_definition_id' => $response['agent_definition_id'], 'version_no' => $response['version']]))) self::operationMissing();
        if ($operation === 'publish' && !hash_equals($fingerprint, hash('sha256', (string) $response['agent_definition_id']))
            && !hash_equals($fingerprint, RequestPayloadFingerprint::fingerprint(['agent_definition_id' => $response['agent_definition_id'],
                'expected_snapshot_sha256' => $response['snapshot_sha256']]))) self::operationMissing();
        return $response;
    }

    private function operationResourceProof(int $environmentId, string $code, array $record, array $response, string $operation): string
    {
        $rejected = ($response['operation_state'] ?? null) === 'rejected';
        $versionId = $rejected ? (int) $record['agent_version_id'] : $response['agent_version_id'];
        $rows = Db::connect('pgsql')->query('SELECT d.id AS definition_id,d.environment_id,d.code,v.id AS version_id,
            v.agent_definition_id,v.version_no,v.snapshot_sha256,v.state AS version_state,v.published_time
            FROM sand_ai_agent_definition d JOIN sand_ai_agent_version v ON v.agent_definition_id=d.id
            WHERE d.environment_id=? AND d.id=? AND d.code=? AND d.delete_time IS NULL AND v.id=?',
            [$environmentId, $response['agent_definition_id'], $code, $versionId]);
        if (count($rows) !== 1) self::operationMissing();
        $row = $rows[0];
        if ((int) $row['definition_id'] !== $response['agent_definition_id'] || (int) $row['version_id'] !== $versionId
            || (int) $row['agent_definition_id'] !== $response['agent_definition_id'] || (string) $row['code'] !== $code
            || (!$rejected && $operation !== 'disable' && (int) $row['version_no'] !== $response['version'])) self::operationMissing();
        // Published receipts keep addressing their original immutable version, regardless of the later active version or draft.
        if (!$rejected && in_array($operation, ['publish', 'rollback', 'disable'], true) && ((string) $row['version_state'] !== 'published'
            || $row['published_time'] === null || ($operation !== 'disable'
                && (!is_string($row['snapshot_sha256']) || !hash_equals($response['snapshot_sha256'], $row['snapshot_sha256']))))) self::operationMissing();
        return RequestPayloadFingerprint::fingerprint(['environment_id' => $environmentId, 'code' => $code,
            'agent_definition_id' => $response['agent_definition_id'], 'agent_version_id' => $versionId,
            'version_no' => (int) $row['version_no'], 'operation' => $operation, 'created_by' => (string) $record['created_by'],
            ...($operation === 'draft' || $rejected ? [] : ['snapshot_sha256' => (string) $row['snapshot_sha256']])]);
    }

    private static function operationMissing(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent operation request not found');
    }

    /** @return array{data:list<array<string,mixed>>} */
    public function models(int $environmentId): array
    {
        $this->requireEnvironment($environmentId);
        return ['data' => (new ChatGateway())->availableModels($environmentId)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function draft(int $environmentId, array $input, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $this->requireMutation($key, $actor);
        $draft = AgentDefinitionPolicy::draft($input);
        $fingerprint = RequestPayloadFingerprint::fingerprint($draft);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $draft, $key, $actor, $fingerprint): array {
            $this->lock($environmentId . ':agent-operation:draft:' . $key);
            $this->lock($environmentId . ':agent-definition:' . $draft['code']);
            if (($replay = $this->replay($environmentId, 'draft', $key, $fingerprint)) !== null) return $replay;
            $db = Db::connect('pgsql');
            $rows = $db->query('SELECT * FROM sand_ai_agent_definition WHERE environment_id=? AND code=? FOR UPDATE', [$environmentId, $draft['code']]);
            if ($rows === []) {
                $db->execute('INSERT INTO sand_ai_agent_definition (environment_id,code,name,created_by,updated_by) VALUES (?,?,?,?,?)', [$environmentId, $draft['code'], $draft['name'], $actor, $actor]);
                $rows = $db->query('SELECT * FROM sand_ai_agent_definition WHERE environment_id=? AND code=? FOR UPDATE', [$environmentId, $draft['code']]);
            }
            $definitionId = (int) $rows[0]['id'];
            // 已启用智能体允许改名：名称写在 definition 层随草稿保存更新，
            // 随后的 publish 把同名版本启用，不出现草稿态拒绝。
            $existingDraft = $db->query("SELECT * FROM sand_ai_agent_version WHERE agent_definition_id=? AND state='draft' FOR UPDATE", [$definitionId]);
            $config = AgentDefinitionPolicy::config($draft);
            $readonly = (new AgentReadonlyToolCatalog())->freeze($environmentId, $draft['readonly_tools'] ?? []);
            if ($existingDraft === []) {
                $next = (int) $db->query('SELECT COALESCE(MAX(version_no),0)+1 AS next_no FROM sand_ai_agent_version WHERE agent_definition_id=?', [$definitionId])[0]['next_no'];
                $snapshot = AgentDefinitionPolicy::snapshot($draft['code'], $next, $config, $readonly);
                $db->execute('INSERT INTO sand_ai_agent_version (agent_definition_id,version_no,model,instructions,tools,review_required,max_output_tokens,knowledge_base_code,context_turn_limit,readonly_tools,snapshot_sha256,created_by,updated_by) VALUES (?,?,?,?,CAST(? AS jsonb),?,?,?, ?,CAST(? AS jsonb),?,?,?)', [
                    $definitionId, $next, $config['model'], $config['instructions'], json_encode($config['tools'], JSON_THROW_ON_ERROR), $config['review_required'] ? 'true' : 'false', $config['max_output_tokens'], $config['knowledge_base_code'], $config['context_turn_limit'], json_encode($readonly, JSON_THROW_ON_ERROR), $snapshot, $actor, $actor,
                ]);
                $versionId = (int) $db->query("SELECT id FROM sand_ai_agent_version WHERE agent_definition_id=? AND state='draft'", [$definitionId])[0]['id'];
            } else {
                $next = (int) $existingDraft[0]['version_no'];
                $versionId = (int) $existingDraft[0]['id'];
                $snapshot = AgentDefinitionPolicy::snapshot($draft['code'], $next, $config, $readonly);
                $db->execute('UPDATE sand_ai_agent_version SET model=?,instructions=?,tools=CAST(? AS jsonb),review_required=?,max_output_tokens=?,knowledge_base_code=?,context_turn_limit=?,readonly_tools=CAST(? AS jsonb),snapshot_sha256=?,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND state=\'draft\'', [
                    $config['model'], $config['instructions'], json_encode($config['tools'], JSON_THROW_ON_ERROR), $config['review_required'] ? 'true' : 'false', $config['max_output_tokens'], $config['knowledge_base_code'], $config['context_turn_limit'], json_encode($readonly, JSON_THROW_ON_ERROR), $snapshot, $actor, $versionId,
                ]);
            }
            $db->execute('UPDATE sand_ai_agent_definition SET name=?,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?', [$draft['name'], $actor, $definitionId]);
            $result = ['agent_definition_id' => $definitionId, 'agent_version_id' => $versionId, 'code' => $draft['code'], 'version' => $next, 'state' => 'draft', 'snapshot_sha256' => $snapshot];
            $this->record($environmentId, 'draft', $key, $fingerprint, $definitionId, $versionId, $result, $actor);
            $this->audit($actor, 'agent_definition.draft', $definitionId, $next);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function publish(int $environmentId, int $definitionId, string $key, string $actor, ?string $expectedSnapshotSha256 = null, ?\Closure $revalidateAdmin = null): array
    {
        $this->requireEnvironment($environmentId);
        $this->requireMutation($key, $actor);
        if ($definitionId <= 0 || ($expectedSnapshotSha256 !== null
            && preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256) !== 1)) self::invalid();
        $fingerprint = $expectedSnapshotSha256 === null ? hash('sha256', (string) $definitionId)
            : RequestPayloadFingerprint::fingerprint(['agent_definition_id' => $definitionId, 'expected_snapshot_sha256' => $expectedSnapshotSha256]);
        // 拒绝时携带原 ApiProblem 出事务，保证错误码与首次校验语义一致；
        // 幂等重放拿不到它，由 publishRejectionProblem 按回执 reason 还原。
        $problem = null;
        $result = Db::connect('pgsql')->transaction(function () use ($environmentId, $definitionId, $key, $actor, $fingerprint, $expectedSnapshotSha256, $revalidateAdmin, &$problem): array {
            $this->lock($environmentId . ':agent-operation:publish:' . $key);
            if (($replay = $this->replay($environmentId, 'publish', $key, $fingerprint)) !== null) {
                if (($replay['operation_state'] ?? null) === 'rejected' && $revalidateAdmin !== null) $revalidateAdmin();
                return $replay;
            }
            $db = Db::connect('pgsql');
            $definition = $this->definitionById($environmentId, $definitionId, true);
            // 确定性拒绝一律在同一事务里落幂等回执（拒绝也要留回执），
            // by-request-key 才能查到并让前端结清本地操作锁。
            $reject = function (int $versionId, string $reason) use ($environmentId, $definitionId, $definition, $key, $fingerprint, $expectedSnapshotSha256, $actor): array {
                $rejection = ['operation_state' => 'rejected', 'reason' => $reason,
                    'agent_definition_id' => $definitionId, 'code' => (string) $definition['code'],
                    'expected_snapshot_sha256' => $expectedSnapshotSha256];
                $this->record($environmentId, 'publish', $key, $fingerprint, $definitionId, $versionId, $rejection, $actor);
                return $rejection;
            };
            $versions = $db->query("SELECT * FROM sand_ai_agent_version WHERE agent_definition_id=? AND state='draft' FOR UPDATE", [$definitionId]);
            if ($versions === []) {
                // 回执必须锚定真实存在的版本行；没有任何版本的定义无法出具可校验回执，按原样拒绝。
                $anchor = (int) ($definition['active_version_id'] ?? 0);
                if ($anchor < 1) {
                    $latest = $db->query('SELECT id FROM sand_ai_agent_version WHERE agent_definition_id=? ORDER BY version_no DESC LIMIT 1', [$definitionId]);
                    $anchor = $latest === [] ? 0 : (int) $latest[0]['id'];
                }
                if ($anchor < 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent has no draft version');
                $problem = new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent has no draft version');
                return $reject($anchor, 'no_draft_version');
            }
            $version = $versions[0];
            if ($expectedSnapshotSha256 !== null && (!is_string($version['snapshot_sha256'])
                || !hash_equals($expectedSnapshotSha256, $version['snapshot_sha256']))) {
                // Only a confirmed mismatch with a current authenticated administrator gets a durable rejection.
                if (!is_string($version['snapshot_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $version['snapshot_sha256']) !== 1
                    || (int) $version['id'] < 1 || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', (string) $definition['code']) !== 1) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent draft snapshot is unavailable');
                }
                if ($revalidateAdmin === null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent draft changed; refresh its version before publishing');
                $revalidateAdmin();
                $this->requireEnvironment($environmentId);
                $problem = new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent draft changed; refresh its version before publishing');
                $revalidateAdmin();
                return $reject((int) $version['id'], 'configuration_changed');
            }
            try {
                $config = AgentDefinitionPolicy::publishedConfig((string) $definition['code'], $version);
                (new AgentReadonlyToolCatalog())->assertCurrent($environmentId, AgentDefinitionPolicy::readonlyBindings($version));
                $publication = $config['knowledge_base_code'] === null ? null
                    : (new \app\Application\Knowledge\KnowledgePublicationGateway())
                        ->findForConsumer($environmentId, $config['knowledge_base_code']);
                if ($publication !== null && (int) $publication['owner_environment_id'] !== $environmentId) {
                    $problem = new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent knowledge must belong to this environment');
                    return $reject((int) $version['id'], 'knowledge_environment_mismatch');
                }
                $models = (new ChatGateway())->availableModels($environmentId);
                if (!in_array($config['model'], array_column($models, 'code'), true)) {
                    $problem = new ApiProblem('SAND_AI_MODEL_UNAVAILABLE', 'Agent model is not available in this environment');
                    return $reject((int) $version['id'], 'model_unavailable');
                }
            } catch (ApiProblem $failure) {
                // retryable 的失败结果未确认，不写拒绝回执，前端保留本地操作锁。
                if ($failure->retryable) throw $failure;
                $problem = $failure;
                return $reject((int) $version['id'], 'validation_failed');
            }
            $db->execute("UPDATE sand_ai_agent_version SET state='published',published_time=CURRENT_TIMESTAMP(0),updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?", [$actor, (int) $version['id']]);
            $db->execute("UPDATE sand_ai_agent_definition SET active_version_id=?,state='published',status=1,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?", [(int) $version['id'], $actor, $definitionId]);
            $result = ['agent_definition_id' => $definitionId, 'agent_version_id' => (int) $version['id'], 'code' => $definition['code'], 'version' => (int) $version['version_no'], 'state' => 'published', 'snapshot_sha256' => $version['snapshot_sha256']];
            $this->record($environmentId, 'publish', $key, $fingerprint, $definitionId, (int) $version['id'], $result, $actor);
            $this->audit($actor, 'agent_definition.publish', $definitionId, (int) $version['version_no']);
            return $result;
        });
        // The rejection transaction has committed; both first request and replay keep the conflict envelope.
        if (($result['operation_state'] ?? null) === 'rejected') {
            throw $problem ?? self::publishRejectionProblem((string) ($result['reason'] ?? ''));
        }
        return $result;
    }

    /** Replayed rejection receipts rebuild the problem the first request threw. */
    private static function publishRejectionProblem(string $reason): ApiProblem
    {
        return match ($reason) {
            'no_draft_version' => new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent has no draft version'),
            'model_unavailable' => new ApiProblem('SAND_AI_MODEL_UNAVAILABLE', 'Agent model is not available in this environment'),
            'knowledge_environment_mismatch' => new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent knowledge must belong to this environment'),
            'validation_failed' => new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent draft validation failed'),
            default => new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent draft changed; refresh its version before publishing'),
        };
    }

    /** @return array<string,mixed> */
    public function disable(int $environmentId, int $definitionId, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $this->requireMutation($key, $actor);
        if ($definitionId <= 0) self::invalid();
        $fingerprint = hash('sha256', (string) $definitionId);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $definitionId, $key, $actor, $fingerprint): array {
            $this->lock($environmentId . ':agent-operation:disable:' . $key);
            if (($replay = $this->replay($environmentId, 'disable', $key, $fingerprint)) !== null) return $replay;
            $definition = $this->definitionById($environmentId, $definitionId, true);
            if ((string) $definition['state'] !== 'published' || (int) $definition['status'] !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent is not active');
            Db::connect('pgsql')->execute('UPDATE sand_ai_agent_definition SET status=2,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?', [$actor, $definitionId]);
            $result = ['agent_definition_id' => $definitionId, 'agent_version_id' => (int) $definition['active_version_id'], 'code' => $definition['code'], 'state' => 'disabled'];
            $this->record($environmentId, 'disable', $key, $fingerprint, $definitionId, (int) $definition['active_version_id'], $result, $actor);
            $this->audit($actor, 'agent_definition.disable', $definitionId, 0);
            return $result;
        });
    }

    /**
     * 删除已停用的智能体。仍有渠道绑定时拒绝，调用方用渠道数量写明原因。
     * @return array{agent_definition_id:int,state:string,channel_count:int}
     */
    public function retire(int $environmentId, int $definitionId, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        if ($definitionId <= 0 || trim($actor) === '') self::invalid();
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $definitionId, $actor): array {
            $this->lock($environmentId . ':agent-definition:retire:' . $definitionId);
            $definition = $this->definitionById($environmentId, $definitionId, true);
            $channels = Db::connect('pgsql')->query(
                'SELECT count(*) AS total FROM sand_ai_channel_binding WHERE environment_id=? AND agent_definition_id=? AND delete_time IS NULL',
                [$environmentId, $definitionId]
            );
            $channelCount = (int) ($channels[0]['total'] ?? 0);
            if ((int) $definition['status'] === 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Disable the agent before deleting it');
            }
            if ($channelCount > 0) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent is still used by ' . $channelCount . ' channels');
            }
            Db::connect('pgsql')->execute(
                'UPDATE sand_ai_agent_definition SET delete_time=CURRENT_TIMESTAMP(0), updated_by=?, update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND delete_time IS NULL',
                [$actor, $definitionId]
            );
            $this->audit($actor, 'agent_definition.retire', $definitionId, 0);
            return ['agent_definition_id' => $definitionId, 'state' => 'deleted', 'channel_count' => 0];
        });
    }

    /** Safe configuration history. Published rows are immutable; drafts remain clearly marked. */
    public function versions(int $environmentId, string $code, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1 || $page < 1 || $limit < 1 || $limit > 100) self::invalid();
        $db = Db::connect('pgsql');
        $rows = $db->query('SELECT * FROM sand_ai_agent_definition WHERE environment_id=? AND code=? AND delete_time IS NULL', [$environmentId, $code]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent not found');
        $definition = $rows[0];
        $total = $db->query('SELECT count(*) AS total FROM sand_ai_agent_version WHERE agent_definition_id=?', [(int) $definition['id']]);
        $versions = $db->query('SELECT * FROM sand_ai_agent_version WHERE agent_definition_id=? ORDER BY version_no DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), [(int) $definition['id']]);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) $total[0]['total'],
            'data' => array_map(static fn (array $version): array => [
                'version_no' => (int) $version['version_no'], 'state' => (string) $version['state'],
                'snapshot_sha256' => (string) $version['snapshot_sha256'], 'created_at' => $version['create_time'],
                'is_active' => (int) $definition['active_version_id'] === (int) $version['id'],
                'config' => AgentDefinitionPolicy::publishedConfig($code, $version),
            ], $versions)];
    }

    public function rollback(int $environmentId, int $definitionId, int $versionNo, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $this->requireMutation($key, $actor);
        if ($definitionId < 1 || $versionNo < 1) self::invalid();
        $fingerprint = RequestPayloadFingerprint::fingerprint(['agent_definition_id' => $definitionId, 'version_no' => $versionNo]);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $definitionId, $versionNo, $key, $actor, $fingerprint): array {
            $this->lock($environmentId . ':agent-operation:rollback:' . $key);
            $definition = $this->definitionById($environmentId, $definitionId, true);
            if ((string) $definition['state'] !== 'published' || (int) $definition['status'] !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only an active published agent can roll back configuration');
            }
            if (($replay = $this->replay($environmentId, 'rollback', $key, $fingerprint)) !== null) return $replay;
            $db = Db::connect('pgsql');
            $versions = $db->query('SELECT * FROM sand_ai_agent_version WHERE agent_definition_id=? AND version_no=? FOR UPDATE', [$definitionId, $versionNo]);
            if ($versions === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent version not found');
            $version = $versions[0];
            if ($version['state'] !== 'published' || $version['published_time'] === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Agent configuration was never published');
            }
            $config = AgentDefinitionPolicy::publishedConfig((string) $definition['code'], $version);
            (new AgentReadonlyToolCatalog())->assertCurrent($environmentId, AgentDefinitionPolicy::readonlyBindings($version));
            if (!in_array($config['model'], array_column((new ChatGateway())->availableModels($environmentId), 'code'), true)) {
                throw new ApiProblem('SAND_AI_MODEL_UNAVAILABLE', 'Agent model is not available in this environment');
            }
            $publication = $config['knowledge_base_code'] === null ? null
                : (new \app\Application\Knowledge\KnowledgePublicationGateway())->findForConsumer($environmentId, $config['knowledge_base_code']);
            if ($publication !== null && (int) $publication['owner_environment_id'] !== $environmentId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent knowledge belongs to another environment');
            }
            $db->execute('UPDATE sand_ai_agent_definition SET active_version_id=?,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND environment_id=? AND status=1', [(int) $version['id'], $actor, $definitionId, $environmentId]);
            $result = ['agent_definition_id' => $definitionId, 'agent_version_id' => (int) $version['id'],
                'code' => $definition['code'], 'version' => $versionNo, 'state' => 'published', 'snapshot_sha256' => $version['snapshot_sha256']];
            $this->record($environmentId, 'rollback', $key, $fingerprint, $definitionId, (int) $version['id'], $result, $actor);
            $this->audit($actor, 'agent_definition.rollback', $definitionId, $versionNo);
            return $result;
        });
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function list(int $environmentId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($page < 1 || $limit < 1 || $limit > 100) self::invalid();
        $db = Db::connect('pgsql');
        $total = $db->query('SELECT count(*) AS total FROM sand_ai_agent_definition WHERE environment_id=? AND delete_time IS NULL', [$environmentId]);
        $rows = $db->query("SELECT d.id,d.code,d.name,d.state,d.status,d.active_version_id,a.version_no AS active_version_no,v.id AS draft_version_id,v.version_no AS draft_version_no FROM sand_ai_agent_definition d LEFT JOIN sand_ai_agent_version a ON a.id=d.active_version_id LEFT JOIN sand_ai_agent_version v ON v.agent_definition_id=d.id AND v.state='draft' WHERE d.environment_id=? AND d.delete_time IS NULL ORDER BY d.id DESC LIMIT " . $limit . ' OFFSET ' . (($page - 1) * $limit), [$environmentId]);
        $items = array_map(static fn (array $row): array => [
            'agent_definition_id' => (int) $row['id'], 'code' => (string) $row['code'],
            'name' => (string) $row['name'], 'state' => (string) $row['state'],
            'status' => (int) $row['status'],
            'active_version_id' => $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
            'active_version_no' => $row['active_version_no'] === null ? null : (int) $row['active_version_no'],
            'draft_version_id' => $row['draft_version_id'] === null ? null : (int) $row['draft_version_id'],
            'draft_version_no' => $row['draft_version_no'] === null ? null : (int) $row['draft_version_no'],
        ], $rows);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array<string,mixed> */
    public function read(int $environmentId, string $code): array
    {
        $this->requireEnvironment($environmentId);
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) self::invalid();
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_agent_definition WHERE environment_id=? AND code=? AND delete_time IS NULL', [$environmentId, $code]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent not found');
        $versions = Db::connect('pgsql')->query('SELECT * FROM sand_ai_agent_version WHERE agent_definition_id=? ORDER BY version_no DESC LIMIT 1', [(int) $rows[0]['id']]);
        $latest = null;
        if ($versions !== []) {
            $config = AgentDefinitionPolicy::publishedConfig($code, $versions[0]);
            $latest = ['id' => (int) $versions[0]['id'], 'version' => (int) $versions[0]['version_no'],
                'state' => (string) $versions[0]['state'], ...$config];
        }
        return ['agent_definition_id' => (int) $rows[0]['id'], 'code' => $code,
            'name' => (string) $rows[0]['name'], 'state' => (string) $rows[0]['state'],
            'status' => (int) $rows[0]['status'],
            'active_version_id' => $rows[0]['active_version_id'] === null ? null : (int) $rows[0]['active_version_id'],
            'latest_version' => $latest];
    }

    /** @return array{binding:array<string,mixed>,config:array<string,mixed>} */
    public function resolve(int $environmentId, string $code): array
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) self::invalid();
        $rows = Db::connect('pgsql')->query("SELECT d.id AS definition_id,d.code,d.active_version_id,v.* FROM sand_ai_agent_definition d JOIN sand_ai_agent_version v ON v.id=d.active_version_id WHERE d.environment_id=? AND d.code=? AND d.state='published' AND d.status=1 AND d.delete_time IS NULL AND v.state='published'", [$environmentId, $code]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent is unavailable');
        $row = $rows[0];
        $config = AgentDefinitionPolicy::publishedConfig($code, $row);
        $readonly = AgentDefinitionPolicy::readonlyBindings($row);
        (new AgentReadonlyToolCatalog())->assertCurrent($environmentId, $readonly);
        $knowledgeCode = $config['knowledge_base_code'];
        $publication = $knowledgeCode === null ? null
            : (new \app\Application\Knowledge\KnowledgePublicationGateway())
                ->findForConsumer($environmentId, $knowledgeCode);
        if ($publication !== null && (int) $publication['owner_environment_id'] !== $environmentId) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent knowledge belongs to another environment');
        }
        return ['binding' => [
            ...($readonly === [] ? [] : ['readonly_tool_bindings' => $readonly]),
            'definition_id' => (int) $row['definition_id'],
            'version_id' => (int) $row['id'],
            'code' => $code,
            'snapshot_sha256' => (string) $row['snapshot_sha256'],
            'knowledge_publication_id' => $publication === null ? null : (int) $publication['id'],
            'knowledge_version_id' => $publication === null ? null : (int) $publication['active_version_id'],
            'knowledge_scope_sha256' => $publication === null ? null : (string) $publication['source_scope_sha256'],
        ], 'config' => $config];
    }

    /** @param mixed $binding */
    public function assertCurrent(int $environmentId, mixed $binding): void
    {
        if (!is_array($binding) || !is_int($binding['definition_id'] ?? null) || !is_int($binding['version_id'] ?? null)
            || !is_string($binding['code'] ?? null) || !is_string($binding['snapshot_sha256'] ?? null)
            || !self::nullableId($binding['knowledge_publication_id'] ?? null)
            || !self::nullableId($binding['knowledge_version_id'] ?? null)
            || !self::nullableHash($binding['knowledge_scope_sha256'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent binding is invalid');
        }
        $current = $this->resolve($environmentId, $binding['code']);
        if (\app\Application\Tool\ToolFingerprint::for('agent_readonly_catalog', ['bindings' => $binding['readonly_tool_bindings'] ?? []]) !== \app\Application\Tool\ToolFingerprint::for('agent_readonly_catalog', ['bindings' => $current['binding']['readonly_tool_bindings'] ?? []])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly tool bindings changed');
        }
        foreach (['definition_id', 'version_id', 'code', 'snapshot_sha256',
            'knowledge_publication_id', 'knowledge_version_id', 'knowledge_scope_sha256'] as $field) {
            if ($current['binding'][$field] !== $binding[$field]) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent or knowledge version is no longer active');
            }
        }
    }

    /** Agents published without a knowledge base carry a null publication id. */
    private static function nullableId(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 1);
    }

    /** Agents published without a knowledge base carry a null scope hash. */
    private static function nullableHash(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1);
    }

    private function definitionById(int $environmentId, int $id, bool $lock): array
    {
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_agent_definition WHERE environment_id=? AND id=? AND delete_time IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$environmentId, $id]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent not found');
        return $rows[0];
    }

    private function replay(int $environmentId, string $operation, string $key, string $fingerprint): ?array
    {
        $rows = Db::connect('pgsql')->query('SELECT request_fingerprint,response_snapshot FROM sand_ai_agent_operation WHERE environment_id=? AND operation_code=? AND idempotency_key=? FOR UPDATE', [$environmentId, $operation, $key]);
        if ($rows === []) return null;
        if (!hash_equals((string) $rows[0]['request_fingerprint'], $fingerprint)) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request');
        $response = json_decode((string) $rows[0]['response_snapshot'], true, 16, JSON_THROW_ON_ERROR);
        return [...$response, 'idempotent_replay' => true];
    }

    private function record(int $environmentId, string $operation, string $key, string $fingerprint, int $definitionId, ?int $versionId, array $result, string $actor): void
    {
        Db::connect('pgsql')->execute('INSERT INTO sand_ai_agent_operation (environment_id,operation_code,idempotency_key,request_fingerprint,agent_definition_id,agent_version_id,response_snapshot,created_by) VALUES (?,?,?,?,?,?,CAST(? AS jsonb),?)', [$environmentId, $operation, $key, $fingerprint, $definitionId, $versionId, json_encode($result, JSON_THROW_ON_ERROR), $actor]);
    }

    private function audit(string $actor, string $action, int $definitionId, int $version): void
    {
        AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actor, 'action' => $action,
            'resource_type' => 'agent_definition', 'resource_id' => $definitionId,
            'summary' => $action, 'context' => ['version' => $version]]);
    }

    private function lock(string $key): void { Db::connect('pgsql')->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]); }
    private function requireEnvironment(int $id): void { if ($id <= 0) self::invalid(); try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($id); } catch (IdentityContextException $e) { throw IdentityContextProblem::from($e); } }
    private function requireMutation(string $key, string $actor): void { if (trim($key) === '' || strlen($key) > 128 || trim($actor) === '' || strlen($actor) > 64) self::invalid(); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent definition request is invalid'); }
}
