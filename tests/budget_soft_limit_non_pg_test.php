<?php

declare(strict_types=1);

$app = getenv('SAND_AI_TEST_APP_ROOT') ?: dirname(__DIR__) . '/app';
foreach ([
    'app\\Api\\Support\\ApiProblem' => '/Api/Support/ApiProblem.php',
    'app\\Application\\Budget\\BudgetDecimal' => '/Application/Budget/BudgetDecimal.php',
    'app\\Application\\Budget\\BudgetReservationStateMachine' => '/Application/Budget/BudgetReservationStateMachine.php',
] as $class => $path) {
    if (!class_exists($class, false)) {
        require $app . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetReservationStateMachine;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Soft budget check failed at ' . ($checks + 1));
    }
    ++$checks;
};
$window = [
    'currency' => 'CNY', 'limit_amount' => '10.000000',
    'reserved_amount' => '8.000000', 'settled_amount' => '0.000000',
    'held_amount' => '0.000000',
];
$machine = new BudgetReservationStateMachine();
try {
    $machine->reserve($window, '3.000000', 'CNY');
    throw new RuntimeException('Hard budget accepted an overrun');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_BUDGET_EXCEEDED');
}
$soft = $machine->reserve($window, '3.000000', 'CNY', true);
$assert($soft['window']['reserved_amount'] === '11.000000');
$assert($soft['window']['available_amount'] === '-1.000000');
$assert($soft['reservation']['estimate_amount'] === '3.000000');
$settled = $machine->settle($soft['window'], $soft['reservation'], '2.500000', 'CNY');
$assert($settled['window']['reserved_amount'] === '8.000000');
$assert($settled['window']['settled_amount'] === '2.500000');
$assert($settled['window']['available_amount'] === '-0.500000');
$assert($settled['overrun']);
$released = $machine->release($soft['window'], $soft['reservation'], 'CNY');
$assert($released['window']['available_amount'] === '2.000000');
echo 'budget-soft-limit ' . $checks . '/' . $checks . PHP_EOL;
