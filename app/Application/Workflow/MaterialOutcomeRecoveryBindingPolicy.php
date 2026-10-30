<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class MaterialOutcomeRecoveryBindingPolicy
{
    /** @param array<string,mixed> $expected @param array<string,mixed> $invocation @param array<string,mixed> $dispatch */
    public function assert(array $expected, array $invocation, array $dispatch): void
    {
        foreach (['environment_id', 'parent_workflow_run_id', 'parent_task_run_id', 'parent_task_step_id', 'parent_workflow_run_file_id', 'model_id', 'deployment_id'] as $field) {
            if ((int) ($expected[$field] ?? 0) < 1
                || (int) ($invocation[$field] ?? 0) !== (int) $expected[$field]
                || (int) ($dispatch[$field] ?? 0) !== (int) $expected[$field]) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery context does not match the frozen workflow execution');
            }
        }
        foreach (['request_id', 'logical_execution_id', 'execution_snapshot_sha256'] as $field) {
            if (!is_string($expected[$field] ?? null) || (string) $expected[$field] === ''
                || !is_string($invocation[$field] ?? null) || !hash_equals((string) $expected[$field], (string) $invocation[$field])
                || ($field !== 'request_id' && (!is_string($dispatch[$field] ?? null) || !hash_equals((string) $expected[$field], (string) $dispatch[$field])))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery context does not match the frozen workflow execution');
            }
        }
        if ((string) ($invocation['state'] ?? '') !== 'succeeded' || (string) ($dispatch['state'] ?? '') !== 'succeeded'
            || (int) ($dispatch['invocation_id'] ?? 0) !== (int) ($invocation['id'] ?? 0)
            || (string) ($dispatch['capability_code'] ?? '') !== 'structured_extraction') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Receipt recovery context does not match the frozen workflow execution');
        }
    }
}
