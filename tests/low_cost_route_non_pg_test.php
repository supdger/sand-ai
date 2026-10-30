<?php

declare(strict_types=1);

$app = getenv('SAND_AI_TEST_APP_ROOT') ?: dirname(__DIR__) . '/app';
foreach ([
    'app\\Api\\Support\\ApiProblem' => '/Api/Support/ApiProblem.php',
    'app\\Application\\Budget\\BudgetDecimal' => '/Application/Budget/BudgetDecimal.php',
    'app\\Application\\Gateway\\LowCostRoutePolicy' => '/Application/Gateway/LowCostRoutePolicy.php',
    'app\\Application\\Gateway\\DispatchClaimRoutePolicy' => '/Application/Gateway/DispatchClaimRoutePolicy.php',
] as $class => $path) {
    if (!class_exists($class, false)) require $app . $path;
}

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchClaimRoutePolicy;
use app\Application\Gateway\LowCostRoutePolicy;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Low-cost route check failed at ' . ($checks + 1));
    ++$checks;
};
$candidate = static fn (string $amount, string $currency, int $priority, int $routeId): array => [
    'estimated_amount' => $amount, 'currency' => $currency, 'priority' => $priority,
    'route_id' => $routeId, 'model_code' => 'model-' . $routeId,
    'deployment_id' => $routeId, 'price_revision_id' => $routeId,
];
$assert(LowCostRoutePolicy::choose([
    $candidate('0.100001', 'CNY', 1, 1),
    $candidate('0.100000', 'CNY', 9, 2),
])['route_id'] === 2);
$assert(LowCostRoutePolicy::choose([
    $candidate('0.100000', 'CNY', 2, 1),
    $candidate('0.100000', 'CNY', 1, 2),
])['route_id'] === 2);
$assert(LowCostRoutePolicy::choose([
    $candidate('0.100000', 'CNY', 1, 3),
    $candidate('0.100000', 'CNY', 1, 2),
])['route_id'] === 2);
foreach ([
    [[], 'SAND_AI_LOW_COST_ROUTE_UNAVAILABLE'],
    [[$candidate('1.000000', 'CNY', 1, 1), $candidate('0.001000', 'USD', 2, 2)], 'SAND_AI_LOW_COST_CURRENCY_MISMATCH'],
] as [$routes, $errorCode]) {
    try {
        LowCostRoutePolicy::choose($routes);
        throw new RuntimeException('Expected ' . $errorCode);
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === $errorCode);
    }
}
$assert(DispatchClaimRoutePolicy::usesCapabilityProfileRoute(['selection_kind' => 'lowest_cost'], 'chat', 'chat'));
$assert(!DispatchClaimRoutePolicy::usesCapabilityProfileRoute(['selection_kind' => 'lowest_cost'], 'ai_task', 'chat'));
$assert(!DispatchClaimRoutePolicy::usesCapabilityProfileRoute(['selection_kind' => 'lowest_cost'], 'chat', 'skill'));
echo 'low-cost-route ' . $checks . '/' . $checks . PHP_EOL;
