<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentGateway;
use app\Application\Channel\ChannelBindingGateway;
use app\Application\Channel\ChannelBindingPolicy;
use app\Application\Channel\ChannelConversationGateway;
use app\Application\Channel\ChannelSourceGateway;
use app\Application\Channel\FeishuChannelCredentials;
use app\Application\Channel\WecomChannelCredentials;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Task\TaskGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;
use support\Response;
use app\Application\Agent\AgentStreamEventGateway;
use app\Application\Channel\ChannelConversationPolicy;
use Workerman\Timer;
use Workerman\Protocols\Http\ServerSentEvents;

final class ChannelController
{
    /** Current original caller intent; only sealed original provider outcomes may run. */
    public function recoverReceipt(Request $request, string $code, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $body = $request->post();
            if ($id === false || $request->get() !== [] || !is_array($body)
                || array_diff(array_keys($body), ['conversation_id', 'sender_id', 'expected_task_version', 'expected_lease_fence', 'delivery_platform']) !== []
                || !is_string($body['conversation_id'] ?? null) || !is_string($body['sender_id'] ?? null)
                || !is_int($body['expected_task_version'] ?? null) || $body['expected_task_version'] < 1
                || !is_int($body['expected_lease_fence'] ?? null) || $body['expected_lease_fence'] < 0) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel receipt recovery request is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $read = $authenticator->authenticate($request, 'sand_ai.task.read');
            $retry = $authenticator->authenticate($request, 'sand_ai.task.retry');
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            $scope = RequestIdempotencyKey::trustedScopeForAttribution(
                UsageAttribution::fromIdentity($submit['context'], 'agent', 'sand_ai.agent.submit'),
            );
            foreach ([$read, $retry, $run, $knowledge] as $part) {
                if (!hash_equals($scope, RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($part['context'], 'agent', 'sand_ai.agent.submit'),
                ))) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel caller not found');
            }
            $channel = (new ChannelBindingGateway())->resolve($submit['environment_id'], $code, $submit['context']);
            $this->platform($submit, $channel, $body['delivery_platform'] ?? null);
            $platform = $channel['binding']['delivery_platform'] ?? null;
            $conversationScope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'],
                $body['conversation_id'], $body['sender_id'], $platform);
            $key = RequestIdempotencyKey::requiredForResource($request, 'channel-receipt-recovery', $submit['context'], (string) $id);
            $target = ['code' => $code, 'channel_binding_id' => $channel['binding']['channel_binding_id'],
                'conversation_scope' => $conversationScope, 'delivery_platform' => $platform,
                'expected_task_version' => $body['expected_task_version'], 'expected_lease_fence' => $body['expected_lease_fence'],
                'caller_intent_sha256' => hash('sha256', trim((string) $request->header('idempotency-key', '')))];
            $authorizer = new HostSandIamInvocationAuthorizer();
            $operation = new TaskOperationAccessGuard($authorizer);
            $currentRead = function (TaskRun $task) use ($request, $read, $submit, $run, $knowledge, $requestId, $authorizer): void {
                (new TaskRunAccessGuard($authorizer))->authorizeRead($request, $submit['context'], $task, $requestId);
                (new AgentKnowledgeAccessGuard($authorizer))->authorizeRead($request, $knowledge['context'], $task, $requestId);
                (new AgentRetrievedSourceGuard($authorizer))->authorizeRead($request, $submit['context'], $task, $requestId);
                (new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer))
                    ->authorizeTask($request, $run['context'], $task, $requestId, true)();
            };
            $result = (new \app\Application\Channel\ChannelReceiptRecoveryGateway())->recover(
                $submit['environment_id'], (int) $id, $scope, $key, $target,
                function (TaskRun $task) use ($request, $read, $retry, $submit, $run, $requestId, $authorizer, $operation, $currentRead): array {
                    $currentRead($task);
                    return [
                        'task_authorization' => (new TaskRunAccessGuard($authorizer))->authorize(
                            $request, $submit['context'], (int) $task->id, 'agent_run', $requestId),
                        'read_decision' => $operation->authorize($request, $read['context'], (int) $task->id, 'sand_ai.task.read', $requestId),
                        'retry_decision' => $operation->authorize($request, $retry['context'], (int) $task->id, 'sand_ai.task.retry', $requestId),
                        'run_decision' => $authorizer->authorize($request, $run['context'], 'sand_ai.agent.run', 'sand_ai.task_run',
                            (string) $task->id, 'channel-receipt-recovery:' . bin2hex(random_bytes(16)), $requestId),
                    ];
                },
                static function (TaskRun $task, array $authorization) use ($request, $read, $retry, $run, $requestId, $authorizer, $operation, $currentRead): void {
                    $currentRead($task);
                    (new TaskRunAccessGuard($authorizer))->revalidateQueued($authorization['task_authorization'], $task);
                    $operation->revalidate($request, $read['context'], (int) $task->id, 'sand_ai.task.read', $requestId, $authorization['read_decision']);
                    $operation->revalidate($request, $retry['context'], (int) $task->id, 'sand_ai.task.retry', $requestId, $authorization['retry_decision']);
                    $signed = trim(substr(trim((string) $request->header('authorization', '')), 7));
                    $authorizer->revalidate($authorization['run_decision']['authorization_id'], $signed, $run['context'],
                        'sand_ai.agent.run', 'sand_ai.task_run', (string) $task->id, $request->getRemoteIp(), $requestId);
                },
            );
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Find the accepted original authorization intent without reissuing any work. */
    public function readReceiptRecovery(Request $request, string $code, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $query = $request->get();
            if ($id === false || !is_array($query)
                || array_diff(array_keys($query), ['request_key', 'conversation_id', 'sender_id', 'delivery_platform']) !== []
                || !is_string($query['request_key'] ?? null)
                || !is_string($query['conversation_id'] ?? null) || !is_string($query['sender_id'] ?? null)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel recovery lookup is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $read = $authenticator->authenticate($request, 'sand_ai.task.read');
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $scope = RequestIdempotencyKey::trustedScopeForAttribution(
                UsageAttribution::fromIdentity($submit['context'], 'agent', 'sand_ai.agent.submit'),
            );
            foreach ([$read, $knowledge, $run] as $part) {
                if (!hash_equals($scope, RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($part['context'], 'agent', 'sand_ai.agent.submit'),
                ))) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel caller not found');
            }
            $channel = (new ChannelBindingGateway())->resolve($submit['environment_id'], $code, $submit['context']);
            $this->platform($submit, $channel, $query['delivery_platform'] ?? null);
            $platform = $channel['binding']['delivery_platform'] ?? null;
            $conversationScope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'],
                $query['conversation_id'], $query['sender_id'], $platform);
            // Validate and derive exactly as POST, using the caller's original intent key.
            $request->setHeader('idempotency-key', $query['request_key']);
            $key = RequestIdempotencyKey::requiredForResource($request, 'channel-receipt-recovery', $submit['context'], (string) $id);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $operation = new TaskOperationAccessGuard($authorizer);
            $result = (new \app\Application\Channel\ChannelReceiptRecoveryGateway())->readRecovery(
                $submit['environment_id'], (int) $id, $scope, $key,
                ['code' => $code, 'channel_binding_id' => $channel['binding']['channel_binding_id'],
                    'conversation_scope' => $conversationScope, 'delivery_platform' => $platform],
                static function (TaskRun $task) use ($request, $read, $submit, $run, $knowledge, $requestId, $authorizer, $operation): void {
                    $decision = $operation->authorize($request, $read['context'], (int) $task->id, 'sand_ai.task.read', $requestId);
                    (new TaskRunAccessGuard($authorizer))->authorizeRead($request, $submit['context'], $task, $requestId);
                    (new AgentKnowledgeAccessGuard($authorizer))->authorizeRead($request, $knowledge['context'], $task, $requestId);
                    (new AgentRetrievedSourceGuard($authorizer))->authorizeRead($request, $submit['context'], $task, $requestId);
                    (new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer))
                        ->authorizeTask($request, $run['context'], $task, $requestId, true)();
                    $operation->revalidate($request, $read['context'], (int) $task->id, 'sand_ai.task.read', $requestId, $decision);
                },
            );
            $result['task']['result'] = null;
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function submit(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($run['context']))
                || !hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($knowledge['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $body = $request->post();
            if (!is_array($body)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel message is invalid');
            $message = ChannelBindingPolicy::message($body, $channel['binding']['channel_binding_id']);
            $binding = [...$channel['binding'], 'conversation_scope' => $message['conversation_scope'], 'message_scope' => $message['message_scope']];
            $key = RequestIdempotencyKey::requiredForResource($request, 'channel', $context['context'], $code . ':' . $message['message_scope']);
            return ApiResponder::success((new AgentGateway())->submit(
                $context['environment_id'], [...$channel['agent']['config'], 'query' => $message['query']], $key,
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorize(
                    $request, $context['context'], $taskId, 'agent_run', $requestId,
                ),
                $channel['agent']['binding'], $binding,
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Finds an accepted original message without starting another model invocation. */
    public function readByRequestKey(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($agent['context']))
                || !hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($knowledge['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
            $messageId = $request->get('message_id');
            $conversationId = $request->get('conversation_id');
            $senderId = $request->get('sender_id');
            if (!is_string($messageId) || !is_string($conversationId) || !is_string($senderId)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel recovery request is invalid');
            }
            $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId, $channel['binding']['delivery_platform'] ?? null);
            $messageScope = ChannelBindingPolicy::messageScope($channel['binding']['channel_binding_id'], $messageId, $channel['binding']['delivery_platform'] ?? null);
            if (isset($channel['binding']['delivery_platform']) && !hash_equals($messageId, (string) $request->header('idempotency-key', ''))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Platform recovery requires the original message key');
            }
            $key = RequestIdempotencyKey::requiredForResource($request, 'channel', $context['context'], $code . ':' . $messageScope);
            $task = TaskRun::where('environment_id', $context['environment_id'])
                ->where('request_id', $key)->where('task_type', 'agent_run')->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel message not found');
            return ApiResponder::success($this->readTask(
                $request, $context, $agent, $knowledge, $channel, $scope, (int) $task->id, $requestId,
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Replays native provider deltas from the same accepted task; never starts inference. */
    public function stream(Request $request, string $code, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $connection = $request->connection;
            if ($connection === null || preg_match('/\A[1-9][0-9]*\z/D', $taskId) !== 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent stream request is invalid');
            }
            $conversationId = $request->get('conversation_id');
            $senderId = $request->get('sender_id');
            if (!is_string($conversationId) || !is_string($senderId)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent stream conversation is invalid');
            }
            $headerCursor = (string) $request->header('last-event-id', '');
            $queryCursor = $request->get('after_event_id');
            $cursor = $queryCursor ?? ($headerCursor === '' ? '0' : $headerCursor);
            if (!is_string($cursor) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $cursor) !== 1
                || filter_var($cursor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4096]]) === false
                || ($headerCursor !== '' && $queryCursor !== null && $headerCursor !== $queryCursor)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent stream cursor is invalid');
            }
            $lastEvent = (int) $cursor;
            $streamAuthorizer = HostSandIamInvocationAuthorizer::forHttpStream($request, $requestId);
            $readCurrent = function () use ($request, $code, $taskId, $conversationId, $senderId, $requestId, $streamAuthorizer): array {
                $authenticator = new SandIamContextAuthenticator();
                $context = $authenticator->authenticate($request, 'sand_ai.task.read');
                $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
                $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
                foreach ([$agent, $knowledge] as $part) {
                    if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($part['context']))) {
                        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent stream identity changed');
                    }
                }
                $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
                $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId, $channel['binding']['delivery_platform'] ?? null);
                $result = $this->readTask($request, $context, $agent, $knowledge, $channel, $scope, (int) $taskId, $requestId, $streamAuthorizer);
                $task = TaskRun::where('id', (int) $taskId)->where('environment_id', $context['environment_id'])->find();
                $input = $task === null ? null : (is_object($task->input) ? (array) $task->input : $task->input);
                if ($task === null || !is_array($input) || ($input['_agent_stream'] ?? false) !== true) {
                    throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'This task has no native token stream');
                }
                return [$result['task'], $task, $agent['context']];
            };
            $readCurrent();
            $connection->maxSendBufferSize = 65536;
            $paused = false;
            $timerId = null;
            $startedAt = time();
            $lastHeartbeat = 0;
            $lastPayload = null;
            $previousClose = $connection->onClose;
            $connection->onBufferFull = static function () use (&$paused): void { $paused = true; };
            $connection->onBufferDrain = static function () use (&$paused): void { $paused = false; };
            $connection->onClose = static function ($closed) use (&$timerId, $previousClose): void {
                if ($timerId !== null) Timer::del($timerId);
                $timerId = null;
                if (is_callable($previousClose)) $previousClose($closed);
            };
            $send = static function (array $event) use ($connection): void {
                if ($connection->send((string) new ServerSentEvents($event), true) === false) {
                    throw new ApiProblem('SAND_AI_AGENT_STREAM_INTERRUPTED', 'Agent stream consumer is too slow');
                }
            };
            $timerId = Timer::add(0.5, function () use ($request, $connection, $readCurrent, $send, $requestId, $streamAuthorizer,
                $startedAt, &$timerId, &$paused, &$lastEvent, &$lastPayload, &$lastHeartbeat): void {
                if ($connection->getStatus() === $connection::STATUS_CLOSED) {
                    if ($timerId !== null) Timer::del($timerId);
                    $timerId = null;
                    return;
                }
                if ($paused) {
                    // Bounded persistent producer continues independently; a slow subscriber resumes by cursor.
                    if ($timerId !== null) Timer::del($timerId);
                    $timerId = null;
                    $connection->close();
                    return;
                }
                try {
                    [$publicTask, $task, $identity] = $readCurrent();
                    $events = (new AgentStreamEventGateway())->read($task, $lastEvent);
                    $references = [];
                    foreach ($events as $event) $references = ChannelConversationPolicy::sourceReferences([...$references, ...$event['source_references']]);
                    foreach (array_chunk($references, 8) as $group) {
                        (new AgentRetrievedSourceGuard($streamAuthorizer))->authorizeStreamReferences($request, $identity, $task, $group, $requestId);
                    }
                    // Recheck task/binding/version after authorizing this private source closure.
                    $readCurrent();
                    foreach ($events as $event) {
                        $send(['event' => 'token', 'id' => (string) $event['event_no'],
                            'data' => json_encode(['task_id' => $event['task_id'], 'event_no' => $event['event_no'],
                                'round' => $event['round'], 'text' => $event['text'], 'provisional' => true,
                                'source_block_ids' => array_column($event['source_references'], 'source_block_id')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                        $lastEvent = $event['event_no'];
                    }
                    $payload = json_encode(['task' => $publicTask], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    if ($payload !== $lastPayload) {
                        $send(['event' => 'task', 'data' => $payload]);
                        $lastPayload = $payload;
                        $lastHeartbeat = time();
                    } elseif (time() - $lastHeartbeat >= 15) {
                        $send(['' => 'keepalive']);
                        $lastHeartbeat = time();
                    }
                    if ((in_array($publicTask['state'], ['succeeded', 'failed', 'canceled', 'recovery_pending'], true) && count($events) < 32)
                        || time() - $startedAt >= 90) {
                        if (time() - $startedAt >= 90) $send(['event' => 'reconnect', 'data' => '{}']);
                        if ($timerId !== null) Timer::del($timerId);
                        $timerId = null;
                        $connection->close();
                    }
                } catch (\Throwable $failure) {
                    $code = $failure instanceof ApiProblem ? $failure->errorCode : 'SAND_AI_AGENT_STREAM_UNAVAILABLE';
                    try { $send(['event' => 'error', 'data' => json_encode(['error_code' => $code], JSON_THROW_ON_ERROR)]); } catch (\Throwable) {}
                    if ($timerId !== null) Timer::del($timerId);
                    $timerId = null;
                    $connection->close();
                }
            });
            return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store',
                'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive'], ": connected\n\n");
        } catch (ApiProblem $problem) { return ApiResponder::error($problem, $requestId); }
    }

    public function read(Request $request, string $code, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($agent['context']))
                || !hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($knowledge['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
            $conversationId = $request->get('conversation_id');
            $senderId = $request->get('sender_id');
            if (!is_string($conversationId) || !is_string($senderId) || preg_match('/^[1-9][0-9]*$/D', $taskId) !== 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel read request is invalid');
            }
            $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId, $channel['binding']['delivery_platform'] ?? null);
            $id = (int) $taskId;
            if ($id < 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel task is invalid');
            $result = $this->readTask($request, $context, $agent, $knowledge, $channel, $scope, $id, $requestId);
            return ApiResponder::success($result['task'], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Reads an actual referenced private source without starting retrieval or inference. */
    public function source(Request $request, string $code, string $taskId, string $sourceBlockId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $blockId = filter_var($sourceBlockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $event = $request->get('event_no');
            $eventNo = $event === null ? null : (is_string($event)
                ? filter_var($event, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4096]]) : false);
            $conversationId = $request->get('conversation_id');
            $senderId = $request->get('sender_id');
            if ($id === false || $blockId === false || $eventNo === false
                || !is_string($conversationId) || !is_string($senderId)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel source request is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            foreach ([$agent, $knowledge] as $part) {
                if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($part['context']))) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
                }
            }
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
            $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId, $channel['binding']['delivery_platform'] ?? null);
            // One request retains only official authorization IDs; every later check is a live revalidation.
            $authorizer = HostSandIamInvocationAuthorizer::forHttpStream($request, $requestId);
            $this->readTask($request, $context, $agent, $knowledge, $channel, $scope, (int) $id, $requestId, $authorizer);
            $task = TaskRun::where('id', (int) $id)->where('environment_id', $context['environment_id'])->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
            $gateway = new ChannelSourceGateway();
            $source = $gateway->read($task, (int) $blockId, $eventNo);
            $reference = array_intersect_key($source, array_flip(['source_block_id', 'file_id', 'locator_type', 'locator']));
            $guard = new AgentRetrievedSourceGuard($authorizer);
            $guard->authorizeStreamReferences($request, $agent['context'], $task, [$reference], $requestId);
            $this->readTask($request, $context, $agent, $knowledge, $channel, $scope, (int) $id, $requestId, $authorizer);
            $task = TaskRun::where('id', (int) $id)->where('environment_id', $context['environment_id'])->find();
            if ($task === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
            $current = $gateway->read($task, (int) $blockId, $eventNo);
            if ($source !== $current) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel source changed during read');
            }
            $guard->authorizeStreamReferences($request, $agent['context'], $task, [$reference], $requestId);
            return ApiResponder::success($current, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function conversation(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($agent['context']))
                || !hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($knowledge['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
            $senderId = $request->get('sender_id');
            $before = $request->get('before_task_id');
            $limit = $request->get('limit', '20');
            if (!is_string($senderId) || ($before !== null && !is_string($before))
                || !is_string($limit) || preg_match('/^[1-9][0-9]*$/D', $limit) !== 1
                || (int) $limit > 20 || ($before !== null && preg_match('/^[1-9][0-9]*$/D', $before) !== 1)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel conversation request is invalid');
            }
            $beforeId = $before === null ? null : filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($beforeId === false) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel cursor is invalid');
            $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId, $channel['binding']['delivery_platform'] ?? null);
            $binding = [...$channel['binding'], 'conversation_scope' => $scope];
            $attribution = UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.task.read');
            $ids = (new ChannelConversationGateway())->taskIds(
                $context['environment_id'],
                $attribution,
                $binding, $channel['agent']['binding'], $beforeId, (int) $limit + 1,
            );
            $hasMore = count($ids) > (int) $limit;
            $ids = array_slice($ids, 0, (int) $limit);
            $messages = [];
            foreach ($ids as $id) {
                $messages[] = $this->readTask($request, $context, $agent, $knowledge, $channel, $scope, $id, $requestId);
            }
            return ApiResponder::success([
                'messages' => $messages,
                'next_before_task_id' => $hasMore && $ids !== [] ? end($ids) : null,
                'closed_after_task_id' => (new ChannelConversationGateway())->lastClosedTaskId(
                    $context['environment_id'], $binding,
                    RequestIdempotencyKey::trustedScopeForAttribution($attribution),
                ),
            ], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function closeConversation(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $read = $authenticator->authenticate($request, 'sand_ai.task.read');
            if (!hash_equals(
                RequestIdempotencyKey::trustedScope($context['context']),
                RequestIdempotencyKey::trustedScope($read['context']),
            )) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $body = $request->post();
            if (!is_array($body) || array_diff(array_keys($body), ['sender_id', 'delivery_platform']) !== []
                || !is_string($body['sender_id'] ?? null)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel close request is invalid');
            }
            $channel = (new ChannelBindingGateway())->resolve(
                $context['environment_id'], $code, $context['context'],
            );
            $this->platform($context, $channel, $body['delivery_platform'] ?? null);
            $scope = ChannelBindingPolicy::scope(
                $channel['binding']['channel_binding_id'], $conversationId, $body['sender_id'], $channel['binding']['delivery_platform'] ?? null,
            );
            $binding = [...$channel['binding'], 'conversation_scope' => $scope];
            $key = RequestIdempotencyKey::requiredForResource(
                $request, 'channel-close', $context['context'],
                $code . ':' . $scope,
            );
            $result = (new ChannelConversationGateway())->close(
                $context['environment_id'],
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                $binding, $key,
            );
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function readCloseByRequestKey(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $read = $authenticator->authenticate($request, 'sand_ai.task.read');
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($read['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel identity scope changed');
            }
            $senderId = $request->get('sender_id');
            if (!is_string($senderId)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel recovery sender is invalid');
            $channel = (new ChannelBindingGateway())->resolve($context['environment_id'], $code, $context['context']);
            $this->platform($context, $channel, $request->get('delivery_platform'));
            $scope = ChannelBindingPolicy::scope($channel['binding']['channel_binding_id'], $conversationId, $senderId,
                $channel['binding']['delivery_platform'] ?? null);
            $key = RequestIdempotencyKey::requiredForResource($request, 'channel-close', $context['context'], $code . ':' . $scope);
            $result = (new ChannelConversationGateway())->readClose($context['environment_id'],
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                [...$channel['binding'], 'conversation_scope' => $scope], $key);
            return ApiResponder::success([...$result, 'operation' => 'close',
                'request_key' => trim((string) $request->header('idempotency-key', '')),
                'environment_id' => $context['environment_id'], 'channel_binding_id' => $channel['binding']['channel_binding_id'],
                'code' => $code, 'conversation_id' => $conversationId, 'sender_id' => $senderId,
                'delivery_platform' => $channel['binding']['delivery_platform'] ?? null], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $channel */
    private function platform(array $context, array &$channel, mixed $value): void
    {
        if ($value === null) return;
        if (!is_string($value) || !in_array($value, ['feishu', 'wecom'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel platform is invalid');
        }
        // The same current configuration and managed owner checks used by verified callbacks.
        $code = $channel['binding']['code'];
        $bindingId = $channel['binding']['channel_binding_id'];
        if ($value === 'feishu') {
            $credentials = FeishuChannelCredentials::forCode($code);
            FeishuChannelCredentials::assertManagedScope($credentials, $context['environment_id'], $bindingId);
        } else {
            $credentials = WecomChannelCredentials::forCode($code);
            WecomChannelCredentials::assertManagedScope($credentials, $context['environment_id'], $bindingId);
        }
        $channel['binding']['delivery_platform'] = $value;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $agent @param array<string,mixed> $knowledge @param array<string,mixed> $channel @return array{query:string,task:array<string,mixed>} */
    private function readTask(Request $request, array $context, array $agent, array $knowledge, array $channel, string $scope, int $id, string $requestId, ?HostSandIamInvocationAuthorizer $streamAuthorizer = null): array
    {
            $operationGuard = new TaskOperationAccessGuard($streamAuthorizer);
            $decision = null;
            $frozenInput = null;
            $query = null;
            $result = (new TaskGateway())->read(
                $context['environment_id'], $id, RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                ),
                function (TaskRun $task) use ($request, $context, $agent, $knowledge, $requestId, $channel, $scope, $operationGuard, $streamAuthorizer, &$decision, &$frozenInput, &$query): void {
                    if ((string) $task->task_type !== 'agent_run') throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
                    $input = is_object($task->input) ? (array) $task->input : $task->input;
                    $frozen = is_array($input) ? ($input['_channel'] ?? null) : null;
                    if (!is_array($frozen) || !is_string($frozen['conversation_scope'] ?? null)
                        || !hash_equals($frozen['conversation_scope'], $scope)
                        || (int) ($frozen['channel_binding_id'] ?? 0) !== $channel['binding']['channel_binding_id']
                        || ($frozen['delivery_platform'] ?? null) !== ($channel['binding']['delivery_platform'] ?? null)) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel task not found');
                    }
                    $frozenInput = $frozen;
                    $query = $input['query'] ?? null;
                    if (!is_string($query) || trim($query) === '') throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel query is invalid');
                    (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $frozen);
                    (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $input['_agent_definition'] ?? null);
                    $decision = $operationGuard->authorize($request, $context['context'], (int) $task->id, 'sand_ai.task.read', $requestId);
                    (new TaskRunAccessGuard($streamAuthorizer))->authorizeRead($request, $agent['context'], $task, $requestId);
                    (new AgentKnowledgeAccessGuard($streamAuthorizer))->authorizeRead($request, $knowledge['context'], $task, $requestId);
                    (new AgentRetrievedSourceGuard($streamAuthorizer))->authorizeRead($request, $agent['context'], $task, $requestId);
                },
                true,
            );
            $operationGuard->revalidate($request, $context['context'], $id, 'sand_ai.task.read', $requestId, $decision);
            (new ChannelBindingGateway())->assertCurrent($context['environment_id'], $frozenInput);
            (new AgentDefinitionGateway())->assertCurrent($context['environment_id'], $channel['agent']['binding']);
            if (is_array($result['channel_receipt_recovery'] ?? null)
                && in_array($result['state'], ['failed', 'recovery_pending'], true)) {
                $query = null;
                $result['result'] = null;
            }
            return ['query' => $query, 'task' => $result];
    }
}
