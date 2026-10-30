<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class ChannelDeliveryGateway
{
    /** Reads the original committed retry response; does not retry or change the outbox. */
    public function readRetryByRequestKey(int $environmentId, int $bindingId, int $taskId, string $key,
        string $actor, \Closure $revalidateAdmin): array
    {
        return (new ChannelDeliveryRetryRecovery())->read('feishu', $environmentId, $bindingId,
            $taskId, $key, $actor, $revalidateAdmin);
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function list(int $environmentId, int $bindingId, int $page, int $limit): array
    {
        $this->requireEnvironment($environmentId);
        if ($bindingId < 1 || $page < 1 || $limit < 1 || $limit > 100) self::invalid();
        $this->binding($environmentId, $bindingId);
        $db = Db::connect('pgsql');
        $total = $db->query(
            'SELECT count(*) AS total FROM sand_ai_feishu_delivery WHERE environment_id=? AND channel_binding_id=?',
            [$environmentId, $bindingId],
        );
        $rows = $db->query(
            'SELECT d.task_id,d.channel_revision,d.state,d.attempt_count,d.error_code,d.create_time,d.update_time,
                    t.state AS task_state
             FROM sand_ai_feishu_delivery d
             JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
             WHERE d.environment_id=? AND d.channel_binding_id=?
             ORDER BY d.task_id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$environmentId, $bindingId],
        );
        return [
            'current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0),
            'data' => array_map(static fn (array $row): array => [
                'task_id' => (int) $row['task_id'],
                'channel_revision' => (int) $row['channel_revision'],
                'state' => (string) $row['state'],
                'task_state' => (string) $row['task_state'],
                'attempt_count' => (int) $row['attempt_count'],
                'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
                'create_time' => (string) $row['create_time'],
                'update_time' => (string) $row['update_time'],
            ], $rows),
        ];
    }

    /** @return array{task_id:int,state:string,idempotent_replay?:bool} */
    public function retry(int $environmentId, int $bindingId, int $taskId, string $key, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        if ($bindingId < 1 || $taskId < 1 || trim($key) === '' || strlen($key) > 128
            || trim($actor) === '' || strlen($actor) > 64) self::invalid();
        $fingerprint = hash('sha256', $bindingId . ':' . $taskId);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $bindingId, $taskId, $key, $actor, $fingerprint): array {
            $db = Db::connect('pgsql');
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$environmentId . ':channel-delivery-retry:' . $key]);
            $operation = $db->query(
                "SELECT request_fingerprint,response_snapshot FROM sand_ai_channel_operation
                 WHERE environment_id=? AND operation_code='retry_delivery' AND idempotency_key=? FOR UPDATE",
                [$environmentId, $key],
            );
            if ($operation !== []) {
                if (!hash_equals((string) $operation[0]['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another delivery');
                }
                $stored = $operation[0]['response_snapshot'];
                $result = is_string($stored) ? json_decode($stored, true, 16, JSON_THROW_ON_ERROR) : $stored;
                if (!is_array($result)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Delivery retry record is invalid');
                return [...$result, 'idempotent_replay' => true];
            }
            $binding = $this->binding($environmentId, $bindingId, true);
            $rows = $db->query(
                'SELECT d.task_id,d.channel_revision,d.state,d.error_code,t.state AS task_state
                 FROM sand_ai_feishu_delivery d
                 JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
                 WHERE d.environment_id=? AND d.channel_binding_id=? AND d.task_id=? FOR UPDATE OF d',
                [$environmentId, $bindingId, $taskId],
            );
            if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery is unavailable');
            $row = $rows[0];
            if ((int) $binding['status'] !== 1 || (int) $binding['revision'] !== (int) $row['channel_revision']
                || $row['state'] !== 'failed'
                || $row['error_code'] === 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN'
                || !in_array($row['task_state'], ['succeeded', 'failed', 'canceled'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Delivery cannot be retried');
            }
            $db->execute(
                "UPDATE sand_ai_feishu_delivery SET state='pending',attempt_count=0,error_code=NULL,
                 available_at=CURRENT_TIMESTAMP(0),lease_token=NULL,lease_until=NULL,
                 sent_message_scope=NULL,update_time=CURRENT_TIMESTAMP(0)
                 WHERE environment_id=? AND channel_binding_id=? AND task_id=? AND state='failed'",
                [$environmentId, $bindingId, $taskId],
            );
            $result = ['task_id' => $taskId, 'state' => 'pending'];
            $db->execute(
                "INSERT INTO sand_ai_channel_operation
                 (environment_id,operation_code,idempotency_key,request_fingerprint,channel_binding_id,response_snapshot,created_by)
                 VALUES (?,'retry_delivery',?,?,?,CAST(? AS jsonb),?)",
                [$environmentId, $key, $fingerprint, $bindingId, json_encode($result, JSON_THROW_ON_ERROR), $actor],
            );
            AuditLog::create([
                'environment_id' => $environmentId, 'actor_type' => 'admin', 'actor_ref' => $actor,
                'action' => 'channel_delivery.retry', 'resource_type' => 'task_run', 'resource_id' => $taskId,
                'summary' => '重新发送渠道回信', 'context' => ['channel_binding_id' => $bindingId],
            ]);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function binding(int $environmentId, int $bindingId, bool $lock = false): array
    {
        $sql = 'SELECT id,status,revision FROM sand_ai_channel_binding WHERE environment_id=? AND id=? AND delete_time IS NULL';
        $rows = Db::connect('pgsql')->query($sql . ($lock ? ' FOR UPDATE' : ''), [$environmentId, $bindingId]);
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel is unavailable');
        return $rows[0];
    }

    private function requireEnvironment(int $id): void
    {
        if ($id < 1) self::invalid();
        try {
            (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($id);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel delivery request is invalid');
    }
}
