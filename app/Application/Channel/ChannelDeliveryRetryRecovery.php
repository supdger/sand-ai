<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Agent\AgentGateway;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskInputAccessGuard;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** A read of a committed admin retry, scoped to its original outbox, task and creator. */
final class ChannelDeliveryRetryRecovery
{
    /** @return array<string,mixed> */
    public function read(string $platform, int $environmentId, int $bindingId, int $taskId, string $key,
        string $actor, \Closure $revalidateAdmin): array
    {
        if (!in_array($platform, ['feishu', 'wecom'], true) || $environmentId < 1 || $bindingId < 1 || $taskId < 1
            || trim($key) === '' || strlen($key) > 128 || trim($actor) === '' || strlen($actor) > 64) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Delivery retry lookup is invalid');
        }
        $revalidateAdmin();
        $operationCode = $platform === 'wecom' ? 'retry_wecom_delivery' : 'retry_delivery';
        $fingerprint = hash('sha256', ($platform === 'wecom' ? 'wecom:' : '') . $bindingId . ':' . $taskId);
        $operation = $this->operation($environmentId, $bindingId, $operationCode, $key, $actor, $fingerprint);
        $result = $this->snapshot($operation['response_snapshot'], $platform, $taskId);
        $proof = $this->resourceProof($platform, $environmentId, $bindingId, $taskId, $result);
        $revalidateAdmin();
        $current = $this->operation($environmentId, $bindingId, $operationCode, $key, $actor, $fingerprint);
        if (!RequestPayloadFingerprint::same($operation, $current)
            || !hash_equals($proof, $this->resourceProof($platform, $environmentId, $bindingId, $taskId, $result))) self::missing();
        $revalidateAdmin();
        return [...$result, 'idempotent_replay' => true];
    }

    /** @return array<string,mixed> */
    private function operation(int $environmentId, int $bindingId, string $code, string $key, string $actor, string $fingerprint): array
    {
        $rows = Db::connect('pgsql')->query('SELECT environment_id,operation_code,idempotency_key,request_fingerprint,
            channel_binding_id,response_snapshot,created_by FROM sand_ai_channel_operation
            WHERE environment_id=? AND operation_code=? AND idempotency_key=?', [$environmentId, $code, $key]);
        if (count($rows) !== 1) self::missing();
        $row = $rows[0];
        if ((int) $row['environment_id'] !== $environmentId || (int) $row['channel_binding_id'] !== $bindingId
            || (string) $row['operation_code'] !== $code || (string) $row['idempotency_key'] !== $key
            || (string) $row['created_by'] !== $actor || !is_string($row['request_fingerprint'])
            || !hash_equals($fingerprint, $row['request_fingerprint'])) self::missing();
        return $row;
    }

    /** @return array<string,mixed> */
    private function snapshot(mixed $stored, string $platform, int $taskId): array
    {
        try { $result = is_string($stored) ? json_decode($stored, true, 16, JSON_THROW_ON_ERROR) : $stored; }
        catch (\JsonException) { self::missing(); }
        $keys = $platform === 'wecom' ? ['task_id', 'state', 'confirmed_parts'] : ['task_id', 'state'];
        if (!is_array($result) || array_is_list($result) || array_diff(array_keys($result), $keys) !== []
            || count($result) !== count($keys) || !is_int($result['task_id'] ?? null) || $result['task_id'] !== $taskId
            || ($result['state'] ?? null) !== 'pending'
            || ($platform === 'wecom' && (!is_int($result['confirmed_parts'] ?? null) || $result['confirmed_parts'] < 0))) self::missing();
        return $result;
    }

    private function resourceProof(string $platform, int $environmentId, int $bindingId, int $taskId, array $snapshot): string
    {
        try { $environment = (new HostSandIamEnvironmentFacts())->resolve($environmentId); }
        catch (IdentityContextException $failure) { throw IdentityContextProblem::from($failure); }
        $table = $platform === 'wecom' ? 'sand_ai_wecom_delivery' : 'sand_ai_feishu_delivery';
        $partFields = $platform === 'wecom' ? ',d.part_count,d.next_part' : '';
        $rows = Db::connect('pgsql')->query("SELECT d.task_id,d.environment_id,d.channel_binding_id,d.channel_code,
            d.channel_revision,d.message_scope{$partFields},b.application_id,b.workload_client_id,b.agent_definition_id,
            b.code AS binding_code,b.revision AS binding_revision,b.status AS binding_status
            FROM {$table} d JOIN sand_ai_channel_binding b ON b.id=d.channel_binding_id AND b.environment_id=d.environment_id
            WHERE d.environment_id=? AND d.channel_binding_id=? AND d.task_id=? AND b.delete_time IS NULL",
            [$environmentId, $bindingId, $taskId]);
        if (count($rows) !== 1) self::missing();
        $row = $rows[0];
        if ((int) $row['binding_status'] !== 1 || (int) $row['binding_revision'] !== (int) $row['channel_revision']
            || (string) $row['channel_code'] !== (string) $row['binding_code']) self::missing();
        $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)->where('task_type', 'agent_run')
            ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
        if ($task === null || !is_array($task->input) || !in_array((string) $task->state, ['succeeded', 'failed', 'canceled'], true)) self::missing();
        $frozen = $task->input['_channel'] ?? null;
        if (!is_array($frozen) || ($frozen['channel_binding_id'] ?? null) !== $bindingId
            || ($frozen['revision'] ?? null) !== (int) $row['channel_revision']
            || ($frozen['agent_definition_id'] ?? null) !== (int) $row['agent_definition_id']
            || ($frozen['code'] ?? null) !== (string) $row['channel_code']
            || !is_string($frozen['message_scope'] ?? null) || !is_string($row['message_scope'])
            || !hash_equals($frozen['message_scope'], $row['message_scope'])
            || !is_string($frozen['conversation_scope'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $frozen['conversation_scope']) !== 1
            || (isset($frozen['delivery_platform']) && $frozen['delivery_platform'] !== $platform)) self::missing();
        (new ChannelBindingGateway())->assertCurrent($environmentId, $frozen);
        (new \app\Application\Agent\AgentDefinitionGateway())->assertCurrent($environmentId, $task->input['_agent_definition'] ?? null);
        if (!isset($frozen['delivery_platform'])) {
            $opposite = $platform === 'wecom' ? 'sand_ai_feishu_delivery' : 'sand_ai_wecom_delivery';
            if (Db::connect('pgsql')->query("SELECT 1 FROM {$opposite} WHERE task_id=?", [$taskId]) !== []) self::missing();
        }
        try {
            $scope = (new TaskInputAccessGuard())->persistedScope($task);
            $owner = AgentGateway::attributionFromPersistedInput($task->input, $environmentId);
        } catch (\Throwable) { self::missing(); }
        if ($owner->organizationId !== $environment['organization_id'] || $owner->applicationId !== $environment['application_id']
            || (string) $owner->applicationId !== (string) $row['application_id']
            || (string) $owner->workloadClientId !== (string) $row['workload_client_id']
            || (int) $task->organization_id !== $owner->organizationId || (int) $task->application_id !== $owner->applicationId
            || (int) $task->workload_client_id !== $owner->workloadClientId) self::missing();
        if ($platform === 'wecom') {
            $confirmed = $row['part_count'] === null ? 0 : min((int) $row['part_count'], max(0, (int) $row['next_part'] - 1));
            if ($snapshot['confirmed_parts'] > $confirmed) self::missing();
        }
        return RequestPayloadFingerprint::fingerprint(['platform' => $platform, 'environment' => $environment,
            'binding_id' => $bindingId, 'application_id' => (string) $row['application_id'],
            'workload_client_id' => (string) $row['workload_client_id'], 'task_id' => $taskId,
            'channel' => $frozen, 'owner_scope' => $scope]);
    }

    private static function missing(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery retry request not found');
    }
}
