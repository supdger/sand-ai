<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
$candidateRoot = __DIR__;
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Task/TaskWorkerIdentity.php',
    'Application/Task/TaskExecutionLease.php',
    'Application/Workflow/MaterialAnalysisFileAttemptIdentity.php',
    'Application/Workflow/MaterialCrossFileReviewAttemptIdentity.php',
    'Application/Gateway/InternalModelInvocationContext.php',
    'Application/Workflow/StructuredCompletionContext.php',
    'Application/Usage/UsageAttribution.php',
    'Application/Gateway/DispatchExecutionScope.php',
] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class)) {
        $candidate = $candidateRoot . '/app/' . $path;
        require is_file($candidate) ? $candidate : $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\InternalModelInvocationContext;
use app\Application\Task\TaskExecutionLease;
use app\Application\Workflow\MaterialAnalysisFileAttemptIdentity;
use app\Application\Workflow\MaterialCrossFileReviewAttemptIdentity;
use app\Application\Workflow\StructuredCompletionContext;
use app\Application\Usage\UsageAttribution;

$parent = str_repeat('a', 48);
$first = new MaterialAnalysisFileAttemptIdentity($parent, 6, 1);
$retry = new MaterialAnalysisFileAttemptIdentity($parent, 6, 2);
$otherFile = new MaterialAnalysisFileAttemptIdentity($parent, 7, 1);
$checks = 0;
if ($first->requestId() !== 'structured_' . $parent . '_file_6_attempt_1') {
    throw new RuntimeException('Single-file request identity changed');
}
++ $checks;
if (count(array_unique([
    $first->dispatchLogicalExecutionId(),
    $retry->dispatchLogicalExecutionId(),
    $otherFile->dispatchLogicalExecutionId(),
])) !== 3) {
    throw new RuntimeException('File attempts reuse a dispatch identity');
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
    throw new RuntimeException('Invalid file attempt was accepted');
};
$reject(static fn () => new MaterialAnalysisFileAttemptIdentity($parent, 0, 1));
$reject(static fn () => new MaterialAnalysisFileAttemptIdentity($parent, 6, 0));
$lease = new TaskExecutionLease(9, 'worker:1:abcdef123456', str_repeat('c', 64), 3);
$context = new InternalModelInvocationContext(
    5, 9, 10, 6, 'analyze', $retry->dispatchLogicalExecutionId(),
    str_repeat('d', 64), str_repeat('e', 64), $lease, [], 2, true,
);
$scope = DispatchExecutionScope::internal($context);
if ($scope->attemptNo !== 2 || $scope->logicalExecutionId !== $retry->dispatchLogicalExecutionId()
    || $scope->workflowRunFileId !== 6) {
    throw new RuntimeException('Dispatch scope lost the file attempt');
}
++ $checks;
$reject(static fn () => new InternalModelInvocationContext(
    5, 9, 10, 6, 'analyze', $parent,
    str_repeat('d', 64), str_repeat('e', 64), $lease, [], 2, false,
));
$review = new MaterialCrossFileReviewAttemptIdentity($parent, 2);
$reviewContext = new InternalModelInvocationContext(
    5, 9, 10, null, 'cross_file_review', $review->dispatchLogicalExecutionId(),
    str_repeat('d', 64), str_repeat('e', 64), $lease, [], 2, false, true,
);
$reviewScope = DispatchExecutionScope::internal($reviewContext);
if ($reviewScope->workflowRunFileId !== null || $reviewScope->attemptNo !== 2
    || $reviewScope->logicalNode !== 'cross_file_review') {
    throw new RuntimeException('Cross-file dispatch scope lost the review attempt');
}
++ $checks;
$reject(static fn () => new InternalModelInvocationContext(
    5, 9, 10, 6, 'cross_file_review', $review->dispatchLogicalExecutionId(),
    str_repeat('d', 64), str_repeat('e', 64), $lease, [], 2, false, true,
));
$reject(static fn () => new InternalModelInvocationContext(
    5, 9, 10, null, 'analyze', $review->dispatchLogicalExecutionId(),
    str_repeat('d', 64), str_repeat('e', 64), $lease, [], 2, false, true,
));
$attribution = new UsageAttribution(
    1, 2, 3, 4,
    ['organization_ref' => '2', 'application_ref' => '3', 'workload_client_ref' => '4'],
    'structured_workflow', 'sand_ai.workflow.run', 'structured_extraction',
);
$completion = new StructuredCompletionContext(
    1, 5, 9, 10, null, 'cross_file_review', $lease, str_repeat('d', 64),
    $parent, $attribution, $attribution->applicationScope(), 'model', [], 2, false, true,
);
if ($completion->requestId() !== $review->requestId()
    || $completion->dispatchLogicalExecutionId() !== $review->dispatchLogicalExecutionId()) {
    throw new RuntimeException('Cross-file completion has the wrong request identity');
}
++ $checks;

echo 'material-file-attempt ' . $checks . '/' . $checks . PHP_EOL;
