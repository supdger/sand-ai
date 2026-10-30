<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Task\TaskGateway;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Task\AiTaskGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\FileParseAccessGuard;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\WorkflowRunAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\TaskOperationAccessGuard;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;
use support\Response;

final class TaskController
{
    public function read(Request $request, string $taskId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.read');
            $id = $this->id($taskId);
            $operationGuard = new TaskOperationAccessGuard();
            $decision = null;
            $toolRevalidate = null;
            $result = (new TaskGateway())->read(
                $context['environment_id'], $id, $this->scope($context['context']),
                function (TaskRun $task) use ($request, $context, $requestId, $operationGuard, &$decision, &$toolRevalidate): void {
                    $decision = $operationGuard->authorize(
                        $request, $context['context'], (int) $task->id, 'sand_ai.task.read', $requestId,
                    );
                    if ((string) $task->task_type === 'structured_workflow') {
                        (new WorkflowRunAccessGuard())->authorizeRead(
                            $request, $context['context'], (int) $task->resource_id, $requestId,
                        );
                        return;
                    }
                    $action = match ((string) $task->task_type) {
                        'ai_completion' => 'sand_ai.chat.complete',
                        'agent_run' => 'sand_ai.agent.submit',
                        'audio_transcription' => 'sand_ai.audio.transcription',
                        'file_parse' => 'sand_ai.document_parse',
                        default => throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found'),
                    };
                    $verified = (new SandIamContextAuthenticator())->authenticate($request, $action);
                    if (!hash_equals($this->scope($context['context']), $this->scope($verified['context']))) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                    }
                    if ((string) $task->task_type === 'file_parse') {
                        (new HostSandIamInvocationAuthorizer())->authorize(
                            $request, $verified['context'], 'sand_ai.document_parse', 'sand_ai.file',
                            (string) $task->resource_id, 'file-parse-read:' . bin2hex(random_bytes(16)), $requestId,
                        );
                    } elseif ((string) $task->task_type === 'audio_transcription') {
                        $input = is_object($task->input) ? (array) $task->input : $task->input;
                        $fileId = is_array($input) ? ($input['file_id'] ?? null) : null;
                        if (!is_int($fileId) || $fileId < 1) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Audio task file facts are unavailable');
                        }
                        (new HostSandIamInvocationAuthorizer())->authorize(
                            $request, $verified['context'], 'sand_ai.audio.transcription', 'sand_ai.file',
                            (string) $fileId, 'audio-task-read:' . bin2hex(random_bytes(16)), $requestId,
                        );
                    } elseif ((string) $task->task_type === 'ai_completion') {
                        AiTaskGateway::assertCurrentSourceFacts(
                            $task, UsageAttribution::fromIdentity(
                                $verified['context'], 'ai_task', 'sand_ai.chat.complete',
                            ),
                        );
                        (new TaskRunAccessGuard())->authorizeRead(
                            $request, $verified['context'], $task, $requestId,
                        );
                        $input = is_object($task->input) ? (array) $task->input : $task->input;
                        $meeting = is_array($input) ? ($input['_meeting_summary'] ?? null) : null;
                        if ($meeting !== null) {
                            if (!is_array($meeting) || !is_int($meeting['audio_task_id'] ?? null)
                                || !is_int($meeting['file_id'] ?? null)
                                || !is_string($meeting['sha256'] ?? null)) {
                                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting source facts are unavailable');
                            }
                            $audio = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.transcription');
                            if (!hash_equals($this->scope($verified['context']), $this->scope($audio['context']))) {
                                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                            }
                            $source = (new MeetingSummarySource())->load(
                                (int) $task->environment_id, $meeting['audio_task_id'],
                                UsageAttribution::fromIdentity($audio['context'], 'capability', 'sand_ai.audio.transcription', 'audio_transcription'),
                                $meeting['sha256'], $meeting['review_revision'] ?? 0, true,
                            );
                            if ($source['file_id'] !== $meeting['file_id']
                                || $source['data_class'] !== ($meeting['data_class'] ?? null)
                                || $source['data_egress_policy'] !== ($meeting['data_egress_policy'] ?? null)
                                || $source['data_egress_policy'] !== 'approved_cloud') {
                                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting source file changed');
                            }
                            (new HostSandIamInvocationAuthorizer())->authorize(
                                $request, $audio['context'], 'sand_ai.audio.transcription', 'sand_ai.file',
                                (string) $source['file_id'], 'meeting-summary-read:' . bin2hex(random_bytes(16)), $requestId,
                            );
                        }
                    } elseif ((string) $task->task_type === 'agent_run') {
                        $agentInput = is_object($task->input) ? (array) $task->input : $task->input;
                        if (is_array($agentInput) && array_key_exists('_agent_definition', $agentInput)) {
                            (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $agentInput['_agent_definition']);
                            $knowledge = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.knowledge.query');
                            if (!hash_equals($this->scope($verified['context']), $this->scope($knowledge['context']))) {
                                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                            }
                            (new AgentKnowledgeAccessGuard())->authorizeRead($request, $knowledge['context'], $task, $requestId);
                        }
                        (new TaskRunAccessGuard())->authorizeRead(
                            $request, $verified['context'], $task, $requestId,
                        );
                        (new AgentRetrievedSourceGuard())->authorizeRead(
                            $request, $verified['context'], $task, $requestId,
                        );
                        $toolRevalidate = (new \app\Application\Agent\AgentReadonlyToolReadGuard())->authorizeTask($request, $context['context'], $task, $requestId);
                    }
                },
                authorizeMeetingResult: function (TaskRun $audioTask, array $source) use ($request, $context, $requestId): void {
                    $audio = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.transcription');
                    if (!hash_equals($this->scope($context['context']), $this->scope($audio['context']))
                        || (int) $audioTask->environment_id !== $context['environment_id']
                        || !is_int($source['file_id'] ?? null) || $source['file_id'] < 1) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Meeting source not found');
                    }
                    (new HostSandIamInvocationAuthorizer())->authorize(
                        $request, $audio['context'], 'sand_ai.audio.transcription', 'sand_ai.file',
                        (string) $source['file_id'], 'meeting-result-read:' . bin2hex(random_bytes(16)), $requestId,
                    );
                },
            );
            $operationGuard->revalidate($request, $context['context'], $id, 'sand_ai.task.read', $requestId, $decision);
            if ($toolRevalidate !== null) $toolRevalidate();
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function cancel(Request $request, string $taskId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.cancel');
            $id = $this->id($taskId);
            [$expectedAttempt, $expectedState] = $this->actionExpectation($request, false);
            $operationGuard = new TaskOperationAccessGuard();
            $decision = null;
            $result = (new TaskGateway())->cancel(
                $context['environment_id'], $id, $this->scope($context['context']),
                function (TaskRun $task) use ($request, $context, $requestId, $operationGuard, &$decision): void {
                    $decision = $operationGuard->authorize(
                        $request, $context['context'], (int) $task->id, 'sand_ai.task.cancel', $requestId,
                    );
                },
                $expectedAttempt, $expectedState,
            );
            $operationGuard->revalidate($request, $context['context'], $id, 'sand_ai.task.cancel', $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function retry(Request $request, string $taskId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.retry');
            $id = $this->id($taskId);
            [$expectedAttempt, $expectedState, $expectedFiles, $expectedReviewAttempt] = $this->actionExpectation($request, true);
            $target = $request->post('target');
            if ($target !== null && !is_string($target)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task retry target is invalid');
            }
            $ocrRetryKey = null;
            if ($target === \app\Application\File\FileParseGateway::BEFORE_DISPATCH_RETRY_TARGET) {
                $body = (array) $request->post();
                if (count($body) !== 3 || array_diff(array_keys($body), ['target', 'expected_attempt_count', 'expected_state']) !== []
                    || $expectedAttempt === null || $expectedState !== 'failed') {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Before-dispatch OCR recovery requires only its target and original failed attempt');
                }
                $ocrRetryKey = RequestIdempotencyKey::requiredForResource($request, 'ocr_parse_retry', $context['context'], (string) $id);
            }
            $mode = $request->post('mode');
            if ($mode !== null && !in_array($mode, ['receipt_only', \app\Application\Task\TaskAuthorizationContinuation::MODE, \app\Application\Audio\MeetingOutputCorrection::MODE], true)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task retry mode is invalid');
            }
            $continuationKey = $mode === \app\Application\Task\TaskAuthorizationContinuation::MODE
                ? RequestIdempotencyKey::requiredForResource($request, 'task_auth_continue', $context['context'], (string) $id) : null;
            if ($continuationKey !== null && ($target !== null || array_diff(array_keys((array) $request->post()), ['mode']) !== [])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Authorization continuation accepts only its mode');
            }
            $correction = $mode === \app\Application\Audio\MeetingOutputCorrection::MODE;
            $correctionNode = $correction ? $request->post('node_code') : null;
            $correctionAttempt = $correction ? $request->post('expected_node_attempt') : null;
            if ($correction && ($target !== null || $expectedAttempt === null || $expectedState !== 'failed'
                || !is_string($correctionNode) || preg_match('/\A(chunk_[0-9]{5}|merge_[0-9]{2}_[0-9]{5})\z/', $correctionNode) !== 1
                || !is_int($correctionAttempt) || $correctionAttempt < 1 || $correctionAttempt >= 3
                || count((array)$request->post()) !== 5
                || array_diff(array_keys((array)$request->post()), ['mode','node_code','expected_node_attempt','expected_attempt_count','expected_state']) !== [])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Meeting correction requires the original failed task and node attempts');
            }
            $correctionKey = $correction ? RequestIdempotencyKey::requiredForResource($request, 'meeting_correction', $context['context'], (string)$id) : null;
            $operationGuard = new TaskOperationAccessGuard();
            $decision = null;
            $result = (new TaskGateway())->retry(
                $context['environment_id'], $id, $this->scope($context['context']),
                function (TaskRun $task) use ($request, $context, $requestId): array {
                    $action = match ((string) $task->task_type) {
                        'structured_workflow' => 'sand_ai.workflow.run',
                        'audio_transcription' => 'sand_ai.audio.transcription',
                        'file_parse' => 'sand_ai.document_parse',
                        'ai_completion' => 'sand_ai.chat.complete',
                        'agent_run' => 'sand_ai.agent.submit',
                        default => throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Task retry authorization is unavailable'),
                    };
                    $verified = (new SandIamContextAuthenticator())->authenticate($request, $action);
                    if (!hash_equals($this->scope($context['context']), $this->scope($verified['context']))) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                    }
                    if ((string) $task->task_type === 'structured_workflow') {
                        return (new WorkflowRunAccessGuard())->authorizeRun(
                            $request, $verified['context'], (int) $task->resource_id, $requestId,
                        );
                    }
                    if ((string) $task->task_type === 'audio_transcription') {
                        $input = is_object($task->input) ? (array) $task->input : $task->input;
                        $frozenRequest = is_array($input) ? ($input['request'] ?? null) : null;
                        $fileId = is_array($input) ? ($input['file_id'] ?? null) : null;
                        if (!is_array($frozenRequest) || !is_string($frozenRequest['file_ref'] ?? null)
                            || !is_int($fileId) || $fileId < 1) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Audio task file facts are unavailable');
                        }
                        $attribution = UsageAttribution::fromIdentity(
                            $verified['context'], 'capability', 'sand_ai.audio.transcription', 'audio_transcription',
                        );
                        $currentFileId = (new \app\Application\Task\AudioTaskGateway())->fileIdForAuthorization(
                            (int) $task->environment_id, $frozenRequest, $attribution,
                        );
                        if ($currentFileId !== $fileId) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Audio task file facts changed');
                        }
                        return (new AudioTaskAccessGuard())->authorize(
                            $request, $verified['context'], $fileId, $frozenRequest['file_ref'], $requestId,
                        );
                    }
                    if ((string) $task->task_type === 'ai_completion') {
                        AiTaskGateway::assertCurrentSourceFacts(
                            $task, UsageAttribution::fromIdentity(
                                $verified['context'], 'ai_task', 'sand_ai.chat.complete',
                            ),
                        );
                        $taskAuthorization = (new TaskRunAccessGuard())->authorize(
                            $request, $verified['context'], (int) $task->id, 'ai_completion', $requestId,
                        );
                        $input = is_object($task->input) ? (array) $task->input : $task->input;
                        $meeting = is_array($input) ? ($input['_meeting_summary'] ?? null) : null;
                        if ($meeting === null) {
                            return $taskAuthorization;
                        }
                        if (!is_array($meeting) || !is_int($meeting['audio_task_id'] ?? null)
                            || !is_int($meeting['file_id'] ?? null)
                            || !is_string($meeting['sha256'] ?? null)) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting source facts are unavailable');
                        }
                        $audio = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.transcription');
                        if (!hash_equals($this->scope($verified['context']), $this->scope($audio['context']))) {
                            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                        }
                        $source = (new MeetingSummarySource())->load(
                            (int) $task->environment_id, $meeting['audio_task_id'],
                            UsageAttribution::fromIdentity($audio['context'], 'capability', 'sand_ai.audio.transcription', 'audio_transcription'),
                            $meeting['sha256'], $meeting['review_revision'] ?? 0, true,
                        );
                        if ($source['file_id'] !== $meeting['file_id']
                            || $source['data_class'] !== ($meeting['data_class'] ?? null)
                            || $source['data_egress_policy'] !== ($meeting['data_egress_policy'] ?? null)
                            || $source['data_egress_policy'] !== 'approved_cloud') {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting source file changed');
                        }
                        return [
                            'task_authorization' => $taskAuthorization,
                            'audio_authorization' => (new AudioTaskAccessGuard())->authorize(
                                $request, $audio['context'], $source['file_id'], $source['file_ref'], $requestId,
                            ),
                        ];
                    }
                    if ((string) $task->task_type === 'agent_run') {
                        return (new TaskRunAccessGuard())->authorize(
                            $request, $verified['context'], (int) $task->id, 'agent_run', $requestId,
                        );
                    }
                    return (new FileParseAccessGuard())->authorize(
                        $request, $verified['context'], (int) $task->resource_id, $requestId,
                    );
                },
                function (TaskRun $task) use ($request, $context, $requestId, $operationGuard, &$decision): void {
                    $decision = $operationGuard->authorize(
                        $request, $context['context'], (int) $task->id, 'sand_ai.task.retry', $requestId,
                    );
                },
                $target, $mode,
                function (TaskRun $task) use ($request, $context, $requestId, $operationGuard, &$decision): void {
                    $operationGuard->revalidate($request, $context['context'], (int) $task->id,
                        'sand_ai.task.retry', $requestId, $decision);
                },
                $continuationKey,
                $expectedAttempt, $expectedState, $expectedFiles, $expectedReviewAttempt,
                $ocrRetryKey, $correctionKey, $correctionNode, $correctionAttempt,
            );
            $operationGuard->revalidate($request, $context['context'], $id, 'sand_ai.task.retry', $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** @return array{0:?int,1:?string,2:?array,3:?int} */
    private function actionExpectation(Request $request, bool $materialTargets): array
    {
        $body = (array) $request->post();
        $hasAttempt = array_key_exists('expected_attempt_count', $body);
        $hasState = array_key_exists('expected_state', $body);
        $hasFiles = array_key_exists('expected_failed_files', $body);
        $hasReview = array_key_exists('expected_review_attempt_count', $body);
        if (!$hasAttempt && !$hasState && !$hasFiles && !$hasReview) {
            return [null, null, null, null];
        }
        if (!$hasAttempt || !$hasState || !is_int($body['expected_attempt_count'])
            || $body['expected_attempt_count'] < 0 || $body['expected_attempt_count'] > 9007199254740991
            || !is_string($body['expected_state']) || !in_array($body['expected_state'], [
                'authorization_pending', 'queued', 'running', 'retrying', 'recovery_pending',
                'succeeded', 'failed', 'canceled',
            ], true)
            || ($materialTargets && ($body['mode'] ?? null) !== null && ($body['mode'] ?? null) !== \app\Application\Audio\MeetingOutputCorrection::MODE)
            || (!$materialTargets && ($hasFiles || $hasReview))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Task action expectations are invalid');
        }
        $files = null;
        $reviewAttempt = null;
        if ($hasFiles) {
            if ($hasReview || ($body['target'] ?? null) !== 'failed_files'
                || !is_array($body['expected_failed_files']) || !array_is_list($body['expected_failed_files'])
                || $body['expected_failed_files'] === []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected failed material files are invalid');
            }
            $files = [];
            $previousId = 0;
            foreach ($body['expected_failed_files'] as $file) {
                if (!is_array($file) || count($file) !== 2
                    || !is_int($file['file_id'] ?? null) || $file['file_id'] <= $previousId
                    || $file['file_id'] > 9007199254740991
                    || !is_int($file['attempt_count'] ?? null) || $file['attempt_count'] < 0
                    || $file['attempt_count'] > 9007199254740991) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected failed material files are invalid');
                }
                $files[] = ['file_id' => $file['file_id'], 'attempt_count' => $file['attempt_count']];
                $previousId = $file['file_id'];
            }
        }
        if ($hasReview) {
            if (($body['target'] ?? null) !== 'cross_file_review'
                || !is_int($body['expected_review_attempt_count'])
                || $body['expected_review_attempt_count'] < 0 || $body['expected_review_attempt_count'] > 9007199254740991) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected material review attempt is invalid');
            }
            $reviewAttempt = $body['expected_review_attempt_count'];
        }
        return [$body['expected_attempt_count'], $body['expected_state'], $files, $reviewAttempt];
    }

    private function id(string $taskId): int
    {
        $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid task id is required');
        }

        return $id;
    }

    private function requestId(Request $request): string
    {
        return RequestAuditId::ensure($request);
    }
    private function scope(\app\Identity\Contract\IdentityContext $context): string
    {
        return RequestIdempotencyKey::trustedScopeForAttribution(
            UsageAttribution::fromIdentity($context, 'task', 'sand_ai.task.read', 'task'),
        );
    }
}
