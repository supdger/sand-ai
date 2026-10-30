<?php

declare(strict_types=1);

$app = getenv('SAND_AI_TEST_APP_ROOT') ?: dirname(__DIR__) . '/app';
foreach ([
    'app\\Application\\Budget\\BudgetDecimal' => '/Application/Budget/BudgetDecimal.php',
    'app\\Application\\Budget\\BudgetAlertPolicy' => '/Application/Budget/BudgetAlertPolicy.php',
] as $class => $path) {
    if (!class_exists($class, false)) {
        require $app . $path;
    }
}

use app\Application\Budget\BudgetAlertPolicy;

$cases = [
    ['100.000000', '20.000001', 'normal', null],
    ['100.000000', '20.000000', 'notice', 80],
    ['100.000000', '10.000000', 'warning', 90],
    ['100.000000', '0.000000', 'exhausted', 100],
    ['100.000000', '-0.000001', 'exhausted', 100],
    ['0.000001', '0.000001', 'normal', null],
    ['99999999999999.999999', '20000000000000.000000', 'normal', null],
    ['99999999999999.999999', '19999999999999.999999', 'notice', 80],
];
foreach ($cases as $index => [$limit, $available, $level, $threshold]) {
    $actual = BudgetAlertPolicy::forWindow([
        'limit_amount' => $limit,
        'available_amount' => $available,
    ]);
    if ($actual !== ['level' => $level, 'threshold_percent' => $threshold]) {
        throw new RuntimeException('Budget alert boundary failed at case ' . ($index + 1));
    }
}

echo 'budget-alert ' . count($cases) . '/' . count($cases) . PHP_EOL;
