<?php

declare(strict_types=1);

if (!class_exists(\app\Application\Usage\UsageSummaryReducer::class, false)) {
    require getenv('SAND_AI_REDUCER') ?: dirname(__DIR__) . '/app/Application/Usage/UsageSummaryReducer.php';
}

use app\Application\Usage\UsageSummaryReducer;

$base = [
    'dimension_value' => 'chat', 'currency' => 'CNY', 'total_events' => '2',
    'measured_events' => '2', 'priced_events' => '2', 'unmeasured_events' => '0',
    'unpriced_events' => '0', 'metered_amount' => '12.000001',
    'reconciled_events' => '0', 'discrepancy_events' => '0',
    'unreconciled_events' => '2', 'reconciled_amount' => null,
    'difference_amount' => null,
];
$cases = [
    [$base + ['failed_events' => '0', 'failed_unknown_amount_events' => '0', 'failed_metered_amount' => null], '0', '0'],
    [array_replace($base, ['failed_events' => '1', 'failed_unknown_amount_events' => '0', 'failed_metered_amount' => '0.000001']), '0.000001', '0.000001'],
    [array_replace($base, ['failed_events' => '2', 'failed_unknown_amount_events' => '1', 'failed_metered_amount' => '12.000001']), null, '12.000001'],
    [array_replace($base, ['failed_events' => '1', 'failed_unknown_amount_events' => '1', 'failed_metered_amount' => null]), null, null],
];
$checks = 0;
foreach ($cases as [$input, $expectedTotal, $expectedKnown]) {
    $row = (new UsageSummaryReducer())->rows([$input], 'capability')[0];
    if ($row['failed_metered_amount'] !== $expectedTotal
        || $row['failed_known_amount'] !== $expectedKnown
        || $row['failed_events'] !== (int) $input['failed_events']
        || $row['failed_unknown_amount_events'] !== (int) $input['failed_unknown_amount_events']) {
        throw new RuntimeException('Failed cost is misstated');
    }
    ++$checks;
}
echo "failed-cost {$checks}/{$checks}\n";
