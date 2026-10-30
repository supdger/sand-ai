<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Task\TaskGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamRuntime;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;
use Throwable;

/** Delivers terminal channel tasks from a durable, leased outbox. */
final class FeishuDeliveryGateway
{
    public function runOne(): bool
    {
        $row = $this->claim();
        if ($row === null) return false;
        $taskId = (int) $row['task_id'];
        $lease = (string) $row['lease_token'];
        $confirmedMessageScope = null;
        try {
            [$response, $credentials, $beforeSend] = $this->authorizedResponse($row);
            $envelope = is_string($row['chat_envelope'])
                ? json_decode($row['chat_envelope'], true, 16, JSON_THROW_ON_ERROR) : $row['chat_envelope'];
            if (is_object($envelope)) $envelope = (array) $envelope;
            if (!is_array($envelope)) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery target is unavailable');
            $chatId = (new FeishuDeliveryCipher((array) config('sand_ai_iam_context', [])))->open(
                $envelope, $taskId, (int) $row['environment_id'], (string) $row['channel_code'],
            );
            $sent = (new FeishuMessageClient())->sendText(
                $credentials['app_id'], $credentials['app_secret'], $chatId, $response,
                FeishuDeliveryCipher::uuid($taskId), $beforeSend, $credentials['api_base'] ?? null,
            );
            $confirmedMessageScope = hash('sha256', $sent['message_id']);
            $this->finish($taskId, $lease, 'sent', $confirmedMessageScope, null);
        } catch (Throwable $exception) {
            if ($confirmedMessageScope !== null) {
                $this->resolveConfirmedSend($row, $lease, $confirmedMessageScope);
                return true;
            }
            $terminal = $exception instanceof ApiProblem && in_array($exception->errorCode, [
                'SAND_AI_RESOURCE_NOT_FOUND', 'SAND_AI_SCOPE_SNAPSHOT_INVALID',
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'SAND_AI_SOURCE_UNVERIFIED',
                'SAND_AI_CHANNEL_DELIVERY_INVALID', 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            ], true);
            $code = $exception instanceof ApiProblem ? $exception->errorCode : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE';
            if ($code === 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN') {
                $this->uncertain($row, $lease);
            } else {
                $this->fail($row, $lease, $code, $terminal || (int) $row['attempt_count'] >= 5);
            }
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    private function claim(): ?array
    {
        return Db::connect('pgsql')->transaction(function (): ?array {
            $db = Db::connect('pgsql');
            $expired = $db->query("SELECT task_id,environment_id,channel_binding_id FROM sand_ai_feishu_delivery
                WHERE state='sending' AND lease_until<=CURRENT_TIMESTAMP
                ORDER BY lease_until,task_id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if ($expired !== []) {
                $row = $expired[0];
                $db->execute("UPDATE sand_ai_feishu_delivery SET state='uncertain',
                    error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',lease_token=NULL,lease_until=NULL,
                    update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending'", [(int) $row['task_id']]);
                (new FeishuChannelStatusGateway())->deliveryError(
                    (int) $row['environment_id'], (int) $row['channel_binding_id'], 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',
                );
                return null;
            }
            $rows = $db->query("SELECT d.* FROM sand_ai_feishu_delivery d
                JOIN sand_ai_task_run t ON t.id=d.task_id AND t.environment_id=d.environment_id
                WHERE d.state='pending' AND d.available_at<=CURRENT_TIMESTAMP
                  AND t.state IN ('succeeded','failed','canceled')
                ORDER BY d.available_at,d.task_id LIMIT 1 FOR UPDATE OF d SKIP LOCKED");
            if ($rows === []) return null;
            $row = $rows[0];
            if ((int) $row['attempt_count'] >= 5) {
                $changed = $db->execute("UPDATE sand_ai_feishu_delivery SET state='failed',error_code='SAND_AI_CHANNEL_DELIVERY_EXHAUSTED',
                    lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE task_id=?", [(int) $row['task_id']]);
                if ($changed === 1) (new FeishuChannelStatusGateway())->deliveryError(
                    (int) $row['environment_id'], (int) $row['channel_binding_id'], 'SAND_AI_CHANNEL_DELIVERY_EXHAUSTED',
                );
                return null;
            }
            $lease = bin2hex(random_bytes(16));
            $db->execute("UPDATE sand_ai_feishu_delivery SET state='sending',attempt_count=attempt_count+1,
                lease_token=?,lease_until=CURRENT_TIMESTAMP + INTERVAL '30 seconds',update_time=CURRENT_TIMESTAMP(0)
                WHERE task_id=?", [$lease, (int) $row['task_id']]);
            return [...$row, 'lease_token' => $lease, 'attempt_count' => (int) $row['attempt_count'] + 1];
        });
    }

    /** A confirmed remote send is reconciled only against the original local outbox. */
    private function resolveConfirmedSend(array $row, string $lease, string $messageScope): void
    {
        $rows = Db::connect('pgsql')->query('SELECT state,sent_message_scope FROM sand_ai_feishu_delivery WHERE task_id=?',
            [(int) $row['task_id']]);
        if (($rows[0]['state'] ?? null) === 'sent') {
            if (!is_string($rows[0]['sent_message_scope'])
                || !hash_equals($messageScope, $rows[0]['sent_message_scope'])) {
                throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Confirmed delivery receipt changed');
            }
            return;
        }
        $this->uncertain($row, $lease);
    }

    /** Never requeues an outcome that cannot prove whether the text was delivered. */
    private function uncertain(array $row, string $lease): void
    {
        $db = Db::connect('pgsql');
        $changed = $db->execute("UPDATE sand_ai_feishu_delivery SET state='uncertain',
            error_code='SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',lease_token=NULL,lease_until=NULL,
            update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending' AND lease_token=?",
            [(int) $row['task_id'], $lease]);
        if ($changed === 1) {
            (new FeishuChannelStatusGateway())->deliveryError(
                (int) $row['environment_id'], (int) $row['channel_binding_id'], 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN',
            );
            return;
        }
        $rows = $db->query('SELECT state FROM sand_ai_feishu_delivery WHERE task_id=?', [(int) $row['task_id']]);
        if (in_array($rows[0]['state'] ?? null, ['sent', 'uncertain'], true)) return;
        throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery outcome could not be reconciled');
    }

    /** @param array<string,mixed> $row */
    /** @return array{string,array<string,string>,\Closure} */
    private function authorizedResponse(array $row): array
    {
        $code = (string) $row['channel_code'];
        $credentials = FeishuChannelCredentials::forCode($code);
        $sourceIp = (string) config('sand_ai_feishu.worker_source_ip', '');
        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::') {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery source address is unavailable');
        }
        $host = HostSandIamRuntime::identityContextProvider();
        if ($host === null || !method_exists($host, 'issue')) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM workload issuer is unavailable');
        }
        $taskId = (int) $row['task_id'];
        $requestId = 'feishu-delivery-' . substr(hash('sha256', $taskId . ':' . $row['attempt_count']), 0, 40);
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
        FeishuChannelCredentials::assertManagedScope(
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
            $outbox = Db::connect('pgsql')->query('SELECT 1 FROM sand_ai_feishu_delivery WHERE task_id=? AND state=\'sending\' AND lease_token=? AND lease_until>CURRENT_TIMESTAMP',
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

    private function finish(int $taskId, string $lease, string $state, ?string $messageScope, ?string $error): void
    {
        $changed = Db::connect('pgsql')->execute('UPDATE sand_ai_feishu_delivery SET state=?,sent_message_scope=?,error_code=?,
            lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state=? AND lease_token=?',
            [$state, $messageScope, $error, $taskId, 'sending', $lease]);
        if ($changed !== 1) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Delivery lease changed');
    }

    /** @param array<string,mixed> $row */
    private function fail(array $row, string $lease, string $code, bool $terminal): void
    {
        $state = $terminal ? 'failed' : 'pending';
        $delay = $terminal ? 0 : 10;
        $changed = Db::connect('pgsql')->execute("UPDATE sand_ai_feishu_delivery SET state=?,error_code=?,available_at=CURRENT_TIMESTAMP + (? * INTERVAL '1 second'),
            lease_token=NULL,lease_until=NULL,update_time=CURRENT_TIMESTAMP(0) WHERE task_id=? AND state='sending' AND lease_token=?",
            [$state, $code, $delay, (int) $row['task_id'], $lease]);
        if ($changed === 1) (new FeishuChannelStatusGateway())->deliveryError(
            (int) $row['environment_id'], (int) $row['channel_binding_id'], $code,
        );
    }
}
