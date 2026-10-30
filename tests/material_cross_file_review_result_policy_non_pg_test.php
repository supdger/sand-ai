<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
require $root . '/app/Api/Support/ApiProblem.php';
require $root . '/app/Application/Workflow/MaterialCrossFileReviewOutputValidator.php';
require $root . '/app/Application/Workflow/MaterialCrossFileReviewAttemptIdentity.php';
require $root . '/app/Application/Workflow/StructuredWorkflowPolicy.php';
require $root . '/app/Application/Workflow/MaterialCrossFileReviewResultPolicy.php';

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialCrossFileReviewAttemptIdentity;
use app\Application\Workflow\MaterialCrossFileReviewResultPolicy;
use app\Application\Workflow\StructuredWorkflowPolicy;

$logical = str_repeat('a', 48);
$snapshot = str_repeat('b', 64);
$identity = new MaterialCrossFileReviewAttemptIdentity($logical, 2);
$expected = [
    'environment_id' => 1, 'organization_id' => 2, 'application_id' => 3,
    'workload_client_id' => 4, 'workflow_run_id' => 5, 'task_id' => 6,
    'step_id' => 7, 'attempt_no' => 2, 'execution_snapshot_sha256' => $snapshot,
    'logical_execution_id' => $logical,
];
$invocation = [
    'id' => 8, 'environment_id' => 1, 'organization_id' => 2,
    'application_id' => 3, 'workload_client_id' => 4,
    'parent_workflow_run_id' => 5, 'parent_task_run_id' => 6,
    'parent_task_step_id' => 7, 'parent_workflow_run_file_id' => null,
    'workflow_node_code' => 'cross_file_review', 'execution_attempt_no' => 2,
    'execution_snapshot_sha256' => $snapshot,
    'logical_execution_id' => $identity->dispatchLogicalExecutionId(),
    'request_id' => $identity->requestId(), 'state' => 'succeeded',
    'status' => 1, 'delete_time' => null, 'model_id' => 9, 'deployment_id' => 10,
];
$dispatch = [
    'invocation_id' => 8, 'environment_id' => 1,
    'parent_workflow_run_id' => 5, 'parent_task_run_id' => 6,
    'parent_task_step_id' => 7, 'parent_workflow_run_file_id' => null,
    'workflow_node_code' => 'cross_file_review', 'attempt_no' => 2,
    'execution_snapshot_sha256' => $snapshot,
    'logical_execution_id' => $identity->dispatchLogicalExecutionId(),
    'logical_request_id' => hash('sha256', $identity->requestId()),
    'model_id' => 9, 'deployment_id' => 10,
    'capability_code' => 'structured_extraction', 'state' => 'succeeded',
];
$usage = [
    'invocation_id' => 8, 'environment_id' => 1, 'organization_id' => 2,
    'application_id' => 3, 'workload_client_id' => 4,
    'model_id' => 9, 'deployment_id' => 10,
    'service_action' => 'sand_ai.workflow.run', 'source_kind' => 'structured_workflow',
    'capability_code' => 'structured_extraction', 'idempotency_key' => 'invocation:8',
];
$sourceFileIds = [11 => 101, 22 => 202];
$candidates = [
    ['workflow_run_file_id' => 31, 'file_id' => 101, 'field_path' => '/entities/0/value', 'source_block_ids' => [11]],
    ['workflow_run_file_id' => 32, 'file_id' => 202, 'field_path' => '/entities/0/value', 'source_block_ids' => [22]],
];
$messages = [['role' => 'system', 'content' => 'Review both files'], ['role' => 'user', 'content' => 'sealed results']];
$input = [
    'workflow_run_id' => 5, 'execution_snapshot_sha256' => $snapshot,
    'file_result_ids' => [41, 42], 'sealed_set_sha256' => str_repeat('c', 64),
    'messages' => $messages, 'source_file_ids' => $sourceFileIds,
    'candidates' => $candidates,
    'input_sha256' => hash('sha256', "Review both files\nsealed results"),
];
$current = [
    'workflow_run_id' => 5, 'execution_snapshot_sha256' => $snapshot,
    'messages' => $messages, 'input_sha256' => $input['input_sha256'],
    'file_result_ids' => [41, 42], 'sealed_set_sha256' => str_repeat('c', 64),
    'source_file_ids' => $sourceFileIds, 'candidates' => $candidates,
];
$conflict = json_encode(['items_to_verify' => [[
    'reason' => 'conflict', 'field' => 'amount', 'summary' => 'Values differ',
    'source_block_ids' => [11, 22],
    'affected_candidates' => [
        ['workflow_run_file_id' => 31, 'field_path' => '/entities/0/value'],
        ['workflow_run_file_id' => 32, 'field_path' => '/entities/0/value'],
    ],
]]], JSON_THROW_ON_ERROR);
$policy = new MaterialCrossFileReviewResultPolicy();
$valid = $policy->prepare($expected, $invocation, $dispatch, $usage, $input, $current, $conflict, 4096);
if ($valid['input_sha256'] !== $input['input_sha256']
    || $valid['payload_sha256'] !== StructuredWorkflowPolicy::fingerprint($valid['payload'])
    || $valid['field_source_count'] !== 4
    || $valid['withheld_candidate_count'] !== 2
    || count($valid['payload']['conflicts']) !== 1
    || count($valid['payload']['items_to_verify']) !== 1) {
    throw new RuntimeException('Valid cross-file review lost its evidence');
}
$checks = 1;
$empty = $policy->prepare($expected, $invocation, $dispatch, $usage, $input, $current, '{"items_to_verify":[]}', 4096);
if ($empty['field_source_count'] !== 0 || $empty['withheld_candidate_count'] !== 0) {
    throw new RuntimeException('An empty review invented affected candidates');
}
++ $checks;
$sourceFreeInput = [...$input, 'source_file_ids' => [], 'candidates' => []];
$sourceFreeCurrent = [...$current, 'source_file_ids' => [], 'candidates' => []];
$sourceFree = $policy->prepare(
    $expected, $invocation, $dispatch, $usage, $sourceFreeInput, $sourceFreeCurrent,
    '{"items_to_verify":[]}', 4096,
);
if ($sourceFree['field_source_count'] !== 0 || $sourceFree['withheld_candidate_count'] !== 0) {
    throw new RuntimeException('Source-free file results could not retain a sealed empty review');
}
++ $checks;
$reject = static function (array $e, array $i, array $d, array $u, array $p, array $c, string $raw) use ($policy, &$checks): void {
    try {
        $policy->prepare($e, $i, $d, $u, $p, $c, $raw, 4096);
    } catch (ApiProblem) {
        ++ $checks;
        return;
    }
    throw new RuntimeException('Invalid cross-file review evidence was accepted');
};
$reject($expected, [...$invocation, 'parent_workflow_run_file_id' => 31], $dispatch, $usage, $input, $current, $conflict);
$reject($expected, $invocation, [...$dispatch, 'workflow_node_code' => 'file_analysis'], $usage, $input, $current, $conflict);
$reject($expected, $invocation, [...$dispatch, 'logical_request_id' => str_repeat('f', 64)], $usage, $input, $current, $conflict);
$reject($expected, $invocation, $dispatch, [...$usage, 'idempotency_key' => 'invocation:9'], $input, $current, $conflict);
$reject($expected, $invocation, $dispatch, $usage, [...$input, 'messages' => [
    ['role' => 'system', 'content' => 'changed'], $messages[1],
]], $current, $conflict);
$changedMessages = [['role' => 'system', 'content' => 'changed'], $messages[1]];
$reject($expected, $invocation, $dispatch, $usage, [
    ...$input, 'messages' => $changedMessages,
    'input_sha256' => hash('sha256', "changed\nsealed results"),
], $current, $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, [...$current, 'workflow_run_id' => 6], $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, [...$current, 'execution_snapshot_sha256' => str_repeat('d', 64)], $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, [...$current, 'input_sha256' => str_repeat('e', 64)], $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, [...$current, 'file_result_ids' => [41, 43]], $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, [...$current, 'source_file_ids' => [11 => 202, 22 => 101]], $conflict);
$reject($expected, $invocation, $dispatch, $usage, $input, $current, '{"items_to_verify":[{"reason":"conflict","field":"amount","summary":"Values differ","source_block_ids":[11,22],"affected_candidates":[{"workflow_run_file_id":31,"field_path":"/entities/0/value"},{"workflow_run_file_id":31,"field_path":"/entities/0/value"}]}]}');
echo 'cross-file-review-result-policy ' . $checks . '/' . $checks . PHP_EOL;
