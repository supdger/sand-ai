<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;

/** Checks a completed material run before its exact draft revision can be published. */
final class MaterialAnalysisSampleEvidencePolicy
{
    /** @param array<string,mixed> $profile @param array<string,mixed> $run @param array<string,mixed> $task
     *  @param array<string,mixed> $artifact @param array<string,mixed> $invocation
     *  @param array<string,mixed> $dispatch @param array<string,mixed> $usage */
    public function assertPublishable(array $profile, array $run, array $task, array $artifact, array $invocation, array $dispatch, array $usage, ?array $sealed = null): void
    {
        $profileId = $this->positiveId($profile['id'] ?? null);
        $environmentId = $this->positiveId($profile['environment_id'] ?? null);
        $revision = $this->positiveId($profile['revision'] ?? null);
        $runId = $this->positiveId($run['id'] ?? null);
        $taskId = $this->positiveId($run['task_id'] ?? null);
        $invocationId = $this->positiveId($run['last_invocation_id'] ?? null);
        $config = is_array($profile['config'] ?? null)
            ? (new MaterialAnalysisProfilePolicy())->validate($profile['config']) : null;
        $input = $run['input_snapshot'] ?? null;
        $execution = $run['execution_snapshot'] ?? null;
        $sample = is_array($input) ? ($input['material_profile_sample'] ?? null) : null;
        $frozen = is_array($input) ? ($input['analysis_profile'] ?? null) : null;
        $taskInput = $task['input'] ?? null;
        if ($profileId === null || $environmentId === null || $revision === null
            || $runId === null || $taskId === null || $invocationId === null
            || !is_array($config) || !is_array($input) || !is_array($execution) || !is_array($taskInput)
            || !is_array($sample) || !is_array($frozen)
            || $this->positiveId($sample['profile_id'] ?? null) !== $profileId
            || $this->positiveId($sample['revision'] ?? null) !== $revision
            || ($sample['config_sha256'] ?? null) !== StructuredWorkflowPolicy::fingerprint($config)
            || ($run['environment_id'] ?? null) !== $environmentId
            || ($run['workflow_code'] ?? null) !== 'material_analysis'
            || ($run['status'] ?? null) !== 1
            || ($frozen['code'] ?? null) !== ($profile['code'] ?? null)
            || ($frozen['version'] ?? null) !== ($profile['version'] ?? null)
            || !is_array($frozen['snapshot'] ?? null)
            || StructuredWorkflowPolicy::fingerprint($frozen['snapshot']) !== StructuredWorkflowPolicy::fingerprint($config)
            || !is_array($execution['analysis_profile'] ?? null)
            || StructuredWorkflowPolicy::fingerprint($execution['analysis_profile']) !== StructuredWorkflowPolicy::fingerprint($frozen)
            || !is_array($execution['material_profile_sample'] ?? null)
            || StructuredWorkflowPolicy::fingerprint($execution['material_profile_sample']) !== StructuredWorkflowPolicy::fingerprint($sample)
            || ($run['execution_snapshot_sha256'] ?? null) !== StructuredWorkflowPolicy::fingerprint($execution)
            || ($taskInput['execution_snapshot_sha256'] ?? null) !== ($run['execution_snapshot_sha256'] ?? null)
            || ($taskInput['snapshot_sha256'] ?? null) !== ($run['snapshot_sha256'] ?? null)
            || ($taskInput['workflow_run_id'] ?? null) !== $runId
            || ($task['id'] ?? null) !== $taskId
            || ($task['environment_id'] ?? null) !== $environmentId
            || ($task['resource_type'] ?? null) !== 'workflow_run'
            || ($task['resource_id'] ?? null) !== $runId
            || ($task['state'] ?? null) !== TaskState::SUCCEEDED
            || ($artifact['workflow_run_id'] ?? null) !== $runId
            || ($artifact['state'] ?? null) !== 'succeeded'
            || ($artifact['status'] ?? null) !== 1
            || ($usage['invocation_id'] ?? null) !== $invocationId
            || ($usage['environment_id'] ?? null) !== $environmentId
            || ($usage['source_kind'] ?? null) !== 'structured_workflow'
            || ($usage['service_action'] ?? null) !== 'sand_ai.workflow.run'
            || ($usage['capability_code'] ?? null) !== 'structured_extraction') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The exact material profile revision has no completed, metered sample');
        }
        foreach (['organization_id', 'application_id', 'workload_client_id'] as $field) {
            $id = $this->positiveId($run[$field] ?? null);
            if ($id === null || ($usage[$field] ?? null) !== $id || ($invocation[$field] ?? null) !== $id) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample attribution is incomplete');
            }
        }
        $logicalId = $run['logical_execution_id'] ?? null;
        $executionHash = $run['execution_snapshot_sha256'] ?? null;
        if (!is_string($logicalId) || $logicalId === ''
            || !is_string($executionHash) || $executionHash === ''
            || ($invocation['id'] ?? null) !== $invocationId
            || ($invocation['state'] ?? null) !== 'succeeded'
            || ($invocation['environment_id'] ?? null) !== $environmentId
            || ($invocation['parent_workflow_run_id'] ?? null) !== $runId
            || ($invocation['parent_task_run_id'] ?? null) !== $taskId
            || ($invocation['execution_snapshot_sha256'] ?? null) !== $executionHash
            || ($dispatch['invocation_id'] ?? null) !== $invocationId
            || ($dispatch['state'] ?? null) !== 'succeeded'
            || ($dispatch['environment_id'] ?? null) !== $environmentId
            || ($dispatch['parent_workflow_run_id'] ?? null) !== $runId
            || ($dispatch['parent_task_run_id'] ?? null) !== $taskId
            || ($dispatch['execution_snapshot_sha256'] ?? null) !== $executionHash
            || ($dispatch['application_id'] ?? null) !== ($run['application_id'] ?? null)
            || ($dispatch['service_action'] ?? null) !== 'sand_ai.workflow.run'
            || ($dispatch['source_kind'] ?? null) !== 'structured_workflow'
            || ($dispatch['capability_code'] ?? null) !== 'structured_extraction'
            || $this->positiveId($invocation['model_id'] ?? null) === null
            || ($invocation['model_id'] ?? null) !== ($dispatch['model_id'] ?? null)
            || $this->positiveId($invocation['deployment_id'] ?? null) === null
            || ($invocation['deployment_id'] ?? null) !== ($dispatch['deployment_id'] ?? null)
            || ($usage['model_id'] ?? null) !== ($invocation['model_id'] ?? null)
            || ($usage['deployment_id'] ?? null) !== ($invocation['deployment_id'] ?? null)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The sample meter is not bound to the terminal workflow invocation');
        }
        $mode = $input['material_execution_mode'] ?? null;
        if ($mode === null) {
            if (($invocation['request_id'] ?? null) !== 'structured_' . $logicalId
                || ($invocation['logical_execution_id'] ?? null) !== $logicalId
                || ($dispatch['logical_execution_id'] ?? null) !== $logicalId) {
                self::invalid();
            }
            return;
        }
        if ($mode !== 'single_file_v1' || $sealed === null) {
            self::invalid();
        }
        $this->assertSealedFiles($run, $task, $invocation, $dispatch, $usage, $sealed);
    }

    /** @param array<string,mixed> $run @param array<string,mixed> $task
     *  @param array<string,mixed> $terminalInvocation @param array<string,mixed> $terminalDispatch
     *  @param array<string,mixed> $terminalUsage @param array<string,mixed> $sealed */
    private function assertSealedFiles(array $run, array $task, array $terminalInvocation, array $terminalDispatch, array $terminalUsage, array $sealed): void
    {
        $frozen = $run['file_snapshot'] ?? null;
        $files = $sealed['files'] ?? null;
        $stepId = $this->positiveId($sealed['step_id'] ?? null);
        if (!is_array($frozen) || !array_is_list($frozen) || $frozen === []
            || !is_array($files) || count($files) !== count($frozen) || $stepId === null) {
            self::invalid();
        }
        $seen = [];
        $last = null;
        foreach ($files as $entry) {
            if (!is_array($entry) || !is_array($entry['run_file'] ?? null)
                || !is_array($entry['result'] ?? null) || !is_array($entry['invocation'] ?? null)
                || !is_array($entry['dispatch'] ?? null) || !is_array($entry['usage'] ?? null)) {
                self::invalid();
            }
            $file = $entry['run_file'];
            $result = $entry['result'];
            $invocation = $entry['invocation'];
            $dispatch = $entry['dispatch'];
            $usage = $entry['usage'];
            $fileId = $this->positiveId($file['file_id'] ?? null);
            $runFileId = $this->positiveId($file['id'] ?? null);
            $attempt = $this->positiveId($file['attempt_count'] ?? null);
            $invocationId = $this->positiveId($result['invocation_id'] ?? null);
            if ($fileId === null || $runFileId === null || $attempt === null || $invocationId === null
                || isset($seen[$fileId]) || ($file['status'] ?? null) !== 1
                || ($file['delete_time'] ?? null) !== null || ($file['analysis_state'] ?? null) !== 'succeeded'
                || ($file['last_invocation_id'] ?? null) !== $invocationId
                || ($result['workflow_run_file_id'] ?? null) !== $runFileId
                || ($result['attempt_no'] ?? null) !== $attempt
                || ($result['execution_snapshot_sha256'] ?? null) !== ($run['execution_snapshot_sha256'] ?? null)
                || ($result['schema_code'] ?? null) !== 'sand_ai.material_analysis'
                || ($result['schema_version'] ?? null) !== '1.0.0'
                || ($entry['source_count'] ?? null) !== ($result['field_source_count'] ?? null)
                || !is_int($entry['source_count'] ?? null) || $entry['source_count'] < 1) {
                self::invalid();
            }
            $seen[$fileId] = $file;
            $identity = new MaterialAnalysisFileAttemptIdentity((string) $run['logical_execution_id'], $runFileId, $attempt);
            $this->assertInvocationChain($run, $task, $stepId, $runFileId, null, $attempt,
                $identity->requestId(), $identity->dispatchLogicalExecutionId(), $invocation, $dispatch, $usage);
            $last = [$invocation, $dispatch, $usage];
        }
        foreach ($frozen as $item) {
            if (!is_array($item) || !isset($seen[$item['file_id'] ?? null])) {
                self::invalid();
            }
            $current = $seen[$item['file_id']];
            foreach (['file_id', 'parse_id', 'sha256', 'version_ref'] as $field) {
                if (($current[$field] ?? null) !== ($item[$field] ?? null)) {
                    self::invalid();
                }
            }
        }
        if (count($files) === 1) {
            if (($sealed['review'] ?? null) !== null || $last === null
                || ($terminalInvocation['id'] ?? null) !== ($last[0]['id'] ?? null)
                || ($terminalDispatch['invocation_id'] ?? null) !== ($last[1]['invocation_id'] ?? null)
                || ($terminalUsage['id'] ?? null) !== ($last[2]['id'] ?? null)) {
                self::invalid();
            }
            return;
        }
        $review = $sealed['review'] ?? null;
        if (!is_array($review) || !is_array($review['state'] ?? null) || !is_array($review['result'] ?? null)) {
            self::invalid();
        }
        $state = $review['state'];
        $result = $review['result'];
        $attempt = $this->positiveId($state['attempt_count'] ?? null);
        $terminalId = $this->positiveId($terminalInvocation['id'] ?? null);
        if ($attempt === null || $terminalId === null || ($state['state'] ?? null) !== 'succeeded'
            || ($state['last_invocation_id'] ?? null) !== $terminalId
            || ($result['workflow_run_id'] ?? null) !== ($run['id'] ?? null)
            || ($result['attempt_no'] ?? null) !== $attempt
            || ($result['invocation_id'] ?? null) !== $terminalId
            || ($result['execution_snapshot_sha256'] ?? null) !== ($run['execution_snapshot_sha256'] ?? null)
            || ($review['source_count'] ?? null) !== ($result['field_source_count'] ?? null)) {
            self::invalid();
        }
        $reviewIdentity = new MaterialCrossFileReviewAttemptIdentity((string) $run['logical_execution_id'], $attempt);
        $this->assertInvocationChain($run, $task, $stepId, null, 'cross_file_review', $attempt,
            $reviewIdentity->requestId(), $reviewIdentity->dispatchLogicalExecutionId(),
            $terminalInvocation, $terminalDispatch, $terminalUsage);
    }

    /** @param array<string,mixed> $run @param array<string,mixed> $task
     *  @param array<string,mixed> $invocation @param array<string,mixed> $dispatch @param array<string,mixed> $usage */
    private function assertInvocationChain(array $run, array $task, int $stepId, ?int $runFileId,
        ?string $nodeCode, int $attempt, string $requestId, string $logicalId,
        array $invocation, array $dispatch, array $usage): void
    {
        $id = $this->positiveId($invocation['id'] ?? null);
        $expectedInvocation = [
            'environment_id' => $run['environment_id'], 'organization_id' => $run['organization_id'],
            'application_id' => $run['application_id'], 'workload_client_id' => $run['workload_client_id'],
            'parent_workflow_run_id' => $run['id'], 'parent_task_run_id' => $task['id'],
            'parent_task_step_id' => $stepId, 'parent_workflow_run_file_id' => $runFileId,
            'execution_attempt_no' => $attempt, 'execution_snapshot_sha256' => $run['execution_snapshot_sha256'],
            'logical_execution_id' => $logicalId, 'request_id' => $requestId,
            'state' => 'succeeded', 'status' => 1, 'delete_time' => null,
        ];
        $expectedDispatch = [
            'invocation_id' => $id, 'environment_id' => $run['environment_id'],
            'application_id' => $run['application_id'], 'parent_workflow_run_id' => $run['id'],
            'parent_task_run_id' => $task['id'], 'parent_task_step_id' => $stepId,
            'parent_workflow_run_file_id' => $runFileId, 'attempt_no' => $attempt,
            'execution_snapshot_sha256' => $run['execution_snapshot_sha256'],
            'logical_execution_id' => $logicalId, 'logical_request_id' => hash('sha256', $requestId),
            'service_action' => 'sand_ai.workflow.run', 'source_kind' => 'structured_workflow',
            'capability_code' => 'structured_extraction', 'state' => 'succeeded',
            'model_id' => $invocation['model_id'] ?? null, 'deployment_id' => $invocation['deployment_id'] ?? null,
        ];
        $expectedUsage = [
            'invocation_id' => $id, 'environment_id' => $run['environment_id'],
            'organization_id' => $run['organization_id'], 'application_id' => $run['application_id'],
            'workload_client_id' => $run['workload_client_id'],
            'service_action' => 'sand_ai.workflow.run', 'source_kind' => 'structured_workflow',
            'capability_code' => 'structured_extraction', 'idempotency_key' => 'invocation:' . $id,
            'model_id' => $invocation['model_id'] ?? null, 'deployment_id' => $invocation['deployment_id'] ?? null,
        ];
        if ($id === null || $this->positiveId($invocation['model_id'] ?? null) === null
            || $this->positiveId($invocation['deployment_id'] ?? null) === null) {
            self::invalid();
        }
        foreach ([$expectedInvocation, $expectedDispatch, $expectedUsage] as $index => $expected) {
            $actual = [$invocation, $dispatch, $usage][$index];
            foreach ($expected as $field => $value) {
                if (!array_key_exists($field, $actual) || $actual[$field] !== $value) {
                    self::invalid();
                }
            }
        }
        if ($nodeCode !== null && (($invocation['workflow_node_code'] ?? null) !== $nodeCode
            || ($dispatch['workflow_node_code'] ?? null) !== $nodeCode)) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The sample meter is not bound to the sealed material run');
    }

    private function positiveId(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
