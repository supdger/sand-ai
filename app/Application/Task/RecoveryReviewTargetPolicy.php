<?php

declare(strict_types=1);

namespace app\Application\Task;

final class RecoveryReviewTargetPolicy
{
    /** @param array<string,mixed> $task @param array<string,mixed>|null $workflow */
    public function matches(array $task, ?array $workflow): bool
    {
        return ($task['resource_type'] ?? null) === 'workflow_run'
            && (int) ($task['id'] ?? 0) > 0
            && (int) ($task['resource_id'] ?? 0) > 0
            && $workflow !== null
            && (int) ($workflow['id'] ?? 0) === (int) $task['resource_id']
            && (int) ($workflow['environment_id'] ?? 0) === (int) ($task['environment_id'] ?? 0)
            && (int) ($workflow['task_id'] ?? 0) === (int) $task['id']
            && in_array(($workflow['workflow_code'] ?? null), ['material_analysis', 'legal_analysis'], true)
            && (int) ($workflow['status'] ?? 0) === 1
            && ($workflow['delete_time'] ?? null) === null;
    }
}
