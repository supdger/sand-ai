<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    \app\Api\Support\ApiProblem::class => '/app/Api/Support/ApiProblem.php',
    \app\Application\Budget\BudgetDecimal::class => '/app/Application/Budget/BudgetDecimal.php',
    \app\Application\Workflow\MaterialWorkflowUsageAggregator::class => '/app/Application/Workflow/MaterialWorkflowUsageAggregator.php',
    \app\Application\Workflow\StructuredServiceRunPresenter::class => '/app/Application/Workflow/StructuredServiceRunPresenter.php',
] as $class => $file) {
    if (!class_exists($class)) {
        $candidate = __DIR__ . $file;
        require is_file($candidate) ? $candidate : $root . $file;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialWorkflowUsageAggregator;
use app\Application\Workflow\StructuredServiceRunPresenter;

$aggregator = new MaterialWorkflowUsageAggregator();
$tests = 0;
$check = static function (bool $condition) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('Material usage aggregate check ' . $tests . ' failed');
    }
};
$event = static fn (string $amount, string $currency, int $input, int $output): array => [
    'usage_state' => 'measured',
    'pricing_state' => 'priced',
    'tokens' => ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output],
    'metered_amount' => $amount,
    'currency' => $currency,
];
$attempt = static fn (int $id, int $file, int $number, string $state, ?array $usage): array => [
    'invocation_id' => $id,
    'file_id' => $file,
    'attempt_no' => $number,
    'dispatch_state' => $state,
    'usage' => $usage,
];

$check($aggregator->summarize([]) === null);
$retry = $aggregator->summarize([
    $attempt(11, 101, 1, 'failed', $event('1.100000', 'USD', 20, 5)),
    $attempt(12, 101, 2, 'succeeded', $event('2.200000', 'USD', 30, 7)),
    $attempt(13, 102, 1, 'not_dispatched', null),
]);
$check($retry['attempt_count'] === 3 && $retry['event_count'] === 2);
$check($retry['metered_amount'] === '3.300000' && $retry['currency'] === 'USD');
$check($retry['tokens'] === ['input_tokens' => 50, 'output_tokens' => 12, 'total_tokens' => 62]);
$check($retry['missing_event_count'] === 0);
$projected = (new StructuredServiceRunPresenter())->present(
    ['id' => 9, 'state' => 'succeeded'],
    ['code' => 'material_analysis', 'version' => '1', 'name' => 'Material'],
    null,
    $retry,
    null,
    false,
);
$check($projected['usage']['total_tokens'] === 62 && $projected['cost']['metered_amount'] === '3.300000');
$check($projected['usage']['attempt_count'] === 3 && $projected['cost']['by_currency'] === ['USD' => '3.300000']);

$mixed = $aggregator->summarize([
    $attempt(11, 101, 1, 'succeeded', $event('1.100000', 'USD', 20, 5)),
    $attempt(12, 102, 1, 'succeeded', $event('2.200000', 'CNY', 30, 7)),
]);
$check($mixed['pricing_state'] === 'mixed_currency' && $mixed['metered_amount'] === null);
$check($mixed['by_currency'] === ['CNY' => '2.200000', 'USD' => '1.100000']);
$projected = (new StructuredServiceRunPresenter())->present(
    ['id' => 9, 'state' => 'succeeded'],
    ['code' => 'material_analysis', 'version' => '1', 'name' => 'Material'],
    null,
    $mixed,
    null,
    false,
);
$check($projected['cost']['state'] === 'mixed_currency' && $projected['cost']['metered_amount'] === null);

$missing = $aggregator->summarize([
    $attempt(11, 101, 1, 'succeeded', $event('1.100000', 'USD', 20, 5)),
    $attempt(12, 101, 2, 'ambiguous', null),
]);
$check($missing['usage_state'] === 'unavailable' && $missing['pricing_state'] === 'unavailable');
$check($missing['missing_event_count'] === 1 && $missing['by_currency']['USD'] === '1.100000');
$failedMissing = $aggregator->summarize([
    $attempt(11, 101, 1, 'failed', null),
    $attempt(12, 101, 2, 'succeeded', $event('2.200000', 'USD', 30, 7)),
]);
$check($failedMissing['missing_event_count'] === 1 && $failedMissing['pricing_state'] === 'unavailable');

$unmeasured = [
    'usage_state' => 'unmeasured',
    'pricing_state' => 'unpriced',
    'tokens' => ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null],
    'metered_amount' => null,
    'currency' => null,
];
$unknown = $aggregator->summarize([$attempt(11, 101, 1, 'succeeded', $unmeasured)]);
$check($unknown['usage_state'] === 'unmeasured' && $unknown['tokens'] === null);
$check($unknown['pricing_state'] === 'unavailable' && $unknown['metered_amount'] === null);
$check($unknown['unpriced_count'] === 1 && $unknown['by_currency'] === []);

$unpriced = $unmeasured;
$unpriced['usage_state'] = 'measured';
$unpriced['tokens'] = ['input_tokens' => 12, 'output_tokens' => 3, 'total_tokens' => 15];
$partial = $aggregator->summarize([
    $attempt(11, 101, 1, 'failed', $unpriced),
    $attempt(12, 101, 2, 'succeeded', $event('2.200000', 'USD', 30, 7)),
]);
$check($partial['usage_state'] === 'measured' && $partial['tokens']['total_tokens'] === 52);
$check($partial['pricing_state'] === 'unavailable' && $partial['by_currency'] === ['USD' => '2.200000']);

try {
    $aggregator->summarize([
        $attempt(11, 101, 1, 'failed', $event('1.100000', 'USD', 20, 5)),
        $attempt(11, 101, 2, 'succeeded', $event('2.200000', 'USD', 30, 7)),
    ]);
    throw new RuntimeException('Duplicate invocation was accepted');
} catch (ApiProblem) {
    $check(true);
}
try {
    $aggregator->summarize([$attempt(11, 101, 1, 'not_dispatched', $event('1.100000', 'USD', 20, 5))]);
    throw new RuntimeException('Not-dispatched usage was accepted');
} catch (ApiProblem) {
    $check(true);
}
$review = $attempt(21, 0, 1, 'succeeded', $event('0.300000', 'USD', 4, 2));
$review['node'] = 'cross_file_review';
$withReview = $aggregator->summarize([
    $attempt(11, 101, 1, 'succeeded', $event('1.100000', 'USD', 20, 5)),
    $attempt(12, 102, 1, 'succeeded', $event('2.200000', 'USD', 30, 7)),
    $review,
]);
$check($withReview['attempt_count'] === 3 && $withReview['event_count'] === 3);
$check($withReview['metered_amount'] === '3.600000'
    && $withReview['tokens']['total_tokens'] === 68);
try {
    $invalidReview = $review;
    $invalidReview['file_id'] = 101;
    $aggregator->summarize([$invalidReview]);
    throw new RuntimeException('Cross-file review was charged as a file');
} catch (ApiProblem) {
    $check(true);
}

echo 'material usage aggregation: ' . $tests . '/' . $tests . " passed\n";
