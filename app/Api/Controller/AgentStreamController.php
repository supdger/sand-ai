<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentConversationGateway;
use app\Application\Agent\AgentContinuationGateway;
use app\Application\Agent\AgentStreamEventGateway;
use app\Application\Channel\ChannelConversationPolicy;
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
use Workerman\Timer;
use Workerman\Protocols\Http\ServerSentEvents;

/** Persisted Generic Agent replay; independent of Channel identities and bindings. */
final class AgentStreamController
{
    /** Current public projections of this identity's exact published Generic conversation. */
    public function conversation(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1
                || array_diff(array_keys($request->get()), ['before_task_id', 'limit']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent conversation query is invalid');
            }
            AgentConversationGateway::conversationId($conversationId);
            $before = $request->get('before_task_id');
            $limit = $request->get('limit', '20');
            if (!is_string($limit) || preg_match('/\A[1-9][0-9]*\z/D', $limit) !== 1
                || filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]) === false
                || ($before !== null && (!is_string($before) || preg_match('/\A[1-9][0-9]*\z/D', $before) !== 1
                    || filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent conversation cursor is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            foreach (['sand_ai.agent.submit', 'sand_ai.agent.run', 'sand_ai.knowledge.query'] as $action) {
                $other = $authenticator->authenticate($request, $action);
                if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']), RequestIdempotencyKey::trustedScope($other['context']))) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent conversation identity changed');
                }
            }
            $definition = (new AgentDefinitionGateway())->resolve($context['environment_id'], $code);
            $attribution = UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit');
            $ids = (new AgentConversationGateway())->taskIds($attribution, $definition['binding'],
                $conversationId, $before === null ? null : (int) $before, (int) $limit);
            $authorizer = HostSandIamInvocationAuthorizer::forHttpStream($request, $requestId);
            $tasks = [];
            foreach ($ids as $id) {
                [$public, $task] = $this->readCurrent($request, $code, $id, $requestId, $authorizer);
                $tasks[] = $public;
            }
            (new AgentDefinitionGateway())->assertCurrent($context['environment_id'], $definition['binding']);
            return ApiResponder::success(['conversation_id' => $conversationId, 'tasks' => $tasks,
                'next_before_task_id' => count($ids) === (int) $limit ? $ids[array_key_last($ids)] : null], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Replays native provider deltas from the same accepted task; never starts inference. */
    public function events(Request $request, string $code, string $taskId): Response
    {
        return $this->stream($request, $code, $taskId);
    }

    public function adHocEvents(Request $request, string $taskId): Response
    {
        return $this->stream($request, null, $taskId);
    }

    private function stream(Request $request, ?string $code, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $connection = $request->connection;
            if ($connection === null || preg_match('/\A[1-9][0-9]*\z/D', $taskId) !== 1
                || filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent stream request is invalid');
            }
            if (($code !== null && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1)
                || $request->protocolVersion() !== '1.1'
                || array_diff(array_keys($request->get()), ['after_event_id']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent stream request is invalid');
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
            $readCurrent = function () use ($request, $code, $taskId, $requestId, $streamAuthorizer): array {
                return $this->readCurrent($request, $code, (int) $taskId, $requestId, $streamAuthorizer);
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
                    $events = (new AgentStreamEventGateway())->read($task, $lastEvent, 1);
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
                    if ((in_array($publicTask['state'], ['succeeded', 'failed', 'canceled', 'recovery_pending'], true) && count($events) < 1)
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


    /** Current request credentials, current execution admission and source facts are all rechecked. */
    private function readCurrent(Request $request, ?string $code, int $id, string $requestId,
        HostSandIamInvocationAuthorizer $authorizer): array
    {
        $authenticator = new SandIamContextAuthenticator();
        $context = $authenticator->authenticate($request, 'sand_ai.task.read');
        $agent = $authenticator->authenticate($request, 'sand_ai.agent.submit');
        $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
        $knowledge = $code === null ? null : $authenticator->authenticate($request, 'sand_ai.knowledge.query');
        $parts = [$agent, $run];
        if ($knowledge !== null) $parts[] = $knowledge;
        foreach ($parts as $part) {
            if (!hash_equals(RequestIdempotencyKey::trustedScope($context['context']),
                RequestIdempotencyKey::trustedScope($part['context']))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent stream identity changed');
            }
        }
        $operation = new TaskOperationAccessGuard($authorizer);
        $decision = null;
        $persisted = null;
        $toolRevalidate = null;
        $public = (new TaskGateway())->read($context['environment_id'], $id,
            RequestIdempotencyKey::trustedScopeForAttribution(
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit')),
            function (TaskRun $task) use ($request, $context, $agent, $knowledge, $code, $requestId,
                $operation, $authorizer, &$decision, &$persisted, &$toolRevalidate): void {
                $input = is_object($task->input) ? (array) $task->input : $task->input;
                if ((string) $task->task_type !== 'agent_run' || !is_array($input)
                    || array_key_exists('_channel', $input) || $task->content_purged_time !== null
                    || ($code === null && (array_key_exists('_agent_definition', $input)
                        || ($input['knowledge_base_code'] ?? null) !== null || array_key_exists('_agent_conversation', $input)))
                    || ($code !== null && (!is_array($input['_agent_definition'] ?? null)
                        || ($input['_agent_definition']['code'] ?? null) !== $code))) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
                }
                if (($input['_agent_stream'] ?? false) !== true) {
                    throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'This task has no native token stream');
                }
                if ($code !== null) {
                    (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $input['_agent_definition']);
                }
                $decision = $operation->authorize($request, $context['context'], (int) $task->id, 'sand_ai.task.read', $requestId);
                $runGuard = new TaskRunAccessGuard($authorizer);
                $runGuard->authorizeRead($request, $agent['context'], $task, $requestId);
                if (!in_array((string) $task->state, ['succeeded', 'failed', 'canceled'], true)) {
                    $runGuard->revalidateQueued(AgentContinuationGateway::executionAuthorization($task), $task);
                }
                if ($knowledge !== null) {
                    (new AgentKnowledgeAccessGuard($authorizer))->authorizeRead($request, $knowledge['context'], $task, $requestId);
                }
                (new AgentRetrievedSourceGuard($authorizer))->authorizeRead($request, $agent['context'], $task, $requestId);
                $toolRevalidate = (new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer))->authorizeTask($request, $agent['context'], $task, $requestId);
                $persisted = $task;
            }, true);
        $operation->revalidate($request, $context['context'], $id, 'sand_ai.task.read', $requestId, $decision);
        if ($toolRevalidate !== null) $toolRevalidate();
        return [$public, $persisted, $agent['context']];
    }
}
