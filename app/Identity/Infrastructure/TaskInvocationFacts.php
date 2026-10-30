<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Resolves an asynchronous task from persisted ownership and frozen input. */
final class TaskInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, ['sand_ai.chat.complete', 'sand_ai.agent.submit', 'sand_ai.agent.run'], true)) {
            self::unverified();
        }
        $taskId = self::positiveId($resourceKey);
        $task = TaskRun::where('id', $taskId)->where('status', 1)->whereNull('delete_time')->find();
        if ($task === null) {
            self::unverified();
        }
        return self::fromTask($task, $actionCode);
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public static function fromTask(TaskRun $task, string $actionCode): array
    {
        return self::fromPersisted([
            'id' => $task->id,
            'status' => $task->status,
            'delete_time' => $task->delete_time,
            'task_type' => $task->task_type,
            'resource_type' => $task->resource_type,
            'resource_id' => $task->resource_id,
            'organization_id' => $task->organization_id,
            'application_id' => $task->application_id,
            'environment_id' => $task->environment_id,
            'workload_client_id' => $task->workload_client_id,
            'data_class' => $task->data_class,
            'input' => $task->input,
        ], $actionCode, self::positiveId($task->id));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $row, string $actionCode, int $taskId): array
    {
        $taskType = match ($actionCode) {
            'sand_ai.chat.complete' => 'ai_completion',
            'sand_ai.agent.submit' => 'agent_run',
            'sand_ai.agent.run' => 'agent_run',
            default => self::unverified(),
        };
        $sourceKind = $taskType === 'ai_completion' ? 'ai_task' : 'agent';
        $resourceType = $taskType === 'ai_completion' ? 'ai_task' : 'agent_run';
        if (self::positiveId($row['id'] ?? null) !== $taskId
            || self::positiveId($row['status'] ?? null) !== 1
            || !array_key_exists('delete_time', $row) || $row['delete_time'] !== null
            || ($row['task_type'] ?? null) !== $taskType
            || ($row['resource_type'] ?? null) !== $resourceType
            || self::positiveId($row['resource_id'] ?? null) !== $taskId) {
            self::unverified();
        }
        $environmentId = self::positiveId($row['environment_id'] ?? null);
        $organizationId = self::positiveId($row['organization_id'] ?? null);
        $applicationId = self::positiveId($row['application_id'] ?? null);
        $workloadClientId = self::positiveId($row['workload_client_id'] ?? null);
        $input = is_object($row['input'] ?? null) ? (array) $row['input'] : ($row['input'] ?? null);
        if (!is_array($input) || !array_key_exists('_resource_data_class', $input)
            || !array_key_exists('data_class', $row)) {
            self::unverified();
        }
        $dataClass = $row['data_class'];
        if ($dataClass !== $input['_resource_data_class']
            || ($taskType === 'agent_run' && $dataClass !== null)
            || ($dataClass !== null && (!is_string($dataClass)
                || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1))) {
            self::unverified();
        }
        try {
            $owner = UsageAttribution::fromCurrentPersisted(
                is_array($input['_usage_attribution'] ?? null) ? $input['_usage_attribution'] : null,
                $environmentId, $sourceKind, $actionCode,
            );
            $scope = $input['_trusted_identity_scope'] ?? null;
            $expectedScope = RequestIdempotencyKey::trustedScopeForAttribution($owner);
            if ($owner->organizationId !== $organizationId
                || $owner->applicationId !== $applicationId
                || $owner->workloadClientId !== $workloadClientId
                || !is_string($scope) || !hash_equals($expectedScope, $scope)) {
                self::unverified();
            }
        } catch (\InvalidArgumentException) {
            self::unverified();
        }
        return [
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'environment_id' => $environmentId,
            'workload_client_id' => $workloadClientId,
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.task_run',
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
