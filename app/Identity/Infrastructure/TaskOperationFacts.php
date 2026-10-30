<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowRun;

/** Resolves a task operation through its persisted task and source resource. */
final class TaskOperationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, ['sand_ai.task.read', 'sand_ai.task.cancel', 'sand_ai.task.retry'], true)) {
            self::unverified();
        }
        $taskId = self::positiveId($resourceKey);
        $task = TaskRun::where('id', $taskId)->where('status', 1)->whereNull('delete_time')->find();
        if ($task === null) self::unverified();
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input)) self::unverified();
        $source = null;
        $workflow = null;
        if ((string) $task->task_type === 'file_parse') {
            $source = (new FileInvocationFacts())->resolve(
                'sand_ai', 'sand_ai.document_parse', (string) $task->resource_id,
            );
        } elseif ((string) $task->task_type === 'audio_transcription') {
            $source = (new FileInvocationFacts())->resolve(
                'sand_ai', 'sand_ai.audio.transcription', (string) ($input['file_id'] ?? ''),
            );
        } elseif ((string) $task->task_type === 'structured_workflow') {
            $run = WorkflowRun::where('id', (int) $task->resource_id)
                ->where('environment_id', (int) $task->environment_id)
                ->where('task_id', $taskId)->where('status', 1)->whereNull('delete_time')->find();
            if ($run === null) self::unverified();
            $workflow = [
                'id' => $run->id, 'task_id' => $run->task_id,
                'attribution_snapshot' => $run->attribution_snapshot,
            ];
            $source = (new WorkflowInvocationFacts())->resolve(
                'sand_ai', 'sand_ai.workflow.read', (string) $run->id,
            );
        }
        return self::fromPersisted([
            'id' => $task->id, 'status' => $task->status, 'delete_time' => $task->delete_time,
            'task_type' => $task->task_type, 'resource_type' => $task->resource_type,
            'resource_id' => $task->resource_id, 'organization_id' => $task->organization_id,
            'application_id' => $task->application_id, 'environment_id' => $task->environment_id,
            'workload_client_id' => $task->workload_client_id, 'data_class' => $task->data_class,
            'input' => $input,
        ], $taskId, $source, $workflow);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $source
     * @param array<string,mixed>|null $workflow
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $row, int $taskId, ?array $source = null, ?array $workflow = null): array
    {
        if (self::positiveId($row['id'] ?? null) !== $taskId
            || self::positiveId($row['status'] ?? null) !== 1
            || !array_key_exists('delete_time', $row) || $row['delete_time'] !== null) {
            self::unverified();
        }
        $type = $row['task_type'] ?? null;
        if ($type === 'ai_completion' || $type === 'agent_run') {
            $facts = TaskInvocationFacts::fromPersisted(
                $row, $type === 'ai_completion' ? 'sand_ai.chat.complete' : 'sand_ai.agent.submit', $taskId,
            );
        } else {
            $facts = self::sourceFacts($row, $taskId, $source, $workflow);
        }
        $facts['resource_type'] = 'sand_ai.task_operation';
        $facts['resource_ref'] = (string) $taskId;
        return $facts;
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $source @param array<string,mixed>|null $workflow @return array<string,mixed> */
    private static function sourceFacts(array $row, int $taskId, ?array $source, ?array $workflow): array
    {
        $type = $row['task_type'] ?? null;
        $input = is_object($row['input'] ?? null) ? (array) $row['input'] : ($row['input'] ?? null);
        if (!is_array($input) || !is_array($source)
            || !in_array($type, ['file_parse', 'audio_transcription', 'structured_workflow'], true)) {
            self::unverified();
        }
        $environmentId = self::positiveId($row['environment_id'] ?? null);
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            $sourceId = self::positiveId($source[$field] ?? null);
            if (($field === 'environment_id' && $sourceId !== $environmentId)
                || (($row[$field] ?? null) !== null && self::positiveId($row[$field]) !== $sourceId)) {
                self::unverified();
            }
        }
        $dataClass = $source['data_class'] ?? null;
        if ($dataClass !== null && (!is_string($dataClass)
            || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) {
            self::unverified();
        }
        if (($row['data_class'] ?? null) !== null && $row['data_class'] !== $dataClass) self::unverified();
        if ($type === 'structured_workflow') {
            $runId = self::positiveId($row['resource_id'] ?? null);
            if (($row['resource_type'] ?? null) !== 'workflow_run'
                || self::positiveId($input['workflow_run_id'] ?? null) !== $runId
                || ($source['resource_type'] ?? null) !== 'sand_ai.workflow_run'
                || ($source['resource_ref'] ?? null) !== (string) $runId
                || !is_array($workflow)
                || self::positiveId($workflow['id'] ?? null) !== $runId
                || self::positiveId($workflow['task_id'] ?? null) !== $taskId) {
                self::unverified();
            }
            $attribution = is_object($workflow['attribution_snapshot'] ?? null)
                ? (array) $workflow['attribution_snapshot'] : ($workflow['attribution_snapshot'] ?? null);
            $sourceKind = 'structured_workflow';
            $serviceAction = 'sand_ai.workflow.run';
        } else {
            $fileId = self::positiveId($type === 'file_parse' ? ($row['resource_id'] ?? null) : ($input['file_id'] ?? null));
            if (($source['resource_type'] ?? null) !== 'sand_ai.file'
                || ($source['resource_ref'] ?? null) !== (string) $fileId
                || ($type === 'file_parse'
                    && (($row['resource_type'] ?? null) !== 'file'
                        || self::positiveId($input['file_id'] ?? null) !== $fileId))
                || ($type === 'audio_transcription'
                    && (($row['resource_type'] ?? null) !== 'audio_transcription'
                        || self::positiveId($row['resource_id'] ?? null) !== $taskId
                        || !is_array($input['request'] ?? null)
                        || !is_string($input['request']['file_ref'] ?? null)
                        || $input['request']['file_ref'] === ''))) {
                self::unverified();
            }
            $key = $type === 'file_parse' ? '_usage_attribution' : 'usage_attribution';
            $attribution = $input[$key] ?? null;
            $sourceKind = $type === 'file_parse' ? 'file_parse' : 'capability';
            $serviceAction = $type === 'file_parse' ? 'sand_ai.document_parse' : 'sand_ai.audio.transcription';
        }
        try {
            $owner = UsageAttribution::fromCurrentPersisted(
                is_array($attribution) ? $attribution : null,
                $environmentId, $sourceKind, $serviceAction,
            );
            if ($owner->organizationId !== $source['organization_id']
                || $owner->applicationId !== $source['application_id']
                || $owner->workloadClientId !== $source['workload_client_id']
                || ($type !== 'structured_workflow'
                    && (!is_string($input['_trusted_identity_scope'] ?? null)
                        || !hash_equals(RequestIdempotencyKey::trustedScopeForAttribution($owner), $input['_trusted_identity_scope'])))) {
                self::unverified();
            }
        } catch (\InvalidArgumentException) {
            self::unverified();
        }
        return [
            'organization_id' => $source['organization_id'],
            'application_id' => $source['application_id'],
            'environment_id' => $source['environment_id'],
            'workload_client_id' => $source['workload_client_id'],
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.task_operation',
            'resource_ref' => (string) $taskId,
        ];
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false && (string) $parsed === $value) return $parsed;
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
