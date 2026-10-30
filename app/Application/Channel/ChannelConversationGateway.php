<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

final class ChannelConversationGateway
{
    /** @return array{id:int,closed_after_task_id:int,idempotent_replay:bool} */
    public function close(int $environmentId, UsageAttribution $attribution, array $channel, string $requestId): array
    {
        $scope = $this->scope($channel);
        $ownerScope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        if ($environmentId < 1 || $attribution->environmentId !== $environmentId
            || $attribution->organizationId === null || $attribution->applicationId === null
            || $attribution->workloadClientId === null || $requestId === '' || strlen($requestId) > 96) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel conversation close request is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $attribution, $channel, $scope, $ownerScope, $requestId,
        ): array {
            $this->lock($environmentId, $channel);
            $this->lockMessageRequest($requestId);
            $db = Db::connect('pgsql');
            $bindings = $db->query(
                'SELECT revision,agent_definition_id,application_id,workload_client_id
                 FROM sand_ai_channel_binding
                 WHERE id=? AND environment_id=? AND code=? AND status=1 AND delete_time IS NULL
                 FOR SHARE',
                [$channel['channel_binding_id'], $environmentId, $channel['code'] ?? null],
            );
            $binding = $bindings[0] ?? null;
            if (!is_array($binding) || (int) $binding['revision'] !== ($channel['revision'] ?? null)
                || (int) $binding['agent_definition_id'] !== ($channel['agent_definition_id'] ?? null)
                || (string) $binding['application_id'] !== (string) $attribution->applicationId
                || (string) $binding['workload_client_id'] !== (string) $attribution->workloadClientId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel binding changed before close');
            }
            if (TaskRun::where('request_id', $requestId)->find() !== null) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The channel message is already an agent task');
            }
            $existing = $db->query(
                'SELECT * FROM sand_ai_channel_conversation_close WHERE request_id=? FOR UPDATE',
                [$requestId],
            );
            if ($existing !== []) {
                $row = $existing[0];
                if ((int) $row['environment_id'] !== $environmentId
                    || (int) $row['channel_binding_id'] !== $channel['channel_binding_id']
                    || !hash_equals((string) $row['conversation_scope'], $scope)
                    || !hash_equals((string) $row['owner_scope'], $ownerScope)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Channel close request changed');
                }
                return [
                    'id' => (int) $row['id'],
                    'closed_after_task_id' => (int) $row['closed_after_task_id'],
                    'idempotent_replay' => true,
                ];
            }
            $this->assertAvailable($environmentId, $channel);
            $last = $db->query(
                "SELECT id FROM sand_ai_task_run
                 WHERE environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?
                   AND task_type='agent_run'
                   AND input#>>'{_channel,channel_binding_id}'=?
                   AND input#>>'{_channel,conversation_scope}'=?
                 ORDER BY id DESC LIMIT 1",
                [
                    $environmentId, $attribution->organizationId, $attribution->applicationId,
                    $attribution->workloadClientId, (string) $channel['channel_binding_id'], $scope,
                ],
            );
            $closedAfterTaskId = $last === [] ? 0 : (int) $last[0]['id'];
            $created = $db->query(
                'INSERT INTO sand_ai_channel_conversation_close
                 (environment_id,channel_binding_id,conversation_scope,owner_scope,request_id,closed_after_task_id)
                 VALUES (?,?,?,?,?,?) RETURNING id',
                [
                    $environmentId, $channel['channel_binding_id'], $scope,
                    $ownerScope, $requestId, $closedAfterTaskId,
                ],
            );
            AuditLog::create([
                'environment_id' => $environmentId,
                'actor_type' => 'workload',
                'actor_ref' => (string) $attribution->workloadClientId,
                'action' => 'channel.conversation.close',
                'resource_type' => 'channel_binding',
                'resource_id' => (int) $channel['channel_binding_id'],
                'summary' => 'Channel conversation context closed',
                'context' => [
                    'close_id' => (int) $created[0]['id'],
                    'closed_after_task_id' => $closedAfterTaskId,
                ],
            ]);
            return [
                'id' => (int) $created[0]['id'],
                'closed_after_task_id' => $closedAfterTaskId,
                'idempotent_replay' => false,
            ];
        });
    }

    /** @param array<string,mixed> $channel @return array{id:int,closed_after_task_id:int,actor_ref:string,idempotent_replay:bool} */
    public function readClose(int $environmentId, UsageAttribution $attribution, array $channel, string $requestId): array
    {
        $scope = $this->scope($channel);
        $ownerScope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        if ($environmentId < 1 || $attribution->environmentId !== $environmentId
            || $attribution->organizationId === null || $attribution->applicationId === null
            || $attribution->workloadClientId === null || $requestId === '' || strlen($requestId) > 96) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel conversation recovery request is invalid');
        }
        $db = Db::connect('pgsql');
        $rows = $db->query(
            'SELECT id,closed_after_task_id FROM sand_ai_channel_conversation_close
             WHERE environment_id=? AND channel_binding_id=? AND conversation_scope=? AND owner_scope=? AND request_id=?',
            [$environmentId, $channel['channel_binding_id'], $scope, $ownerScope, $requestId],
        );
        if ($rows === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel close not found');
        if (count($rows) !== 1) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original channel close is ambiguous');
        $row = $rows[0];
        // The original transaction wrote this workload actor and its exact immutable close result.
        $proof = $db->query(
            "SELECT id FROM sand_ai_audit_log
             WHERE environment_id=? AND actor_type='workload' AND actor_ref=?
               AND action='channel.conversation.close' AND resource_type='channel_binding' AND resource_id=?
               AND context->>'close_id'=? AND context->>'closed_after_task_id'=? LIMIT 2",
            [$environmentId, (string) $attribution->workloadClientId, $channel['channel_binding_id'],
                (string) $row['id'], (string) $row['closed_after_task_id']],
        );
        if (count($proof) !== 1) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original channel close actor proof is unavailable');
        }
        return ['id' => (int) $row['id'], 'closed_after_task_id' => (int) $row['closed_after_task_id'],
            'actor_ref' => (string) $attribution->workloadClientId, 'idempotent_replay' => true];
    }

    /** @param array<string,mixed> $channel */
    public function lastClosedTaskId(int $environmentId, array $channel, string $ownerScope): int
    {
        $scope = $this->scope($channel);
        if ($environmentId < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $ownerScope) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel conversation scope is invalid');
        }
        $rows = Db::connect('pgsql')->query(
            'SELECT closed_after_task_id FROM sand_ai_channel_conversation_close
             WHERE environment_id=? AND channel_binding_id=? AND conversation_scope=? AND owner_scope=?
             ORDER BY id DESC LIMIT 1',
            [$environmentId, $channel['channel_binding_id'], $scope, $ownerScope],
        );
        return $rows === [] ? 0 : (int) $rows[0]['closed_after_task_id'];
    }

    /** @return list<int> */
    public function taskIds(int $environmentId, UsageAttribution $attribution, array $channel, array $agent, ?int $beforeTaskId, int $limit): array
    {
        $scope = $this->scope($channel);
        $this->assertAgentSnapshot($agent);
        $rows = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_task_run
             WHERE environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?
               AND task_type='agent_run' AND id<?
               AND input#>>'{_channel,channel_binding_id}'=?
               AND input#>>'{_channel,conversation_scope}'=?
               AND input#>>'{_channel,revision}'=?
               AND input#>>'{_agent_definition,version_id}'=?
               AND input#>>'{_agent_definition,knowledge_publication_id}'=?
               AND input#>>'{_agent_definition,knowledge_version_id}'=?
               AND input#>>'{_agent_definition,knowledge_scope_sha256}'=?
             ORDER BY id DESC LIMIT ?",
            [$environmentId, $attribution->organizationId, $attribution->applicationId,
                $attribution->workloadClientId, $beforeTaskId ?? PHP_INT_MAX,
                (string) $channel['channel_binding_id'], $scope, (string) $channel['revision'],
                (string) $agent['version_id'], (string) $agent['knowledge_publication_id'],
                (string) $agent['knowledge_version_id'], $agent['knowledge_scope_sha256'], $limit],
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** Called only inside the Agent task creation transaction. */
    public function lock(int $environmentId, array $channel): void
    {
        $scope = $this->scope($channel);
        $db = Db::connect('pgsql');
        $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$environmentId . ':channel-conversation:' . $channel['channel_binding_id'] . ':' . $scope]);
    }

    /** Called only after the same-conversation lock is held. */
    public function assertAvailable(int $environmentId, array $channel): void
    {
        $scope = $this->scope($channel);
        $db = Db::connect('pgsql');
        $rows = $db->query(
            "SELECT id FROM sand_ai_task_run WHERE environment_id=? AND task_type='agent_run'
             AND input#>>'{_channel,channel_binding_id}'=? AND input#>>'{_channel,conversation_scope}'=?
             AND state NOT IN ('succeeded','failed','canceled') LIMIT 1",
            [$environmentId, (string) $channel['channel_binding_id'], $scope],
        );
        if ($rows !== []) throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'A channel message is still being processed', true);
    }

    /** Called inside the same-conversation lock, before an Agent task is created or replayed. */
    public function assertMessageRequestAvailable(string $requestId): void
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id FROM sand_ai_channel_conversation_close WHERE request_id=?',
            [$requestId],
        );
        if ($rows !== []) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The channel message already closed a conversation');
        }
    }

    /** Shares one idempotency fence across task submission and platform close controls. */
    public function lockMessageRequest(string $requestId): void
    {
        Db::connect('pgsql')->query(
            'SELECT pg_advisory_xact_lock(hashtextextended(?,0))',
            ['channel-message-request:' . $requestId],
        );
    }

    /** @param array<string,mixed> $channel @param array<string,mixed> $agent @return array{messages:list<array{role:string,content:string}>,source_groups:list<list<array<string,mixed>>>,source_references:list<array<string,mixed>>} */
    public function history(TaskRun $task, array $channel, array $agent, UsageAttribution $attribution, string $identityScope, int $turnLimit = 2): array
    {
        if ($turnLimit < 0 || $turnLimit > 4) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel context policy is invalid');
        }
        $scope = $this->scope($channel);
        $closedAfterTaskId = $this->lastClosedTaskId((int) $task->environment_id, $channel, $identityScope);
        $this->assertAgentSnapshot($agent);
        $platform = $channel['delivery_platform'] ?? null;
        $historyPlatform = $platform;
        if ($platform === null) {
            $deliveries = Db::connect('pgsql')->query(
                "SELECT 'feishu' AS platform FROM sand_ai_feishu_delivery WHERE task_id=? AND environment_id=?
                 UNION ALL
                 SELECT 'wecom' AS platform FROM sand_ai_wecom_delivery WHERE task_id=? AND environment_id=?",
                [(int) $task->id, (int) $task->environment_id, (int) $task->id, (int) $task->environment_id],
            );
            if (count($deliveries) > 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel delivery platform is ambiguous');
            }
            $historyPlatform = $deliveries[0]['platform'] ?? null;
        }
        $deliveryJoin = match ($historyPlatform) {
            null => '',
            'feishu' => "JOIN sand_ai_feishu_delivery delivery
                ON delivery.task_id=t.id AND delivery.environment_id=t.environment_id AND delivery.state='sent'",
            'wecom' => "JOIN sand_ai_wecom_delivery delivery
                ON delivery.task_id=t.id AND delivery.environment_id=t.environment_id AND delivery.state='sent'",
            default => throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel delivery platform is invalid'),
        };
        $platformPredicate = $platform === null
            ? "AND t.input#>>'{_channel,delivery_platform}' IS NULL"
            : "AND t.input#>>'{_channel,delivery_platform}'=?";
        if ($historyPlatform === null) {
            $platformPredicate .= "
                AND NOT EXISTS (SELECT 1 FROM sand_ai_feishu_delivery d WHERE d.task_id=t.id AND d.environment_id=t.environment_id)
                AND NOT EXISTS (SELECT 1 FROM sand_ai_wecom_delivery d WHERE d.task_id=t.id AND d.environment_id=t.environment_id)";
        }
        $parameters = [(int) $task->environment_id, $attribution->organizationId, $attribution->applicationId,
            $attribution->workloadClientId, (int) $task->id, $closedAfterTaskId,
            (string) $channel['channel_binding_id'], $scope, (string) $channel['revision'],
            (string) $agent['version_id'], (string) $agent['knowledge_publication_id'],
            (string) $agent['knowledge_version_id'], $agent['knowledge_scope_sha256']];
        if ($platform !== null) $parameters[] = $platform;
        $rows = Db::connect('pgsql')->query(
            "SELECT t.id,t.state,t.input,t.result,count(*) OVER() AS eligible_turns FROM sand_ai_task_run t
             {$deliveryJoin}
             WHERE t.environment_id=? AND t.organization_id=? AND t.application_id=? AND t.workload_client_id=?
               AND t.task_type='agent_run' AND t.state='succeeded' AND t.id<? AND t.id>?
               AND t.input#>>'{_channel,channel_binding_id}'=?
               AND t.input#>>'{_channel,conversation_scope}'=?
               AND t.input#>>'{_channel,revision}'=?
               AND t.input#>>'{_agent_definition,version_id}'=?
               AND t.input#>>'{_agent_definition,knowledge_publication_id}'=?
               AND t.input#>>'{_agent_definition,knowledge_version_id}'=?
               AND t.input#>>'{_agent_definition,knowledge_scope_sha256}'=?
               {$platformPredicate}
               AND t.result#>>'{review_required}'='false'
             ORDER BY t.id DESC LIMIT ?",
            [...$parameters, $turnLimit],
        );
        $history = ChannelConversationPolicy::history($rows, $channel, $agent, $identityScope, $turnLimit);
        $history['omitted_prior_turn_count'] = $rows === [] ? ($turnLimit === 0 ? null : 0)
            : max(0, (int) $rows[0]['eligible_turns'] - count($rows));
        $history['closed_after_task_id'] = $closedAfterTaskId;
        return $history;
    }

    /** @param array<string,mixed> $agent */
    private function assertAgentSnapshot(array $agent): void
    {
        foreach (['version_id', 'knowledge_publication_id', 'knowledge_version_id'] as $field) {
            if (!is_int($agent[$field] ?? null) || $agent[$field] < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel agent snapshot is invalid');
            }
        }
        if (!is_string($agent['knowledge_scope_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $agent['knowledge_scope_sha256']) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel agent snapshot is invalid');
        }
    }

    /** @param array<string,mixed> $channel */
    private function scope(array $channel): string
    {
        $scope = $channel['conversation_scope'] ?? null;
        if (!is_int($channel['channel_binding_id'] ?? null) || $channel['channel_binding_id'] < 1
            || !is_string($scope) || preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel conversation scope is invalid');
        }
        return $scope;
    }
}
