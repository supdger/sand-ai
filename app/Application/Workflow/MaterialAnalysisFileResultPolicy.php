<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Checks a validated single-file result against its frozen run and actual model dispatch. */
final class MaterialAnalysisFileResultPolicy
{
    /**
     * @param array<string,mixed> $expected Server-owned run, file, task and step facts.
     * @param array<string,mixed> $invocation Persisted terminal invocation facts.
     * @param array<string,mixed> $dispatch Persisted dispatch ledger facts.
     * @param array<string,mixed> $usageEvent Persisted immutable usage event facts.
     * @param array<string,mixed> $manifest MaterialAnalysisOutputValidator result.
     * @param list<int> $promptSourceIds Source blocks actually sent to the model.
     * @param list<int> $currentSourceIds Current blocks for the fixed file and parse.
     * @return array{payload:array<string,mixed>,payload_sha256:string,payload_digest_version:int,source_rows:list<array{field_path:string,source_block_id:int}>,field_source_count:int}
     */
    public function prepare(
        array $expected,
        array $invocation,
        array $dispatch,
        array $usageEvent,
        array $manifest,
        array $promptSourceIds,
        array $currentSourceIds,
        bool $completeChunkCoverage = false,
    ): array {
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id',
            'workflow_run_id', 'workflow_run_file_id', 'file_id', 'parse_id', 'task_id',
            'step_id', 'attempt_no'] as $field) {
            if (!is_int($expected[$field] ?? null) || $expected[$field] < 1) {
                self::invalid();
            }
        }
        $snapshot = $expected['execution_snapshot_sha256'] ?? null;
        $logicalId = $expected['logical_execution_id'] ?? null;
        if (!is_string($snapshot) || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot) !== 1
            || !is_string($logicalId) || preg_match('/\A[a-f0-9]{48}\z/D', $logicalId) !== 1) {
            self::invalid();
        }
        $identity = new MaterialAnalysisFileAttemptIdentity(
            $logicalId, $expected['workflow_run_file_id'], $expected['attempt_no'],
        );
        $requestId = $identity->requestId();
        $dispatchLogicalId = $identity->dispatchLogicalExecutionId();
        $invocationFacts = [
            'environment_id' => $expected['environment_id'],
            'organization_id' => $expected['organization_id'],
            'application_id' => $expected['application_id'],
            'workload_client_id' => $expected['workload_client_id'],
            'parent_workflow_run_id' => $expected['workflow_run_id'],
            'parent_task_run_id' => $expected['task_id'],
            'parent_task_step_id' => $expected['step_id'],
            'parent_workflow_run_file_id' => $expected['workflow_run_file_id'],
            'execution_attempt_no' => $expected['attempt_no'],
            'execution_snapshot_sha256' => $snapshot,
            'logical_execution_id' => $dispatchLogicalId,
            'request_id' => $requestId,
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
            'parent_workflow_run_file_id' => $expected['workflow_run_file_id'],
            'attempt_no' => $expected['attempt_no'],
            'execution_snapshot_sha256' => $snapshot,
            'logical_execution_id' => $dispatchLogicalId,
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
        $promptIds = $this->idSet($promptSourceIds);
        $currentIds = $this->idSet($currentSourceIds);
        if ($currentIds === [] || ($completeChunkCoverage
            ? array_diff_key($promptIds, $currentIds) !== [] : ($promptIds === [] || $promptIds !== $currentIds))) {
            self::invalid();
        }

        // For a chunked file, every leaf and review invocation has already been verified by the writer.
        // The final bounded review prompt is a subset, not a claim to have resent all source content.
        $resultSourceIds = $completeChunkCoverage ? $currentIds : $promptIds;
        $payload = $manifest['payload'] ?? null;
        $sources = $manifest['field_sources'] ?? null;
        if (!is_array($payload) || !is_array($sources)
            || ($payload['schema'] ?? null) !== ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0']
            || !is_array($payload['documents'] ?? null) || $payload['documents'] === []) {
            self::invalid();
        }
        foreach ($payload['documents'] as $document) {
            if (!is_array($document) || ($document['file_id'] ?? null) !== $expected['file_id']) {
                self::invalid();
            }
        }
        $rows = [];
        $seen = [];
        foreach ($sources as $source) {
            $path = is_array($source) ? ($source['field_path'] ?? null) : null;
            $id = is_array($source) ? ($source['source_block_id'] ?? null) : null;
            if (!is_string($path) || !str_starts_with($path, '/') || strlen($path) > 1024
                || !is_int($id) || !isset($resultSourceIds[$id])) {
                self::invalid();
            }
            $key = $path . "\0" . $id;
            if (isset($seen[$key])) {
                self::invalid();
            }
            $seen[$key] = true;
            $rows[] = ['field_path' => $path, 'source_block_id' => $id];
        }
        usort($rows, static function (array $left, array $right): int {
            $pathOrder = strcmp($left['field_path'], $right['field_path']);
            return $pathOrder !== 0 ? $pathOrder : ($left['source_block_id'] <=> $right['source_block_id']);
        });
        return [
            'payload' => $payload,
            'payload_sha256' => MaterialAnalysisFilePayloadDigest::version2($payload),
            'payload_digest_version' => MaterialAnalysisFilePayloadDigest::VERSION,
            'source_rows' => $rows,
            'field_source_count' => count($rows),
        ];
    }

    /** @param list<int> $ids @return array<int,true> */
    private function idSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($set[$id])) {
                self::invalid();
            }
            $set[$id] = true;
        }
        ksort($set, SORT_NUMERIC);
        return $set;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Single-file result evidence does not match the frozen invocation');
    }
}
