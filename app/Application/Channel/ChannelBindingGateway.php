<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Identity\Infrastructure\HostSandIamRuntime;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;
use Throwable;

final class ChannelBindingGateway
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $environmentId, array $input, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $this->mutation($key, $actor);
        $input = ChannelBindingPolicy::configuration($input);
        $this->verifyOwner($environmentId, $input);
        $fingerprint = RequestPayloadFingerprint::fingerprint($input);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $input, $key, $actor, $fingerprint): array {
            $this->lock($environmentId . ':channel-operation:save:' . $key);
            $this->lock($environmentId . ':channel-binding:' . $input['code']);
            if (($replay = $this->replay($environmentId, 'save', $key, $fingerprint, $actor)) !== null) return $replay;
            $db = Db::connect('pgsql');
            $agent = $db->query("SELECT id,code FROM sand_ai_agent_definition WHERE id=? AND environment_id=? AND state='published' AND status=1 AND delete_time IS NULL", [$input['agent_definition_id'], $environmentId]);
            if ($agent === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published agent not found');
            $definition = (new AgentDefinitionGateway())->resolve($environmentId, (string) $agent[0]['code']);
            if ($definition['binding']['definition_id'] !== $input['agent_definition_id']) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent changed');
            $rows = $db->query('SELECT * FROM sand_ai_channel_binding WHERE environment_id=? AND code=? FOR UPDATE', [$environmentId, $input['code']]);
            if ($rows === []) {
                $db->execute('INSERT INTO sand_ai_channel_binding (environment_id,code,name,agent_definition_id,application_id,workload_client_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)', [$environmentId, $input['code'], $input['name'], $input['agent_definition_id'], $input['application_id'], $input['workload_client_id'], $actor, $actor]);
                $rows = $db->query('SELECT * FROM sand_ai_channel_binding WHERE environment_id=? AND code=? FOR UPDATE', [$environmentId, $input['code']]);
            } else {
                if ($rows[0]['delete_time'] !== null) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deleted channel code is reserved');
                $rebinding = (int) $rows[0]['agent_definition_id'] !== $input['agent_definition_id']
                    || (string) $rows[0]['application_id'] !== $input['application_id']
                    || (string) $rows[0]['workload_client_id'] !== $input['workload_client_id']
                    || (int) $rows[0]['status'] !== 1;
                $db->execute('UPDATE sand_ai_channel_binding SET name=?,agent_definition_id=?,application_id=?,workload_client_id=?,revision=revision+?,status=1,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?', [$input['name'], $input['agent_definition_id'], $input['application_id'], $input['workload_client_id'], $rebinding ? 1 : 0, $actor, (int) $rows[0]['id']]);
                $rows = $db->query('SELECT * FROM sand_ai_channel_binding WHERE id=?', [(int) $rows[0]['id']]);
            }
            $result = $this->summary($rows[0]);
            $this->record($environmentId, 'save', $key, $fingerprint, (int) $rows[0]['id'], $result, $actor);
            $this->audit($actor, 'channel_binding.save', (int) $rows[0]['id'], (int) $rows[0]['revision']);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function disable(int $environmentId, int $id, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $this->mutation($key, $actor);
        if ($id <= 0) self::invalid();
        $fingerprint = hash('sha256', (string) $id);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $id, $key, $actor, $fingerprint): array {
            $this->lock($environmentId . ':channel-operation:disable:' . $key);
            if (($replay = $this->replay($environmentId, 'disable', $key, $fingerprint, $actor)) !== null) return $replay;
            $db = Db::connect('pgsql');
            $rows = $db->query('SELECT * FROM sand_ai_channel_binding WHERE environment_id=? AND id=? AND delete_time IS NULL FOR UPDATE', [$environmentId, $id]);
            if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel not found');
            if ((int) $rows[0]['status'] !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Channel is already disabled');
            $db->execute('UPDATE sand_ai_channel_binding SET status=2,revision=revision+1,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?', [$actor, $id]);
            $rows = $db->query('SELECT * FROM sand_ai_channel_binding WHERE id=?', [$id]);
            $result = $this->summary($rows[0]);
            $this->record($environmentId, 'disable', $key, $fingerprint, $id, $result, $actor);
            $this->audit($actor, 'channel_binding.disable', $id, (int) $rows[0]['revision']);
            return $result;
        });
    }

    /** Reads the existing immutable operation proof; never repeats a binding mutation.
     * @param callable():void $revalidateAdmin @return array<string,mixed>
     */
    public function readOperation(int $environmentId, string $operation, string $key, string $fingerprint, string|int $target, string $actor, callable $revalidateAdmin): array
    {
        $this->requireEnvironment($environmentId);
        $this->mutation($key, $actor);
        if (!in_array($operation, ['save', 'disable'], true) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $key) === 1) self::invalid();
        if ($operation === 'save') {
            if (!is_string($target)) self::invalid();
            ChannelBindingPolicy::code($target);
            $fixedTarget = ['code' => $target];
        } else {
            if (!is_int($target) || $target <= 0) self::invalid();
            $fixedTarget = ['channel_binding_id' => $target];
        }
        $revalidateAdmin();
        $result = ['operation' => $operation, 'environment_id' => $environmentId, 'request_key' => $key,
            'input_sha256' => $fingerprint, 'target' => $fixedTarget, 'state' => 'unknown', 'binding' => null];
        $rows = Db::connect('pgsql')->query('SELECT request_fingerprint,response_snapshot,channel_binding_id,created_by
            FROM sand_ai_channel_operation WHERE environment_id=? AND operation_code=? AND idempotency_key=?', [$environmentId, $operation, $key]);
        if ($rows !== [] && hash_equals((string) $rows[0]['created_by'], $actor)) {
            $row = $rows[0];
            if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original channel request fingerprint differs');
            }
            try { $snapshot = json_decode((string) $row['response_snapshot'], true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original channel proof is invalid'); }
            if (!is_array($snapshot) || !is_int($snapshot['channel_binding_id'] ?? null)
                || $snapshot['channel_binding_id'] !== (int) $row['channel_binding_id']
                || ($snapshot['environment_id'] ?? null) !== $environmentId
                || !is_int($snapshot['revision'] ?? null) || $snapshot['revision'] <= 0
                || !in_array($snapshot['status'] ?? null, [1, 2], true)
                || !is_bool($snapshot['feishu_configured'] ?? null) || !is_bool($snapshot['wecom_configured'] ?? null)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original channel proof is invalid');
            }
            $configuration = ChannelBindingPolicy::configuration(array_intersect_key($snapshot, array_flip(['code', 'name', 'agent_definition_id', 'application_id', 'workload_client_id'])));
            if (($operation === 'save' && ($configuration['code'] !== $target || $snapshot['status'] !== 1
                    || !hash_equals(RequestPayloadFingerprint::fingerprint($configuration), $fingerprint)))
                || ($operation === 'disable' && ($snapshot['channel_binding_id'] !== $target || $snapshot['status'] !== 2
                    || !hash_equals(hash('sha256', (string) $target), $fingerprint)))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original channel target proof differs');
            }
            $current = Db::connect('pgsql')->query('SELECT id FROM sand_ai_channel_binding
                WHERE id=? AND environment_id=? AND code=? AND delete_time IS NULL', [$snapshot['channel_binding_id'], $environmentId, $configuration['code']]);
            if ($current === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel is unavailable');
            $result['state'] = 'completed';
            // Only the original public summary is returned; later revisions are not substituted.
            $result['binding'] = array_intersect_key($snapshot, array_flip(['channel_binding_id', 'environment_id', 'code', 'name',
                'agent_definition_id', 'application_id', 'workload_client_id', 'revision', 'status', 'feishu_configured', 'wecom_configured']));
        }
        $this->requireEnvironment($environmentId);
        $revalidateAdmin();
        return $result;
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function list(int $environmentId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($page < 1 || $limit < 1 || $limit > 100) self::invalid();
        $db = Db::connect('pgsql');
        $total = $db->query('SELECT count(*) AS total FROM sand_ai_channel_binding WHERE environment_id=? AND delete_time IS NULL', [$environmentId]);
        $rows = $db->query('SELECT c.*,d.name AS agent_name,d.code AS agent_code,
            (f.channel_binding_id IS NOT NULL) AS feishu_configured,
            (w.channel_binding_id IS NOT NULL) AS wecom_configured
            FROM sand_ai_channel_binding c JOIN sand_ai_agent_definition d ON d.id=c.agent_definition_id
            LEFT JOIN sand_ai_feishu_channel_credential f ON f.channel_binding_id=c.id
            LEFT JOIN sand_ai_wecom_channel_credential w ON w.channel_binding_id=c.id
            WHERE c.environment_id=? AND c.delete_time IS NULL ORDER BY c.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), [$environmentId]);
        $items = array_map(function (array $row): array {
            return [...$this->summary($row), 'agent_name' => (string) $row['agent_name'], 'agent_code' => (string) $row['agent_code']];
        }, $rows);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0), 'data' => $items];
    }

    /** @return array<string,mixed> */
    public function read(int $environmentId, string $code): array
    {
        $this->requireEnvironment($environmentId);
        ChannelBindingPolicy::code($code);
        $rows = Db::connect('pgsql')->query('SELECT c.*,(f.channel_binding_id IS NOT NULL) AS feishu_configured,
            (w.channel_binding_id IS NOT NULL) AS wecom_configured
            FROM sand_ai_channel_binding c LEFT JOIN sand_ai_feishu_channel_credential f ON f.channel_binding_id=c.id
            LEFT JOIN sand_ai_wecom_channel_credential w ON w.channel_binding_id=c.id
            WHERE c.environment_id=? AND c.code=? AND c.delete_time IS NULL', [$environmentId, $code]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel not found');
        return $this->summary($rows[0]);
    }

    /** @return array{binding:array<string,mixed>,agent:array<string,mixed>} */
    public function resolve(int $environmentId, string $code, IdentityContext $identity): array
    {
        ChannelBindingPolicy::code($code);
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_channel_binding WHERE environment_id=? AND code=? AND status=1 AND delete_time IS NULL', [$environmentId, $code]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel is unavailable');
        $row = $rows[0];
        if ((string) $row['application_id'] !== $identity->applicationId || (string) $row['workload_client_id'] !== $identity->workloadClientId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel is unavailable');
        }
        $agentRows = Db::connect('pgsql')->query('SELECT code FROM sand_ai_agent_definition WHERE environment_id=? AND id=?', [$environmentId, (int) $row['agent_definition_id']]);
        if ($agentRows === []) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel agent is unavailable');
        $agent = (new AgentDefinitionGateway())->resolve($environmentId, (string) $agentRows[0]['code']);
        if ($agent['binding']['definition_id'] !== (int) $row['agent_definition_id']) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel agent changed');
        return ['binding' => ['channel_binding_id' => (int) $row['id'], 'code' => $code, 'revision' => (int) $row['revision'], 'agent_definition_id' => (int) $row['agent_definition_id']], 'agent' => $agent];
    }

    /** @param mixed $binding */
    public function assertCurrent(int $environmentId, mixed $binding): void
    {
        if (!is_array($binding) || !is_int($binding['channel_binding_id'] ?? null) || !is_int($binding['revision'] ?? null)
            || !is_int($binding['agent_definition_id'] ?? null) || !is_string($binding['code'] ?? null)
            || !is_string($binding['conversation_scope'] ?? null) || !is_string($binding['message_scope'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $binding['conversation_scope']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $binding['message_scope']) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel binding is invalid');
        }
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_channel_binding WHERE environment_id=? AND id=? AND code=? AND status=1 AND delete_time IS NULL', [$environmentId, $binding['channel_binding_id'], $binding['code']]);
        if ($rows === [] || (int) $rows[0]['revision'] !== $binding['revision'] || (int) $rows[0]['agent_definition_id'] !== $binding['agent_definition_id']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel binding is no longer active');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function summary(array $row): array
    {
        $configured = $row['feishu_configured'] ?? null;
        if ($configured === null) {
            $found = Db::connect('pgsql')->query('SELECT 1 FROM sand_ai_feishu_channel_credential WHERE channel_binding_id=?', [(int) $row['id']]);
            $configured = $found !== [];
        }
        $wecomConfigured = $row['wecom_configured'] ?? null;
        if ($wecomConfigured === null) {
            $found = Db::connect('pgsql')->query('SELECT 1 FROM sand_ai_wecom_channel_credential WHERE channel_binding_id=?', [(int) $row['id']]);
            $wecomConfigured = $found !== [];
        }
        return ['channel_binding_id' => (int) $row['id'], 'environment_id' => (int) $row['environment_id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'agent_definition_id' => (int) $row['agent_definition_id'], 'application_id' => (string) $row['application_id'], 'workload_client_id' => (string) $row['workload_client_id'], 'revision' => (int) $row['revision'], 'status' => (int) $row['status'], 'feishu_configured' => $configured === true || $configured === 1 || $configured === '1' || $configured === 't', 'wecom_configured' => $wecomConfigured === true || $wecomConfigured === 1 || $wecomConfigured === '1' || $wecomConfigured === 't'];
    }

    private function replay(int $environmentId, string $operation, string $key, string $fingerprint, string $actor): ?array
    {
        $rows = Db::connect('pgsql')->query('SELECT request_fingerprint,response_snapshot,created_by FROM sand_ai_channel_operation WHERE environment_id=? AND operation_code=? AND idempotency_key=? FOR UPDATE', [$environmentId, $operation, $key]);
        if ($rows === []) return null;
        if (!hash_equals((string) $rows[0]['created_by'], $actor)) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel operation not found');
        if (!hash_equals((string) $rows[0]['request_fingerprint'], $fingerprint)) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request');
        return [...json_decode((string) $rows[0]['response_snapshot'], true, 16, JSON_THROW_ON_ERROR), 'idempotent_replay' => true];
    }

    private function record(int $environmentId, string $operation, string $key, string $fingerprint, int $id, array $result, string $actor): void
    {
        Db::connect('pgsql')->execute('INSERT INTO sand_ai_channel_operation (environment_id,operation_code,idempotency_key,request_fingerprint,channel_binding_id,response_snapshot,created_by) VALUES (?,?,?,?,?,CAST(? AS jsonb),?)', [$environmentId, $operation, $key, $fingerprint, $id, json_encode($result, JSON_THROW_ON_ERROR), $actor]);
    }

    private function audit(string $actor, string $action, int $id, int $revision): void
    {
        AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actor, 'action' => $action, 'resource_type' => 'channel_binding', 'resource_id' => $id, 'summary' => $action, 'context' => ['revision' => $revision]]);
    }

    /** @param array<string,mixed> $input */
    private function verifyOwner(int $environmentId, array $input): void
    {
        try {
            $environment = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
            $clientId = filter_var($input['workload_client_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $host = HostSandIamRuntime::workloadClientReferenceVerifier();
            if ($clientId === false || $host === null || !method_exists($host, 'verifyForService')) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Channel workload client is unavailable');
            }
            try {
                $client = $host->verifyForService((int) $clientId);
            } catch (Throwable) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Channel workload client is unavailable');
            }
            if (!is_array($client) || ($client['status'] ?? null) !== 1
                || (string) ($client['workload_client_id'] ?? '') !== $input['workload_client_id']
                || (string) ($client['application_id'] ?? '') !== $input['application_id']
                || ($client['environment_id'] ?? null) !== $environmentId
                || ($client['organization_id'] ?? null) !== $environment['organization_id']
                || (string) $environment['application_id'] !== $input['application_id']) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Channel workload ownership changed');
            }
        } catch (IdentityContextException $e) {
            throw IdentityContextProblem::from($e);
        }
    }

    private function lock(string $key): void { Db::connect('pgsql')->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]); }
    private function requireEnvironment(int $id): void { if ($id <= 0) self::invalid(); try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($id); } catch (IdentityContextException $e) { throw IdentityContextProblem::from($e); } }
    private function mutation(string $key, string $actor): void { if (trim($key) === '' || strlen($key) > 128 || trim($actor) === '' || strlen($actor) > 64) self::invalid(); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel binding request is invalid'); }
}
