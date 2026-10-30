<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Binds a model review to the fixed input, invocation, usage, and cited candidates. */
final class MaterialCrossFileReviewResultPolicy
{
    public function __construct(
        private readonly MaterialCrossFileReviewOutputValidator $validator = new MaterialCrossFileReviewOutputValidator(),
    ) {
    }

    /**
     * @param array<string,mixed> $expected Server-owned run, task, step and review attempt.
     * @param array<string,mixed> $invocation Persisted terminal invocation.
     * @param array<string,mixed> $dispatch Persisted terminal dispatch.
     * @param array<string,mixed> $usageEvent Persisted immutable usage event.
     * @param array<string,mixed> $input Complete prompt from MaterialCrossFileReviewInputLoader.
     * @param array<string,mixed> $current Evidence reloaded before result persistence.
     * @return array{payload:array<string,mixed>,payload_sha256:string,input_sha256:string,source_rows:list<array{field_path:string,source_block_id:int}>,withheld_rows:list<array{workflow_run_file_id:int,field_path:string}>,field_source_count:int,withheld_candidate_count:int}
     */
    public function prepare(
        array $expected,
        array $invocation,
        array $dispatch,
        array $usageEvent,
        array $input,
        array $current,
        string $rawJson,
        int $maxOutputCharacters,
    ): array {
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id',
            'workflow_run_id', 'task_id', 'step_id', 'attempt_no'] as $field) {
            if (!is_int($expected[$field] ?? null) || $expected[$field] < 1) {
                self::invalid();
            }
        }
        $snapshot = $expected['execution_snapshot_sha256'] ?? null;
        $logicalId = $expected['logical_execution_id'] ?? null;
        if (!self::hash($snapshot, 64) || !self::hash($logicalId, 48)) {
            self::invalid();
        }
        $identity = new MaterialCrossFileReviewAttemptIdentity($logicalId, $expected['attempt_no']);
        $messages = $input['messages'] ?? null;
        if (!is_array($messages) || count($messages) !== 2
            || ($messages[0]['role'] ?? null) !== 'system'
            || ($messages[1]['role'] ?? null) !== 'user'
            || !is_string($messages[0]['content'] ?? null)
            || !is_string($messages[1]['content'] ?? null)
            || !self::hash($input['input_sha256'] ?? null, 64)
            || !hash_equals(
                $input['input_sha256'],
                hash('sha256', $messages[0]['content'] . "\n" . $messages[1]['content']),
            )
            || ($input['workflow_run_id'] ?? null) !== $expected['workflow_run_id']
            || ($current['workflow_run_id'] ?? null) !== $expected['workflow_run_id']
            || ($input['execution_snapshot_sha256'] ?? null) !== $snapshot
            || ($current['execution_snapshot_sha256'] ?? null) !== $snapshot
            || ($current['input_sha256'] ?? null) !== $input['input_sha256']
            || ($current['messages'] ?? null) !== $messages
            || ($input['material_review_output_contract'] ?? null) !== ($current['material_review_output_contract'] ?? null)
            || !self::hash($input['sealed_set_sha256'] ?? null, 64)
            || ($input['sealed_set_sha256'] ?? null) !== ($current['sealed_set_sha256'] ?? null)
            || !is_array($input['file_result_ids'] ?? null)
            || !array_is_list($input['file_result_ids'])
            || count($input['file_result_ids']) < 2
            || $input['file_result_ids'] !== ($current['file_result_ids'] ?? null)
            || !is_array($input['source_file_ids'] ?? null)
            || $input['source_file_ids'] !== ($current['source_file_ids'] ?? null)
            || !is_array($input['candidates'] ?? null)
            || $input['candidates'] !== ($current['candidates'] ?? null)) {
            self::invalid();
        }
        $invocationFacts = [
            'environment_id' => $expected['environment_id'],
            'organization_id' => $expected['organization_id'],
            'application_id' => $expected['application_id'],
            'workload_client_id' => $expected['workload_client_id'],
            'parent_workflow_run_id' => $expected['workflow_run_id'],
            'parent_task_run_id' => $expected['task_id'],
            'parent_task_step_id' => $expected['step_id'],
            'parent_workflow_run_file_id' => null,
            'workflow_node_code' => 'cross_file_review',
            'execution_attempt_no' => $expected['attempt_no'],
            'execution_snapshot_sha256' => $snapshot,
            'logical_execution_id' => $identity->dispatchLogicalExecutionId(),
            'request_id' => $identity->requestId(),
            'state' => 'succeeded',
            'status' => 1,
            'delete_time' => null,
        ];
        foreach ($invocationFacts as $field => $value) {
            if (!array_key_exists($field, $invocation) || $invocation[$field] !== $value) {
                self::invalid();
            }
        }
        $invocationId = $invocation['id'] ?? null;
        $modelId = $invocation['model_id'] ?? null;
        $deploymentId = $invocation['deployment_id'] ?? null;
        if (!is_int($invocationId) || $invocationId < 1
            || !is_int($modelId) || $modelId < 1
            || !is_int($deploymentId) || $deploymentId < 1) {
            self::invalid();
        }
        $dispatchFacts = [
            'invocation_id' => $invocationId,
            'environment_id' => $expected['environment_id'],
            'parent_workflow_run_id' => $expected['workflow_run_id'],
            'parent_task_run_id' => $expected['task_id'],
            'parent_task_step_id' => $expected['step_id'],
            'parent_workflow_run_file_id' => null,
            'workflow_node_code' => 'cross_file_review',
            'attempt_no' => $expected['attempt_no'],
            'execution_snapshot_sha256' => $snapshot,
            'logical_execution_id' => $identity->dispatchLogicalExecutionId(),
            'logical_request_id' => hash('sha256', $identity->requestId()),
            'model_id' => $modelId,
            'deployment_id' => $deploymentId,
            'capability_code' => 'structured_extraction',
            'state' => 'succeeded',
        ];
        foreach ($dispatchFacts as $field => $value) {
            if (!array_key_exists($field, $dispatch) || $dispatch[$field] !== $value) {
                self::invalid();
            }
        }
        $usageFacts = [
            'invocation_id' => $invocationId,
            'environment_id' => $expected['environment_id'],
            'organization_id' => $expected['organization_id'],
            'application_id' => $expected['application_id'],
            'workload_client_id' => $expected['workload_client_id'],
            'model_id' => $modelId,
            'deployment_id' => $deploymentId,
            'service_action' => 'sand_ai.workflow.run',
            'source_kind' => 'structured_workflow',
            'capability_code' => 'structured_extraction',
            'idempotency_key' => 'invocation:' . $invocationId,
        ];
        foreach ($usageFacts as $field => $value) {
            if (!array_key_exists($field, $usageEvent) || $usageEvent[$field] !== $value) {
                self::invalid();
            }
        }
        $manifest = $this->validator->validate(
            $rawJson, $input['source_file_ids'], $input['candidates'], $maxOutputCharacters,
            MaterialReviewOutputContract::fromSnapshot($input),
        );
        $sources = $manifest['field_sources'];
        $withheld = $manifest['withheld_candidates'];
        usort($sources, static function (array $left, array $right): int {
            $pathOrder = strcmp($left['field_path'], $right['field_path']);
            return $pathOrder !== 0 ? $pathOrder : $left['source_block_id'] <=> $right['source_block_id'];
        });
        usort($withheld, static function (array $left, array $right): int {
            $fileOrder = $left['workflow_run_file_id'] <=> $right['workflow_run_file_id'];
            return $fileOrder !== 0 ? $fileOrder : strcmp($left['field_path'], $right['field_path']);
        });
        return [
            'payload' => $manifest['payload'],
            'payload_sha256' => StructuredWorkflowPolicy::fingerprint($manifest['payload']),
            'input_sha256' => $input['input_sha256'],
            'source_rows' => $sources,
            'withheld_rows' => $withheld,
            'field_source_count' => count($sources),
            'withheld_candidate_count' => count($withheld),
        ];
    }

    private static function hash(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $value) === 1;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Cross-file review evidence does not match the frozen run');
    }
}
