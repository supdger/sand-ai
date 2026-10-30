<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Task\TaskGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamRuntime;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;
use Throwable;

/** Delivers terminal channel tasks from a durable, leased outbox. */
final class WecomDeliveryGateway
{
    /** Reads the original committed retry response; does not retry or change the outbox. */
    public function readRetryByRequestKey(int $environmentId, int $bindingId, int $taskId, string $key,
        string $actor, \Closure $revalidateAdmin): array
    {
        return (new ChannelDeliveryRetryRecovery())->read('wecom', $environmentId, $bindingId,
            $taskId, $key, $actor, $revalidateAdmin);
    }

    /** @return array{task_id:int,state:string,confirmed_parts:int,idempotent_replay?:bool} */
    public function retry(int $environmentId, int $bindingId, int $taskId, string $key, string $actor): array
    {
        if ($environmentId < 1 || $bindingId < 1 || $taskId < 1 || trim($key) === ''
            || strlen($key) > 128 || trim($actor) === '' || strlen($actor) > 64) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel delivery retry is invalid');
        }
        try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); }
        catch (IdentityContextException $exception) { throw IdentityContextProblem::from($exception); }
        $fingerprint = hash('sha256', 'wecom:' . $bindingId . ':' . $taskId);
        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $bindingId, $taskId, $key, $actor, $fingerprint,
        ): array {
            $db = Db::connect('pgsql');
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',
                [$environmentId . ':wecom-delivery-retry:' . $key]);
            $operation = $db->query("SELECT request_fingerprint,response_snapshot FROM sand_ai_channel_operation
                WHERE environment_id=? AND operation_code='retry_wecom_delivery' AND idempotency_key=? FOR UPDATE",
                [$environmentId, $key]);
            if ($operation !== []) {
                if (!hash_equals((string) $operation[0]['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another delivery');
                }
                $stored = $operation[0]['response_snapshot'];
                $result = is_string($stored) ? json_decode($stored, true, 16, JSON_THROW_ON_ERROR) : $stored;
                if (!is_array($result)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Delivery retry record is invalid');
                }
                return [...$result, 'idempotent_replay' => true];
            }
            $binding = $db->query('SELECT status,revision FROM sand_ai_channel_binding
                WHERE environment_id=? AND id=? AND delete_time IS NULL FOR UPDATE',
                [$environmentId, $bindingId]);
            if ($binding === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel is unavailable');
            $rows = $db->query('SELECT d.channel_revision,d.state,d.part_count,d.next_part,t.state AS task_state
                FROM sand_ai_wecom_delivery d
                JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
                WHERE d.environment_id=? AND d.channel_binding_id=? AND d.task_id=? FOR UPDATE OF d',
                [$environmentId, $bindingId, $taskId]);
            if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery is unavailable');
            $row = $rows[0];
            if ((int) $binding[0]['status'] !== 1
                || (int) $binding[0]['revision'] !== (int) $row['channel_revision']
                || $row['state'] !== 'failed'
                || !in_array($row['task_state'], ['succeeded', 'failed', 'canceled'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Delivery cannot be retried');
            }
            $changed = $db->execute("UPDATE sand_ai_wecom_delivery SET state='pending',attempt_count=0,
                error_code=NULL,available_at=CURRENT_TIMESTAMP(0),lease_token=NULL,lease_until=NULL,
                update_time=CURRENT_TIMESTAMP(0)
                WHERE environment_id=? AND channel_binding_id=? AND task_id=? AND state='failed'",
                [$environmentId, $bindingId, $taskId]);
            if ($changed !== 1) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Delivery changed before retry');
            $confirmed = $row['part_count'] === null ? 0
                : min((int) $row['part_count'], max(0, (int) $row['next_part'] - 1));
            $result = ['task_id' => $taskId, 'state' => 'pending', 'confirmed_parts' => $confirmed];
            $db->execute("INSERT INTO sand_ai_channel_operation
                (environment_id,operation_code,idempotency_key,request_fingerprint,channel_binding_id,response_snapshot,created_by)
                VALUES (?,'retry_wecom_delivery',?,?,?,CAST(? AS jsonb),?)",
                [$environmentId, $key, $fingerprint, $bindingId, json_encode($result, JSON_THROW_ON_ERROR), $actor]);
            AuditLog::create([
                'environment_id' => $environmentId, 'actor_type' => 'admin', 'actor_ref' => $actor,
                'action' => 'wecom_delivery.retry', 'resource_type' => 'task_run', 'resource_id' => $taskId,
                'summary' => '重新发送企业微信未确认的回信段', 'context' => [
                    'channel_binding_id' => $bindingId, 'confirmed_parts' => $confirmed,
                ],
            ]);
            return $result;
        });
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    public function list(int $environmentId, int $bindingId, int $page, int $limit): array
    {
        if ($environmentId < 1 || $bindingId < 1 || $page < 1 || $limit < 1 || $limit > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel delivery request is invalid');
        }
        try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); }
        catch (IdentityContextException $exception) { throw IdentityContextProblem::from($exception); }
        $db = Db::connect('pgsql');
        $binding = $db->query('SELECT id FROM sand_ai_channel_binding WHERE environment_id=? AND id=? AND delete_time IS NULL',
            [$environmentId, $bindingId]);
        if ($binding === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel is unavailable');
        $total = $db->query('SELECT count(*) AS total FROM sand_ai_wecom_delivery WHERE environment_id=? AND channel_binding_id=?',
            [$environmentId, $bindingId]);
        $rows = $db->query('SELECT d.task_id,d.channel_revision,d.state,d.attempt_count,d.part_count,d.next_part,
                d.error_code,d.create_time,d.update_time,
                t.state AS task_state
            FROM sand_ai_wecom_delivery d JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
            WHERE d.environment_id=? AND d.channel_binding_id=?
            ORDER BY d.task_id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            [$environmentId, $bindingId]);
        return ['current_page' => $page, 'per_page' => $limit, 'total' => (int) ($total[0]['total'] ?? 0),
            'data' => array_map(static fn (array $row): array => [
                'task_id' => (int) $row['task_id'], 'channel_revision' => (int) $row['channel_revision'],
                'state' => (string) $row['state'], 'task_state' => (string) $row['task_state'],
                'attempt_count' => (int) $row['attempt_count'],
                'part_count' => $row['part_count'] === null ? null : (int) $row['part_count'],
                'confirmed_parts' => $row['part_count'] === null ? null
                    : min((int) $row['part_count'], max(0, (int) $row['next_part'] - 1)),
                'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
                'create_time' => (string) $row['create_time'], 'update_time' => (string) $row['update_time'],
            ], $rows)];
    }

    public function runOne(): bool
    {
        $row = $this->claim();
        if ($row === null) return false;
        $taskId = (int) $row['task_id'];
        $lease = (string) $row['lease_token'];
        $sentPart = 0;
        try {
            [$response, $credentials, $beforeSend] = $this->authorizedResponse($row);
            $parts = self::responseParts($response);
            $responseHash = hash('sha256', $response);
            $nextPart = $this->prepareParts($row, $lease, $responseHash, count($parts));
            $envelope = is_string($row['receiver_envelope'])
                ? json_decode($row['receiver_envelope'], true, 16, JSON_THROW_ON_ERROR) : $row['receiver_envelope'];
            if (is_object($envelope)) $envelope = (array) $envelope;
            if (!is_array($envelope)) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery target is unavailable');
            $userId = (new WecomDeliveryCipher((array) config('sand_ai_iam_context', [])))->open(
                $envelope, $taskId, (int) $row['environment_id'], (string) $row['channel_code'],
            );
            for ($partNo = $nextPart; $partNo <= count($parts); ++$partNo) {
                $this->renewLease($taskId, $lease);
                (new WecomMessageClient())->sendText(
                    $credentials['corp_id'], $credentials['corp_secret'], $credentials['agent_id'],
                    $userId, $parts[$partNo - 1], $beforeSend, $credentials['api_base'] ?? null,
                );
                $sentPart = $partNo;
                $this->confirmPart($taskId, $lease, $responseHash, $partNo, count($parts));
                $sentPart = 0;
            }
            $this->finish($taskId, $lease, 'sent', null);
        } catch (Throwable $exception) {
            \Log::error('SandAI wecom delivery exception', ['class' => get_class($exception), 'code' => $exception instanceof \app\Api\Support\ApiProblem ? $exception->errorCode : (string) $exception->getCode(), 'origin' => basename((string) $exception->getFile()) . ':' . $exception->getLine()]);
            if ($sentPart > 0) {
                $this->resolveConfirmedSend($row, $lease, $sentPart);
                return true;
            }
            $terminal = $exception instanceof ApiProblem && in_array($exception->errorCode, [
                'SAND_AI_RESOURCE_NOT_FOUND', 'SAND_AI_SCOPE_SNAPSHOT_INVALID',
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'SAND_AI_SOURCE_UNVERIFIED',
                'SAND_AI_CHANNEL_DELIVERY_INVALID', 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            ], true);
            $code = $exception instanceof ApiProblem ? $exception->errorCode : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE';
            (new WecomChannelStatusGateway())->deliveryError(
                (int) $row['environment_id'], (int) $row['channel_binding_id'], $code,
            );
            if ($code === 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN') {
                $this->finish($taskId, $lease, 'uncertain', $code);
            } else {
                $this->fail($row, $lease, $code, $terminal || (int) $row['attempt_count'] >= 5);
            }
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private function resolveConfirmedSend(array $row, string $lease, int $sentPart): void
    {
        $db = Db::connect('pgsql');
        $taskId = (int) $row['task_id'];
        $rows = $db->query('SELECT state,lease_token,next_part,part_count FROM sand_ai_wecom_delivery WHERE task_id=?', [$taskId]);
        if (($rows[0]['state'] ?? null) === 'sent') return;
        if (($rows[0]['state'] ?? null) === 'sending'
            && hash_equals($lease, (string) ($rows[0]['lease_token'] ?? ''))
            && (int) ($rows[0]['next_part'] ?? 0) > $sentPart) {
            if ((int) $rows[0]['next_part'] > (int) $rows[0]['part_count']) {
                $this->finish($taskId, $lease, 'sent', null);
            } else {
                $changed = $db->execute("UPDATE sand_ai_wecom_delivery SET state='pending',available_at=CURRENT_TIMESTAMP(0),
                    lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0)
                    WHERE task_id=? AND state='sending' AND lease_token=? AND next_part>?",
                    [$taskId, $lease, $sentPart]);
                if ($changed !== 1) {
                    throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Confirmed delivery progress could not be reconciled');
                }
            }
            return;
        }
        $changed = $db->execute("UPDATE sand_ai_wecom_delivery SET state='uncertain',
            error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',lease_token=NULL,lease_until=NULL,
            update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending' AND lease_token=?",
            [$taskId, $lease]);
        if ($changed === 1) {
            (new WecomChannelStatusGateway())->deliveryError(
                (int) $row['environment_id'], (int) $row['channel_binding_id'], 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',
            );
            return;
        }
        $rows = $db->query('SELECT state FROM sand_ai_wecom_delivery WHERE task_id=?', [$taskId]);
        if (in_array($rows[0]['state'] ?? null, ['sent', 'uncertain'], true)) return;
        throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery state could not be reconciled');
    }

    /** @return list<string> */
    private static function responseParts(string $response): array
    {
        $length = mb_strlen($response, 'UTF-8');
        if (trim($response) === '' || $length > 4000) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Agent response cannot be delivered');
        }
        $parts = [];
        $part = '';
        $characters = 0;
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = mb_substr($response, $offset, 1, 'UTF-8');
            if ($characters >= 2000 || strlen($part) + strlen($character) > 3900) {
                $parts[] = $part;
                $part = '';
                $characters = 0;
            }
            $part .= $character;
            ++$characters;
        }
        if ($part !== '') $parts[] = $part;
        $parts = array_values(array_filter(array_map('trim', $parts),
            static fn (string $content): bool => $content !== ''));
        if ($parts === [] || count($parts) > 8) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Agent response cannot be delivered');
        }
        if (count($parts) === 1) return $parts;
        $count = count($parts);
        return array_map(static fn (int $index, string $content): string => '（' . ($index + 1) . '/' . $count . '）' . $content,
            array_keys($parts), $parts);
    }

    /** @param array<string,mixed> $row */
    private function prepareParts(array $row, string $lease, string $responseHash, int $partCount): int
    {
        $taskId = (int) $row['task_id'];
        if ($row['response_sha256'] === null && $row['part_count'] === null && (int) $row['next_part'] === 1) {
            $changed = Db::connect('pgsql')->execute('UPDATE sand_ai_wecom_delivery
                SET response_sha256=?,part_count=?,update_time=CURRENT_TIMESTAMP(0)
                WHERE task_id=? AND state=? AND lease_token=? AND response_sha256 IS NULL
                    AND part_count IS NULL AND next_part=1',
                [$responseHash, $partCount, $taskId, 'sending', $lease]);
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery changed before sending');
            }
            return 1;
        }
        if (!is_string($row['response_sha256']) || !hash_equals($row['response_sha256'], $responseHash)
            || (int) $row['part_count'] !== $partCount
            || (int) $row['next_part'] < 1 || (int) $row['next_part'] > $partCount + 1) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Delivery response changed between parts');
        }
        return (int) $row['next_part'];
    }

    private function confirmPart(int $taskId, string $lease, string $responseHash, int $partNo, int $partCount): void
    {
        $changed = Db::connect('pgsql')->execute('UPDATE sand_ai_wecom_delivery
            SET next_part=?,lease_until=CURRENT_TIMESTAMP + INTERVAL \'30 seconds\',update_time=CURRENT_TIMESTAMP(0)
            WHERE task_id=? AND state=? AND lease_token=? AND next_part=?
                AND response_sha256=? AND part_count=?',
            [$partNo + 1, $taskId, 'sending', $lease, $partNo, $responseHash, $partCount]);
        if ($changed !== 1) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Confirmed delivery part could not be recorded');
        }
    }

    private function renewLease(int $taskId, string $lease): void
    {
        $changed = Db::connect('pgsql')->execute('UPDATE sand_ai_wecom_delivery
            SET lease_until=CURRENT_TIMESTAMP + INTERVAL \'30 seconds\',update_time=CURRENT_TIMESTAMP(0)
            WHERE task_id=? AND state=? AND lease_token=? AND lease_until>CURRENT_TIMESTAMP',
            [$taskId, 'sending', $lease]);
        if ($changed !== 1) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery lease expired before sending');
        }
    }

    /** @return array<string,mixed>|null */
    private function claim(): ?array
    {
        return Db::connect('pgsql')->transaction(function (): ?array {
            $db = Db::connect('pgsql');
            $expired = $db->query("SELECT d.task_id FROM sand_ai_wecom_delivery d
                WHERE d.state='sending' AND d.lease_until<=CURRENT_TIMESTAMP
                ORDER BY d.lease_until,d.task_id LIMIT 1 FOR UPDATE OF d SKIP LOCKED");
            if ($expired !== []) {
                $db->execute("UPDATE sand_ai_wecom_delivery SET state='uncertain',
                    error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',lease_token=NULL,lease_until=NULL,
                    update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending'",
                    [(int) $expired[0]['task_id']]);
                $db->execute("INSERT INTO sand_ai_wecom_channel_status
                    (environment_id,channel_binding_id,last_delivery_error_code,last_delivery_error_at)
                    SELECT environment_id,channel_binding_id,'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',CURRENT_TIMESTAMP(0)
                    FROM sand_ai_wecom_delivery WHERE task_id=?
                    ON CONFLICT (channel_binding_id) DO UPDATE SET
                    last_delivery_error_code=EXCLUDED.last_delivery_error_code,
                    last_delivery_error_at=EXCLUDED.last_delivery_error_at,update_time=CURRENT_TIMESTAMP(0)",
                    [(int) $expired[0]['task_id']]);
                return null;
            }
            $rows = $db->query("SELECT d.* FROM sand_ai_wecom_delivery d
                JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
                WHERE d.state='pending' AND d.available_at<=CURRENT_TIMESTAMP
                  AND t.state IN ('succeeded','failed','canceled')
                ORDER BY d.available_at,d.task_id LIMIT 1 FOR UPDATE OF d SKIP LOCKED");
            if ($rows === []) return null;
            $row = $rows[0];
            if ((int) $row['attempt_count'] >= 5) {
                $db->execute("UPDATE sand_ai_wecom_delivery SET state='failed',error_code='SAND_AI_CHANNEL_DELIVERY_EXHAUSTED',
                    lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE task_id=?", [(int) $row['task_id']]);
                $db->execute("INSERT INTO sand_ai_wecom_channel_status
                    (environment_id,channel_binding_id,last_delivery_error_code,last_delivery_error_at)
                    VALUES (?,?,'SAND_AI_CHANNEL_DELIVERY_EXHAUSTED',CURRENT_TIMESTAMP(0))
                    ON CONFLICT (channel_binding_id) DO UPDATE SET
                    last_delivery_error_code=EXCLUDED.last_delivery_error_code,
                    last_delivery_error_at=EXCLUDED.last_delivery_error_at,update_time=CURRENT_TIMESTAMP(0)",
                    [(int) $row['environment_id'], (int) $row['channel_binding_id']]);
                return null;
            }
            $lease = bin2hex(random_bytes(16));
            $db->execute("UPDATE sand_ai_wecom_delivery SET state='sending',attempt_count=attempt_count+1,
                lease_token=?,lease_until=CURRENT_TIMESTAMP + INTERVAL '30 seconds',update_time=CURRENT_TIMESTAMP(0)
                WHERE task_id=?", [$lease, (int) $row['task_id']]);
            return [...$row, 'lease_token' => $lease, 'attempt_count' => (int) $row['attempt_count'] + 1];
        });
    }

    /** @param array<string,mixed> $row */
    /** @return array{string,array<string,string>,\Closure} */
    private function authorizedResponse(array $row): array
    {
        $code = (string) $row['channel_code'];
        $credentials = WecomChannelCredentials::forCode($code);
        $sourceIp = (string) config('sand_ai_wecom.worker_source_ip', '');
        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::') {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery source address is unavailable');
        }
        $host = HostSandIamRuntime::identityContextProvider();
        if ($host === null || !method_exists($host, 'issue')) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM workload issuer is unavailable');
        }
        $taskId = (int) $row['task_id'];
        $requestId = 'wecom-delivery-' . substr(hash('sha256', $taskId . ':' . $row['attempt_count']), 0, 40);
        $actions = ['sand_ai.task.read', 'sand_ai.agent.submit', 'sand_ai.agent.run', 'sand_ai.knowledge.query'];
        if (\app\Infrastructure\Persistence\Model\ToolRun::where('parent_task_run_id', $taskId)
            ->where('environment_id', (int) $row['environment_id'])->find() !== null) $actions[] = 'sand_ai.tool.read';
        $issued = $host->issue($credentials['workload_credential'], 'sand-ai', $actions, null,
            $requestId, $sourceIp, 'sand_ai');
        $signed = $issued['context'] ?? null;
        if (!is_string($signed) || $signed === '') {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM workload context is unavailable');
        }
        $provider = new HostSandIamIdentityContextProvider($host);
        $readIdentity = $provider->requireSignedContext($signed, 'sand_ai.task.read', $sourceIp, $requestId);
        $submitIdentity = $provider->requireSignedContext($signed, 'sand_ai.agent.submit', $sourceIp, $requestId);
        $runIdentity = $provider->requireSignedContext($signed, 'sand_ai.agent.run', $sourceIp, $requestId);
        $knowledgeIdentity = $provider->requireSignedContext($signed, 'sand_ai.knowledge.query', $sourceIp, $requestId);
        $scope = RequestIdempotencyKey::trustedScope($readIdentity);
        if ($readIdentity->organizationId !== $submitIdentity->organizationId
            || $readIdentity->organizationId !== $runIdentity->organizationId
            || $readIdentity->organizationId !== $knowledgeIdentity->organizationId
            || !hash_equals($scope, RequestIdempotencyKey::trustedScope($submitIdentity))
            || !hash_equals($scope, RequestIdempotencyKey::trustedScope($runIdentity))
            || !hash_equals($scope, RequestIdempotencyKey::trustedScope($knowledgeIdentity))
            || $readIdentity->environmentId !== (int) $row['environment_id']) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Delivery identity changed');
        }
        $channel = (new ChannelBindingGateway())->resolve($readIdentity->environmentId, $code, $readIdentity);
        WecomChannelCredentials::assertManagedScope(
            $credentials, $readIdentity->environmentId, $channel['binding']['channel_binding_id'],
        );
        if ($channel['binding']['channel_binding_id'] !== (int) $row['channel_binding_id']
            || $channel['binding']['revision'] !== (int) $row['channel_revision']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Delivery channel changed');
        }
        $header = 'Bearer ' . $signed;
        $operation = new TaskOperationAccessGuard();
        $decision = null;
        $frozenInput = null;
        $toolRead = null;
        $deliveryTask = null;
        $result = (new TaskGateway())->read($readIdentity->environmentId, $taskId, RequestIdempotencyKey::trustedScopeForAttribution(
            UsageAttribution::fromIdentity($readIdentity, 'agent', 'sand_ai.agent.submit'),
        ),
            function (TaskRun $task) use ($row, $channel, $operation, $header, $sourceIp,
                $readIdentity, $submitIdentity, $runIdentity, $knowledgeIdentity, $requestId, &$decision, &$frozenInput, &$toolRead, &$deliveryTask): void {
                if ((string) $task->task_type !== 'agent_run') {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery task is unavailable');
                }
                $input = is_object($task->input) ? (array) $task->input : $task->input;
                $frozen = is_array($input) ? ($input['_channel'] ?? null) : null;
                if (!is_array($frozen) || (int) ($frozen['channel_binding_id'] ?? 0) !== (int) $row['channel_binding_id']
                    || (int) ($frozen['revision'] ?? 0) !== (int) $row['channel_revision']
                    || !is_string($frozen['message_scope'] ?? null)
                    || !hash_equals((string) $row['message_scope'], $frozen['message_scope'])) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery task is unavailable');
                }
                (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $frozen);
                (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $input['_agent_definition'] ?? null);
                $frozenInput = $frozen;
                $decision = $operation->authorizeSigned($header, $sourceIp, $readIdentity,
                    (int) $task->id, 'sand_ai.task.read', $requestId);
                (new TaskRunAccessGuard())->authorizeReadSigned($header, $sourceIp, $submitIdentity, $task, $requestId);
                (new AgentKnowledgeAccessGuard())->authorizeReadSigned($header, $sourceIp, $knowledgeIdentity, $task, $requestId);
                (new AgentRetrievedSourceGuard())->authorizeReadSigned($header, $sourceIp, $runIdentity, $task, $requestId);
                $toolRead = (new \app\Application\Agent\AgentReadonlyToolReadGuard())->authorizeTaskSigned($header, $sourceIp, $readIdentity, $task, $requestId);
                $toolRead();
                $deliveryTask = $task;
            }, true);
        $operation->revalidateSigned($signed, $sourceIp, $readIdentity, $taskId, 'sand_ai.task.read', $requestId, $decision ?? []);
        (new ChannelBindingGateway())->assertCurrent($readIdentity->environmentId, $frozenInput);
        (new AgentDefinitionGateway())->assertCurrent($readIdentity->environmentId, $channel['agent']['binding']);
        if (!$toolRead instanceof \Closure || !$deliveryTask instanceof TaskRun) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery authorization is unavailable');
        }
        $toolRead();
        $resultHash = \app\Application\Tool\ToolFingerprint::for('channel_task_result', [
            'state' => (string) $deliveryTask->state, 'result' => $deliveryTask->result, 'input' => $deliveryTask->input,
        ]);
        $beforeSend = function () use ($toolRead, $deliveryTask, $resultHash, $row, $channel, $header, $signed, $sourceIp,
            $readIdentity, $submitIdentity, $runIdentity, $knowledgeIdentity, $requestId, $operation, $decision, $frozenInput): void {
            $current = TaskRun::where('id', (int) $deliveryTask->id)->where('environment_id', $readIdentity->environmentId)
                ->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
            if ($current === null || !hash_equals($resultHash, \app\Application\Tool\ToolFingerprint::for('channel_task_result', [
                'state' => (string) $current->state, 'result' => $current->result, 'input' => $current->input,
            ]))) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery result changed before send');
            $operation->revalidateSigned($signed, $sourceIp, $readIdentity, (int) $current->id, 'sand_ai.task.read', $requestId, $decision ?? []);
            (new ChannelBindingGateway())->assertCurrent($readIdentity->environmentId, $frozenInput);
            (new AgentDefinitionGateway())->assertCurrent($readIdentity->environmentId, $channel['agent']['binding']);
            (new TaskRunAccessGuard())->authorizeReadSigned($header, $sourceIp, $submitIdentity, $current, $requestId);
            (new AgentKnowledgeAccessGuard())->authorizeReadSigned($header, $sourceIp, $knowledgeIdentity, $current, $requestId);
            (new AgentRetrievedSourceGuard())->authorizeReadSigned($header, $sourceIp, $runIdentity, $current, $requestId);
            $toolRead();
            $outbox = Db::connect('pgsql')->query('SELECT 1 FROM sand_ai_wecom_delivery WHERE task_id=? AND state=\'sending\' AND lease_token=? AND lease_until>CURRENT_TIMESTAMP',
                [(int) $row['task_id'], (string) $row['lease_token']]);
            if ($outbox === []) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery lease changed before send');
        };
        if ($result['state'] === 'succeeded') {
            if (($result['result']['review_required'] ?? false) === true) {
                return ['处理已完成，结果待审核。', $credentials, $beforeSend];
            }
            $text = $result['result']['output']['content'] ?? null;
            if (!is_string($text) || trim($text) === '' || mb_strlen($text, 'UTF-8') > 4000) {
                throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Agent response cannot be delivered');
            }
            return [$text, $credentials, $beforeSend];
        }
        if (in_array($result['state'], ['failed','canceled'], true)) return ['本次处理未完成，请稍后重试。', $credentials, $beforeSend];
        throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Agent task is not complete', true);
    }

    private function finish(int $taskId, string $lease, string $state, ?string $error): void
    {
        $changed = Db::connect('pgsql')->execute('UPDATE sand_ai_wecom_delivery SET state=?,error_code=?,
            lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0)
            WHERE task_id=? AND state=? AND lease_token=?
                AND (? <> \'sent\' OR (part_count IS NOT NULL AND next_part=part_count+1))',
            [$state, $error, $taskId, 'sending', $lease, $state]);
        if ($changed !== 1) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery lease changed');
    }

    /** @param array<string,mixed> $row */
    private function fail(array $row, string $lease, string $code, bool $terminal): void
    {
        $state = $terminal ? 'failed' : 'pending';
        $delay = $terminal ? 0 : 10;
        Db::connect('pgsql')->execute("UPDATE sand_ai_wecom_delivery SET state=?,error_code=?,available_at=CURRENT_TIMESTAMP + (? * INTERVAL '1 second'),
            lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending' AND lease_token=?",
            [$state, $code, $delay, (int) $row['task_id'], $lease]);
    }
}
