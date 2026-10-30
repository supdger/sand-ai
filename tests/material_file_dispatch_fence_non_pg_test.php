<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Task/TaskState.php',
    'Application/Task/DispatchRetryPolicy.php',
    'Application/Task/MaterialFileDispatchFence.php',
] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class)) {
        require $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Task\MaterialFileDispatchFence;

$completed = [
    'id' => 11, 'invocation_id' => 21, 'parent_workflow_run_id' => 5,
    'parent_workflow_run_file_id' => 31, 'attempt_no' => 1, 'state' => 'succeeded',
];
$unfinished = [
    'id' => 12, 'invocation_id' => 22, 'parent_workflow_run_id' => 5,
    'parent_workflow_run_file_id' => 32, 'attempt_no' => 1, 'state' => 'ambiguous',
];
$result = [
    31 => [
        'invocation_id' => 21, 'attempt_no' => 1,
        'analysis_state' => 'succeeded', 'file_attempt_no' => 1, 'last_invocation_id' => 21,
    ],
];
$checks = 0;
if (MaterialFileDispatchFence::unresolved(5, [$completed], $result) !== null) {
    throw new RuntimeException('Completed file blocked the parent task');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [$unfinished, $completed], $result) !== $unfinished) {
    throw new RuntimeException('Unresolved dispatch was bypassed');
}
++ $checks;
$failedPriorAttempt = [
    'id' => 10, 'invocation_id' => 20, 'parent_workflow_run_id' => 5,
    'parent_workflow_run_file_id' => 31, 'attempt_no' => 1, 'state' => 'failed',
];
$retrySuccess = [
    'id' => 13, 'invocation_id' => 23, 'parent_workflow_run_id' => 5,
    'parent_workflow_run_file_id' => 31, 'attempt_no' => 2, 'state' => 'succeeded',
];
$retriedResult = [
    31 => [
        'invocation_id' => 23, 'attempt_no' => 2,
        'analysis_state' => 'succeeded', 'file_attempt_no' => 2, 'last_invocation_id' => 23,
    ],
];
if (MaterialFileDispatchFence::unresolved(5, [$retrySuccess, $failedPriorAttempt], $retriedResult) !== null) {
    throw new RuntimeException('A proven terminal prior attempt blocked the completed retry');
}
++ $checks;
$unsealedFile = [
    31 => ['analysis_state' => 'failed', 'file_attempt_no' => 1, 'last_invocation_id' => null],
];
if (MaterialFileDispatchFence::unresolved(5, [$failedPriorAttempt], [], null, $unsealedFile) !== null) {
    throw new RuntimeException('A known failed file dispatch blocked targeted retry');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [$failedPriorAttempt], [], null, [
    31 => ['analysis_state' => 'pending', 'file_attempt_no' => 1, 'last_invocation_id' => null],
]) !== null) {
    throw new RuntimeException('A prior failed file dispatch blocked its queued retry');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [[...$failedPriorAttempt, 'state' => 'ambiguous']], [], null, $unsealedFile) === null) {
    throw new RuntimeException('An ambiguous file dispatch became retryable');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [[...$failedPriorAttempt, 'state' => 'succeeded']], [], null, $unsealedFile) === null) {
    throw new RuntimeException('An unsealed successful file dispatch became retryable');
}
++ $checks;
$reject = static function (callable $call) use (&$checks): void {
    try {
        $call();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_SCOPE_SNAPSHOT_INVALID') {
            throw $problem;
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('Invalid dispatch evidence was accepted');
};
if (MaterialFileDispatchFence::unresolved(
    5, [$failedPriorAttempt], [], null, [
        31 => ['analysis_state' => 'failed', 'file_attempt_no' => 0, 'last_invocation_id' => null],
    ],
) === null) {
    throw new RuntimeException('A failed dispatch beyond the file attempt was bypassed');
}
++ $checks;
$reviewDispatch = [
    'id' => 14, 'invocation_id' => 24, 'parent_workflow_run_id' => 5,
    'parent_workflow_run_file_id' => null, 'workflow_node_code' => 'cross_file_review',
    'attempt_no' => 1, 'state' => 'succeeded',
];
$pendingReview = [
    'state' => 'recovery_pending', 'attempt_count' => 1, 'last_invocation_id' => null,
    'result_attempt_no' => null, 'result_invocation_id' => null,
];
if (MaterialFileDispatchFence::unresolved(5, [$reviewDispatch, $completed], $result, $pendingReview) !== $reviewDispatch) {
    throw new RuntimeException('Unsealed review dispatch was bypassed');
}
++ $checks;
$sealedReview = [
    'state' => 'succeeded', 'attempt_count' => 1, 'last_invocation_id' => 24,
    'result_attempt_no' => 1, 'result_invocation_id' => 24,
];
if (MaterialFileDispatchFence::unresolved(5, [$reviewDispatch, $completed], $result, $sealedReview) !== null) {
    throw new RuntimeException('Sealed review dispatch blocked local finalization');
}
++ $checks;
$failedReviewDispatch = [...$reviewDispatch, 'state' => 'failed'];
$failedReview = [
    'state' => 'failed', 'attempt_count' => 1, 'last_invocation_id' => null,
    'result_attempt_no' => null, 'result_invocation_id' => null,
];
if (MaterialFileDispatchFence::unresolved(5, [$failedReviewDispatch, $completed], $result, $failedReview) !== null) {
    throw new RuntimeException('A known failed review blocked a fresh review attempt');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [$failedReviewDispatch, $completed], $result, [
    ...$failedReview, 'state' => 'pending',
]) !== null) {
    throw new RuntimeException('A prior failed review blocked a queued review attempt');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [$failedReviewDispatch, $completed], $result, [
    ...$failedReview, 'state' => 'running', 'attempt_count' => 2,
]) !== null) {
    throw new RuntimeException('A prior failed review blocked the next attempt');
}
++ $checks;
if (MaterialFileDispatchFence::unresolved(5, [[...$reviewDispatch, 'state' => 'ambiguous'], $completed], $result, $failedReview) === null) {
    throw new RuntimeException('An ambiguous review dispatch became retryable');
}
++ $checks;
$reject(static fn () => MaterialFileDispatchFence::unresolved(
    5, [$completed], [31 => [...$result[31], 'last_invocation_id' => 99]],
));
$reject(static fn () => MaterialFileDispatchFence::unresolved(
    5, [$completed], [31 => [...$result[31], 'file_attempt_no' => 2]],
));
$reject(static fn () => MaterialFileDispatchFence::unresolved(
    5, [[...$completed, 'invocation_id' => 99]], $result,
));
$reject(static fn () => MaterialFileDispatchFence::unresolved(
    5, [$completed], [31 => $result[31], 32 => $retriedResult[31]],
));
$reject(static fn () => MaterialFileDispatchFence::unresolved(
    5, [$reviewDispatch, $completed], $result, [...$sealedReview, 'last_invocation_id' => 25],
));
echo 'material-file-dispatch-fence ' . $checks . '/' . $checks . PHP_EOL;
