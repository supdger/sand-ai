<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
require $root . '/app/Api/Support/ApiProblem.php';
require $root . '/app/Application/Task/TaskState.php';
require $root . '/app/Application/Workflow/WorkflowRunFileState.php';

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use app\Application\Workflow\WorkflowRunFileState;

$transitions = [
    [TaskState::AUTHORIZATION_PENDING, 'pending', null],
    [TaskState::QUEUED, 'pending', null],
    [TaskState::RUNNING, 'running', null],
    [TaskState::RETRYING, 'retrying', 'SAND_AI_PROVIDER_UNAVAILABLE'],
    [TaskState::RECOVERY_PENDING, 'recovery_pending', 'SAND_AI_LOCAL_RECOVERY_PENDING'],
    [TaskState::FAILED, 'failed', 'SAND_AI_SERVICE_ACTION_FORBIDDEN'],
    [TaskState::CANCELED, 'canceled', null],
    [TaskState::SUCCEEDED, 'succeeded', null],
];
foreach ($transitions as [$taskState, $fileState, $errorCode]) {
    $values = WorkflowRunFileState::forTask($taskState, $errorCode);
    if ($values !== ['analysis_state' => $fileState, 'failure_summary' => $errorCode]) {
        throw new RuntimeException('Workflow file transition did not follow task state');
    }
}
$safe = WorkflowRunFileState::present(41, 'failed', 2, 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
if ($safe !== ['file_id' => 41, 'analysis_state' => 'failed', 'attempt_count' => 2, 'error_code' => 'SAND_AI_SERVICE_ACTION_FORBIDDEN']) {
    throw new RuntimeException('Workflow file status lost a stable error code');
}
$sensitive = WorkflowRunFileState::present(41, 'failed', 2, 'Provider response: private source text');
if ($sensitive['error_code'] !== null
    || WorkflowRunFileState::present(41, 'succeeded', 2, 'SAND_AI_OLD_ERROR')['error_code'] !== null) {
    throw new RuntimeException('Workflow file status exposed a failure summary outside its state');
}
try {
    WorkflowRunFileState::present(41, 'unknown', 1, null);
    throw new RuntimeException('Unknown file state was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_SCOPE_SNAPSHOT_INVALID') {
        throw $problem;
    }
}

echo "workflow-file-state 11/11\n";
