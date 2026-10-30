<?php

declare(strict_types=1);

$root = (getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__)) . '/app/Application/Task';
if (!class_exists(\app\Application\Task\TaskState::class)) {
    require $root . '/TaskState.php';
}
if (!class_exists(\app\Application\Task\MaterialFileFinalizationRecoveryPolicy::class)) {
    require $root . '/MaterialFileFinalizationRecoveryPolicy.php';
}

use app\Application\Task\MaterialFileFinalizationRecoveryPolicy;

$run = ['task_id' => 7, 'workflow_code' => 'material_analysis', 'material_execution_mode' => 'single_file_v1'];
$sealed = [
    ['id' => 11, 'analysis_state' => 'succeeded'],
    ['id' => 12, 'analysis_state' => 'succeeded'],
];
$cases = [
    'one sealed file' => [7, $run, [$sealed[0]], null, true],
    'multiple files without review' => [7, $run, $sealed, null, false],
    'multiple files with sealed review' => [7, $run, $sealed, [
        'state' => 'succeeded', 'attempt_count' => 2, 'last_invocation_id' => 42,
        'result_attempt_no' => 2, 'result_invocation_id' => 42,
    ], true],
    'multiple files with pending review' => [7, $run, $sealed, [
        'state' => 'running', 'attempt_count' => 2, 'last_invocation_id' => null,
        'result_attempt_no' => null, 'result_invocation_id' => null,
    ], false],
    'multiple files with mismatched review' => [7, $run, $sealed, [
        'state' => 'succeeded', 'attempt_count' => 2, 'last_invocation_id' => 42,
        'result_attempt_no' => 2, 'result_invocation_id' => 43,
    ], false],
    'one file incomplete' => [7, $run, [['id' => 11, 'analysis_state' => 'succeeded'], ['id' => 12, 'analysis_state' => 'failed']], null, false],
    'legacy batch' => [7, [...$run, 'material_execution_mode' => null], $sealed, null, false],
    'other task' => [8, $run, $sealed, null, false],
    'no files' => [7, $run, [], null, false],
    'duplicate fixed file' => [7, $run, [$sealed[0], $sealed[0]], null, false],
];
foreach ($cases as $name => [$taskId, $candidate, $files, $review, $expected]) {
    $actual = MaterialFileFinalizationRecoveryPolicy::needed($taskId, $candidate, $files, $review);
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' gave the wrong recovery route');
    }
}
echo count($cases) . '/' . count($cases) . " material local-finalization routes passed\n";
