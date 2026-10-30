<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Agent\AgentGateway;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentDefinitionPolicy;
use app\Application\Channel\ChannelSourceGateway;
use app\Application\Task\TaskGateway;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;
use support\Response;

final class AgentController
{
    /** Explicitly continue a failed SDK execution using a fresh formal IAM admission. */
    public function continueTask(Request $request, string $taskId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $request->get() !== [] || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent continuation accepts an empty body and no query');
            }
            $authenticator = new SandIamContextAuthenticator();
            $retry = $authenticator->authenticate($request, 'sand_ai.task.retry');
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $scope = RequestIdempotencyKey::trustedScopeForAttribution(UsageAttribution::fromIdentity($submit['context'], 'agent', 'sand_ai.agent.run'));
            foreach ([$retry, $run] as $part) {
                if (!hash_equals($scope, RequestIdempotencyKey::trustedScopeForAttribution(UsageAttribution::fromIdentity($part['context'], 'agent', 'sand_ai.agent.run')))) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
                }
            }
            $key = RequestIdempotencyKey::requiredForResource($request, 'agent-continuation', $submit['context'], (string) $id);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $operation = new TaskOperationAccessGuard($authorizer);
            $result = (new \app\Application\Agent\AgentContinuationGateway())->continueTask(
                $submit['environment_id'], (int) $id, $scope, $key,
                function (TaskRun $task) use ($request, $retry, $submit, $run, $requestId, $authorizer, $operation): array {
                    $read = (new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer))->authorizeTask($request, $run['context'], $task, $requestId, true);
                    $read();
                    return [
                        'tool_node_decisions' => (new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer))
                            ->authorizePendingContinuation($request, $run['context'], $task, $requestId),
                        'task_authorization' => (new TaskRunAccessGuard($authorizer))->authorize($request, $submit['context'], (int) $task->id, 'agent_run', $requestId),
                        'retry_decision' => $operation->authorize($request, $retry['context'], (int) $task->id, 'sand_ai.task.retry', $requestId),
                        'run_decision' => $authorizer->authorize($request, $run['context'], 'sand_ai.agent.run', 'sand_ai.task_run', (string) $task->id,
                            'agent-continue:' . bin2hex(random_bytes(16)), $requestId),
                    ];
                },
                static function (TaskRun $task, array $authorization) use ($request, $retry, $run, $requestId, $authorizer, $operation): void {
                    $readGuard = new \app\Application\Agent\AgentReadonlyToolReadGuard($authorizer);
                    $readGuard->authorizeTask($request, $run['context'], $task, $requestId, true)();
                    $readGuard->revalidatePendingContinuation($request, $run['context'], $task, $requestId, $authorization['tool_node_decisions'] ?? []);
                    (new TaskRunAccessGuard($authorizer))->revalidateQueued($authorization['task_authorization'], $task);
                    $operation->revalidate($request, $retry['context'], (int) $task->id, 'sand_ai.task.retry', $requestId, $authorization['retry_decision']);
                    $signed = trim(substr(trim((string) $request->header('authorization', '')), 7));
                    $authorizer->revalidate($authorization['run_decision']['authorization_id'], $signed, $run['context'], 'sand_ai.agent.run',
                        'sand_ai.task_run', (string) $task->id, $request->getRemoteIp(), $requestId);
                },
            );
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Reads only a persisted generic Agent reference, without retrieval or inference. */
    public function source(Request $request, string $taskId, string $sourceBlockId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $blockId = filter_var($sourceBlockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $event = $request->get('event_no');
            $eventNo = $event === null ? null : (is_string($event)
                ? filter_var($event, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4096]]) : false);
            if ($id === false || $blockId === false || $eventNo === false
                || array_diff(array_keys($request->get()), ['event_no']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent source request is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.task.read');
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $scope = RequestIdempotencyKey::trustedScopeForAttribution(UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.run'));
            foreach ([$submit, $run] as $part) {
                if (!hash_equals($scope, RequestIdempotencyKey::trustedScopeForAttribution(UsageAttribution::fromIdentity($part['context'], 'agent', 'sand_ai.agent.run')))) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent identity scope changed');
                }
            }
            $authorizer = HostSandIamInvocationAuthorizer::forHttpStream($request, $requestId);
            $operationGuard = new TaskOperationAccessGuard($authorizer);
            $decision = null;
            $task = null;
            $readCurrent = function () use ($request, $context, $submit, $run, $scope, $id, $requestId,
                $authorizer, $operationGuard, &$decision, &$task): void {
                (new TaskGateway())->read(
                    $context['environment_id'], (int) $id, $scope,
                    function (TaskRun $current) use ($request, $context, $submit, $run, $scope, $requestId,
                        $authorizer, $operationGuard, &$decision, &$task): void {
                        $input = is_object($current->input) ? (array) $current->input : $current->input;
                        if ((string) $current->task_type !== 'agent_run' || !is_array($input)
                            || array_key_exists('_channel', $input)) {
                            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
                        }
                        $decision = $operationGuard->authorize($request, $context['context'],
                            (int) $current->id, 'sand_ai.task.read', $requestId);
                        (new TaskRunAccessGuard($authorizer))->authorizeRead(
                            $request, $submit['context'], $current, $requestId);
                        if (array_key_exists('_agent_definition', $input)) {
                            (new AgentDefinitionGateway())->assertCurrent((int) $current->environment_id, $input['_agent_definition']);
                            $knowledge = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.knowledge.query');
                            if (!hash_equals($scope, RequestIdempotencyKey::trustedScopeForAttribution(UsageAttribution::fromIdentity($knowledge['context'], 'agent', 'sand_ai.agent.run')))) {
                                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
                            }
                            (new AgentKnowledgeAccessGuard($authorizer))->authorizeRead(
                                $request, $knowledge['context'], $current, $requestId);
                        }
                        (new AgentRetrievedSourceGuard($authorizer))->authorizeRead(
                            $request, $run['context'], $current, $requestId);
                        $task = $current;
                    },
                );
                $operationGuard->revalidate($request, $context['context'], (int) $id,
                    'sand_ai.task.read', $requestId, $decision);
            };
            $readCurrent();
            if (!$task instanceof TaskRun) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
            // This reader validates persisted result/event references; it does not authorize a channel.
            $gateway = new ChannelSourceGateway();
            $source = $gateway->read($task, (int) $blockId, $eventNo);
            $reference = array_intersect_key($source, array_flip(['source_block_id', 'file_id', 'locator_type', 'locator']));
            $guard = new AgentRetrievedSourceGuard($authorizer);
            $guard->authorizeStreamReferences($request, $run['context'], $task, [$reference], $requestId);
            $readCurrent();
            if (!$task instanceof TaskRun) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
            $current = $gateway->read($task, (int) $blockId, $eventNo);
            if ($source !== $current) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent source changed during read');
            }
            $guard->authorizeStreamReferences($request, $run['context'], $task, [$reference], $requestId);
            $operationGuard->revalidate($request, $context['context'], (int) $id,
                'sand_ai.task.read', $requestId, $decision);
            return ApiResponder::success($current, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function readPublishedByRequestKey(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent code is invalid');
            }
            $authenticator = new SandIamContextAuthenticator();
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            if ($submit['context']->organizationId !== $run['context']->organizationId
                || $submit['context']->applicationId !== $run['context']->applicationId
                || $submit['context']->environmentId !== $run['context']->environmentId
                || $submit['context']->workloadClientId !== $run['context']->workloadClientId) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent identity scope changed');
            }
            $key = RequestIdempotencyKey::requiredForResource($request, 'agent', $submit['context'], $code);
            $task = TaskRun::where('request_id', $key)->where('environment_id', $submit['environment_id'])
                ->where('task_type', 'agent_run')->find();
            if ($task === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
            }
            return (new TaskController())->read($request, (string) $task->id);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function readAdHocByRequestKey(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if ($request->get() !== [] || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent lookup accepts no query or body');
            }
            $authenticator = new SandIamContextAuthenticator();
            $submit = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            foreach (['organizationId', 'applicationId', 'environmentId', 'workloadClientId'] as $dimension) {
                if ($submit['context']->{$dimension} !== $run['context']->{$dimension}) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
                }
            }
            $key = RequestIdempotencyKey::required($request, 'agent', $submit['context']);
            $task = TaskRun::where('request_id', $key)->where('environment_id', $submit['environment_id'])
                ->where('task_type', 'agent_run')->find();
            $input = $task === null ? null : (is_object($task->input) ? (array) $task->input : $task->input);
            if ($task === null || !is_array($input) || array_key_exists('_channel', $input)
                || array_key_exists('_agent_definition', $input) || array_key_exists('_agent_conversation', $input)
                || ($input['knowledge_base_code'] ?? null) !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent task not found');
            }
            return (new TaskController())->read($request, (string) $task->id)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function submit(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            if ($context['context']->organizationId !== $run['context']->organizationId
                || $context['context']->applicationId !== $run['context']->applicationId
                || $context['context']->environmentId !== $run['context']->environmentId
                || $context['context']->workloadClientId !== $run['context']->workloadClientId) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent identity scope changed');
            }
            $idempotencyRequestId = RequestIdempotencyKey::required($request, 'agent', $context['context']);
            return ApiResponder::success((new AgentGateway())->submit(
                $context['environment_id'],
                $request->post(),
                $idempotencyRequestId,
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorize(
                    $request, $context['context'], $taskId, 'agent_run', $requestId,
                ),
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
    public function submitPublished(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $context = $authenticator->authenticate($request, 'sand_ai.agent.submit');
            $run = $authenticator->authenticate($request, 'sand_ai.agent.run');
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            if ($context['context']->organizationId !== $run['context']->organizationId
                || $context['context']->applicationId !== $run['context']->applicationId
                || $context['context']->environmentId !== $run['context']->environmentId
                || $context['context']->workloadClientId !== $run['context']->workloadClientId
                || $context['context']->organizationId !== $knowledge['context']->organizationId
                || $context['context']->applicationId !== $knowledge['context']->applicationId
                || $context['context']->environmentId !== $knowledge['context']->environmentId
                || $context['context']->workloadClientId !== $knowledge['context']->workloadClientId) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent identity scope changed');
            }
            $body = $request->post();
            if (!is_array($body)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent request is invalid');
            $query = AgentDefinitionPolicy::runInput($body);
            $definition = (new AgentDefinitionGateway())->resolve($context['environment_id'], $code);
            $key = RequestIdempotencyKey::requiredForResource($request, 'agent', $context['context'], $code);
            return ApiResponder::success((new AgentGateway())->submit(
                $context['environment_id'], [...$definition['config'], ...$query], $key,
                UsageAttribution::fromIdentity($context['context'], 'agent', 'sand_ai.agent.submit'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorize(
                    $request, $context['context'], $taskId, 'agent_run', $requestId,
                ),
                $definition['binding'],
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

}
