<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Validates the caller snapshot of a persisted non-workflow task. */
final class TaskInputAccessGuard
{
    public function assert(TaskRun $task, ?string $currentScope): void
    {
        if ((string) $task->task_type === 'structured_workflow') {
            return;
        }
        try {
            $storedScope = $this->persistedScope($task);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
        if (!is_string($currentScope) || !hash_equals($storedScope, $currentScope)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
        }
    }

    public function persistedScope(TaskRun $task): string
    {
        [$key, $sourceKind, $action] = match ((string) $task->task_type) {
            'ai_completion' => ['_usage_attribution', 'ai_task', 'sand_ai.chat.complete'],
            'agent_run' => ['_usage_attribution', 'agent', 'sand_ai.agent.submit'],
            'audio_transcription' => ['usage_attribution', 'capability', 'sand_ai.audio.transcription'],
            'file_parse' => ['_usage_attribution', 'file_parse', 'sand_ai.document_parse'],
            default => throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task type has no trusted caller snapshot'),
        };
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || !is_string($input['_trusted_identity_scope'] ?? null)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task caller snapshot is missing');
        }
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($input[$key] ?? null) ? $input[$key] : null,
                (int) $task->environment_id,
                $sourceKind,
                $action,
            );
            $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task caller snapshot is invalid');
        }
        if (!hash_equals($scope, $input['_trusted_identity_scope'])) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Task caller snapshot does not match');
        }
        return $scope;
    }
}
