<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Task/TaskState.php',
    'Application/Task/TaskAttemptPolicy.php',
] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class)) {
        require $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskAttemptPolicy;

$checks = 0;
foreach ([[0, 3, 3], [2, 3, 3], [3, 3, 4], [4, 4, 5]] as [$attempt, $limit, $expected]) {
    if (TaskAttemptPolicy::explicitMaterialRetryLimit($attempt, $limit) !== $expected) {
        throw new RuntimeException('Explicit material retry limit is incorrect');
    }
    ++$checks;
}
if (TaskAttemptPolicy::manualRetryAllowed(3, 3)) {
    throw new RuntimeException('Legacy retry unexpectedly reset an exhausted task');
}
++ $checks;
foreach ([[4, 3], [2147483647, 2147483647], [-1, 3], [0, 0]] as [$attempt, $limit]) {
    try {
        TaskAttemptPolicy::explicitMaterialRetryLimit($attempt, $limit);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_TASK_INVALID') {
            throw $problem;
        }
        ++$checks;
        continue;
    }
    throw new RuntimeException('Invalid retry counters were accepted');
}
echo 'material-explicit-retry-policy ' . $checks . '/' . $checks . PHP_EOL;
