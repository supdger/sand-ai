<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamRuntime;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use support\Request;
use think\facade\Db;
use Throwable;

final class WecomWebhookGateway
{
    public function verify(Request $request, string $code): string
    {
        $credentials = WecomChannelCredentials::forCode($code);
        $challenge = (new WecomEventDecoder())->verifyUrl($this->query($request), $credentials['token'],
            $credentials['encoding_aes_key'], $credentials['corp_id'], time());
        $scope = WecomChannelStatusGateway::managedScope($credentials);
        if ($scope !== null) (new WecomChannelStatusGateway())->verified($scope[0], $scope[1]);
        return $challenge;
    }

    /** @return array{task_id?:int,idempotent_replay:bool,conversation_closed?:bool,closed_after_task_id?:int} */
    public function receive(Request $request, string $code, string $requestId): array
    {
        $credentials = WecomChannelCredentials::forCode($code);
        $message = (new WecomEventDecoder())->message(
            $request->rawBody(), $this->query($request), $credentials['token'],
            $credentials['encoding_aes_key'], $credentials['corp_id'], $credentials['agent_id'], time(),
        );
        $status = new WecomChannelStatusGateway();
        $managedScope = WecomChannelStatusGateway::managedScope($credentials);
        if ($managedScope !== null) $status->verified($managedScope[0], $managedScope[1]);
        try {
            $closeConversation = $message['conversation_close'] === true;
            $sourceIp = $request->getLocalIp();
            if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::') {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Local service address is unavailable');
            }
            $host = HostSandIamRuntime::identityContextProvider();
            if ($host === null || !method_exists($host, 'issue')) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM workload issuer is unavailable');
            }
            try {
                $issued = $host->issue(
                    $credentials['workload_credential'], 'sand-ai',
                    $closeConversation ? ['sand_ai.agent.submit', 'sand_ai.task.read']
                        : ['sand_ai.agent.submit', 'sand_ai.agent.run', 'sand_ai.knowledge.query'], null,
                    self::childRequestId($requestId, 'issue'), $sourceIp, 'sand_ai',
                );
                $signed = $issued['context'] ?? null;
                if (!is_string($signed) || $signed === '') throw new \RuntimeException('Missing SandIAM context');
                $provider = new HostSandIamIdentityContextProvider($host);
                $identity = $provider->requireSignedContext($signed, 'sand_ai.agent.submit', $sourceIp, self::childRequestId($requestId, 'submit'));
                $runIdentity = $provider->requireSignedContext($signed,
                    $closeConversation ? 'sand_ai.task.read' : 'sand_ai.agent.run',
                    $sourceIp, self::childRequestId($requestId, $closeConversation ? 'read' : 'run'));
                $knowledgeIdentity = $closeConversation ? null
                    : $provider->requireSignedContext($signed, 'sand_ai.knowledge.query', $sourceIp, self::childRequestId($requestId, 'knowledge'));
                if ($identity->organizationId !== $runIdentity->organizationId
                    || (!$closeConversation && $identity->organizationId !== $knowledgeIdentity->organizationId)
                    || !hash_equals(RequestIdempotencyKey::trustedScope($identity), RequestIdempotencyKey::trustedScope($runIdentity))
                    || (!$closeConversation && !hash_equals(RequestIdempotencyKey::trustedScope($identity), RequestIdempotencyKey::trustedScope($knowledgeIdentity)))) {
                    throw new \RuntimeException('SandIAM workload identity changed');
                }
            } catch (Throwable) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM workload authorization failed');
            }
            $channel = (new ChannelBindingGateway())->resolve($identity->environmentId, $code, $identity);
            WecomChannelCredentials::assertManagedScope(
                $credentials, $identity->environmentId, $channel['binding']['channel_binding_id'],
            );
            if ($managedScope === null) $status->verified($identity->environmentId, $channel['binding']['channel_binding_id']);
            $selected = ChannelWebhookMessageIdentity::resolve($identity, $code,
                $channel['binding']['channel_binding_id'], [
                'message_id' => $message['message_id'], 'conversation_id' => $message['conversation_id'],
                'sender_id' => $message['sender_id'], 'query' => $message['query'],
            ], 'wecom');
            $normalized = $selected['message'];
            $binding = [...$channel['binding'],
                'conversation_scope' => $normalized['conversation_scope'],
                'message_scope' => $normalized['message_scope'],
            ];
            if (!$selected['legacy']) $binding['delivery_platform'] = 'wecom';
            $key = $selected['key'];
            if ($closeConversation) {
                if ($selected['legacy']) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The channel message is already an agent task');
                }
                $closed = ChannelWebhookConversationControl::close(
                    $identity, $provider, $signed, $sourceIp, $requestId, $binding, $key,
                );
                return $closed;
            }
            $readonlyBindings = $channel['agent']['binding']['readonly_tool_bindings'] ?? [];
            if (!is_array($readonlyBindings) || !array_is_list($readonlyBindings)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Published agent readonly bindings are unavailable');
            }
            if ($readonlyBindings !== []) {
                (new \app\Application\Agent\AgentReadonlyToolCatalog())->assertCurrent($identity->environmentId, $readonlyBindings);
            }
            // History replay (AgentReadonlyToolReadGuard::authorizeQueuedHistory) requires sand_ai.tool.read and
            // sand_ai.task.read on the queued signed context whenever a prior turn produced any tool run (e.g. a
            // knowledge retrieval), independent of whether this agent has readonly_tools configured.
            try {
                $actions = ['sand_ai.agent.submit', 'sand_ai.agent.run', 'sand_ai.knowledge.query',
                    'sand_ai.tool.run', 'sand_ai.tool.read', 'sand_ai.task.read'];
                $toolIssued = $host->issue($credentials['workload_credential'], 'sand-ai', $actions, null,
                    self::childRequestId($requestId, 'issue-readonly'), $sourceIp, 'sand_ai');
                $toolSigned = $toolIssued['context'] ?? null;
                if (!is_string($toolSigned) || $toolSigned === '') throw new \RuntimeException('Missing SandIAM context');
                $toolSubmit = $provider->requireSignedContext($toolSigned, 'sand_ai.agent.submit', $sourceIp,
                    self::childRequestId($requestId, 'readonly-submit'));
                foreach (['organizationId', 'applicationId', 'environmentId', 'workloadClientId', 'audience'] as $dimension) {
                    if ($toolSubmit->{$dimension} !== $identity->{$dimension}) throw new \RuntimeException('SandIAM workload identity changed');
                }
                foreach ($actions as $action) {
                    $part = $provider->requireSignedContext($toolSigned, $action, $sourceIp,
                        self::childRequestId($requestId, 'readonly-' . $action));
                    foreach (['organizationId', 'applicationId', 'environmentId', 'workloadClientId', 'audience', 'expiresAt'] as $dimension) {
                        if ($part->{$dimension} !== $toolSubmit->{$dimension}) throw new \RuntimeException('SandIAM workload identity changed');
                    }
                }
                $signed = $toolSigned;
                $identity = $toolSubmit;
            } catch (Throwable) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SandIAM readonly tool authorization failed');
            }
            $authorizationHeader = 'Bearer ' . $signed;
            $run = (new AgentGateway())->submit(
                $identity->environmentId,
                [...$channel['agent']['config'], 'query' => $normalized['query']],
                $key,
                UsageAttribution::fromIdentity($identity, 'agent', 'sand_ai.agent.submit'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorizeSigned(
                    $authorizationHeader, $sourceIp, $identity, $taskId, 'agent_run', $requestId,
                ),
                $channel['agent']['binding'], $binding,
            );
            $taskId = (int) $run['task']['id'];
            $envelope = (new WecomDeliveryCipher((array) config('sand_ai_iam_context', [])))->seal(
                $message['receiver_id'], $taskId, $identity->environmentId, $code,
            );
            $db = Db::connect('pgsql');
            $db->execute(
                'INSERT INTO sand_ai_wecom_delivery (task_id,environment_id,channel_binding_id,channel_code,channel_revision,message_scope,receiver_envelope)
                 VALUES (?,?,?,?,?,?,CAST(? AS jsonb)) ON CONFLICT (task_id) DO NOTHING',
                [$taskId, $identity->environmentId, $binding['channel_binding_id'], $code,
                    $binding['revision'], $normalized['message_scope'], json_encode($envelope, JSON_THROW_ON_ERROR)],
            );
            $rows = $db->query('SELECT environment_id,channel_binding_id,channel_code,channel_revision,message_scope
                FROM sand_ai_wecom_delivery WHERE task_id=?', [$taskId]);
            $stored = $rows[0] ?? null;
            if (!is_array($stored) || (int) $stored['environment_id'] !== $identity->environmentId
                || (int) $stored['channel_binding_id'] !== $binding['channel_binding_id']
                || (string) $stored['channel_code'] !== $code || (int) $stored['channel_revision'] !== $binding['revision']
                || !hash_equals((string) $stored['message_scope'], $normalized['message_scope'])) {
                throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'WeCom delivery binding changed');
            }
            $status->accepted($identity->environmentId, $channel['binding']['channel_binding_id']);
            return ['task_id' => $taskId, 'idempotent_replay' => (bool) $run['idempotent_replay']];
        } catch (Throwable $exception) {
            $scope = $managedScope;
            if ($scope === null && isset($identity, $channel)) {
                $scope = [$identity->environmentId, $channel['binding']['channel_binding_id']];
            }
            if ($scope !== null) {
                $status->error($scope[0], $scope[1],
                    $exception instanceof ApiProblem ? $exception->errorCode : 'SAND_AI_CHANNEL_CALLBACK_FAILED');
            }
            throw $exception;
        }
    }

    private static function childRequestId(string $parent, string $action): string
    {
        return 'wecom-' . substr(hash('sha256', $parent . ':' . $action), 0, 40);
    }

    /** @return array<string,mixed> */
    private function query(Request $request): array
    {
        $query = $request->get();
        if (!is_array($query) || array_is_list($query)) {
            throw new ApiProblem('SAND_AI_CHANNEL_CALLBACK_INVALID', 'WeCom callback query is invalid');
        }
        return $query;
    }
}
