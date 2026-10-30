<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Task/TaskState.php',
    'Application/Workflow/WorkflowRunFileState.php',
] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class)) {
        require $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use app\Application\Workflow\WorkflowRunFileState;

$success = ['analysis_state' => 'succeeded', 'attempt_count' => 1, 'last_invocation_id' => 42];
$pending = ['analysis_state' => 'running', 'attempt_count' => 1, 'last_invocation_id' => null];
$result = ['invocation_id' => 42, 'attempt_no' => 1];
$checks = 0;
foreach ([TaskState::RETRYING, TaskState::FAILED, TaskState::CANCELED] as $parentState) {
    if (WorkflowRunFileState::forParentTask($success, $result, $parentState, null, 2, true) !== null) {
        throw new RuntimeException('A parent transition changed a completed file');
    }
    ++ $checks;
}
$retry = WorkflowRunFileState::forParentTask(
    $pending, null, TaskState::RETRYING, 'SAND_AI_PROVIDER_UNAVAILABLE', 2, true,
);
if ($retry !== [
    'analysis_state' => 'retrying',
    'failure_summary' => 'SAND_AI_PROVIDER_UNAVAILABLE',
    'attempt_count' => 2,
]) {
    throw new RuntimeException('An unfinished file did not transition for retry');
}
++ $checks;
$reject = static function (callable $call) use (&$checks): void {
    try {
        $call();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_SCOPE_SNAPSHOT_INVALID') {
            throw $problem;
        }
        ++ $checks;
        return;
    }
    throw new RuntimeException('Inconsistent file success was accepted');
};
$reject(static fn () => WorkflowRunFileState::forParentTask(
    $success, ['invocation_id' => 43, 'attempt_no' => 1], TaskState::FAILED,
));
$reject(static fn () => WorkflowRunFileState::forParentTask(
    $success, null, TaskState::QUEUED,
));
$reject(static fn () => WorkflowRunFileState::forParentTask(
    $pending, null, TaskState::SUCCEEDED, null, null, true,
));

echo 'material-file-state ' . $checks . '/' . $checks . PHP_EOL;
