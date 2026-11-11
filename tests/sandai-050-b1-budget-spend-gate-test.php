<?php

declare(strict_types=1);

/**
 * Isolated 0.5.0 budget spend-gate tests: no database, no SandIAM plugin, no
 * network. Covers the extracted window-exhaustion decision (soft never
 * blocks, hard blocks only at the committed limit), the blocked-call copy,
 * and the structural guarantee that guards no longer consult attestation or
 * capacity estimates.
 *
 * Usage: php tests/sandai-050-b1-budget-spend-gate-test.php
 */

require_once dirname(__DIR__) . '/app/Application/Budget/BudgetDecimal.php';
require_once dirname(__DIR__) . '/app/Application/Budget/BudgetSpendGate.php';

use app\Application\Budget\BudgetSpendGate;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

$openWindow = ['currency' => 'USD', 'limit_amount' => '100.000000', 'reserved_amount' => '0.000000', 'settled_amount' => '0.000000', 'held_amount' => '0.000000'];
$spentWindow = ['currency' => 'USD', 'limit_amount' => '100.000000', 'reserved_amount' => '10.000000', 'settled_amount' => '80.000000', 'held_amount' => '5.000000'];
$exhaustedWindow = ['currency' => 'USD', 'limit_amount' => '100.000000', 'reserved_amount' => '10.000000', 'settled_amount' => '85.000000', 'held_amount' => '5.000000'];
$unlimitedWindow = ['currency' => 'USD', 'limit_amount' => null, 'reserved_amount' => '999999.000000', 'settled_amount' => '0.000000', 'held_amount' => '0.000000'];

// 只提醒（soft）：settled 未达限→放行；settled 已达限→仍放行
ok(BudgetSpendGate::allowsWindowCall('soft', $openWindow), 'gate: soft under limit admits');
ok(BudgetSpendGate::allowsWindowCall('soft', $spentWindow), 'gate: soft with committed spend admits');
ok(BudgetSpendGate::allowsWindowCall('soft', $exhaustedWindow), 'gate: soft at limit still admits');

// 暂停（hard）未到上限：放行；达到/超过上限：拦截
ok(BudgetSpendGate::allowsWindowCall('hard', $openWindow), 'gate: hard empty window admits');
ok(BudgetSpendGate::allowsWindowCall('hard', $spentWindow), 'gate: hard under limit admits');
ok(!BudgetSpendGate::allowsWindowCall('hard', $exhaustedWindow), 'gate: hard at limit blocks');
$overLimitWindow = ['currency' => 'USD', 'limit_amount' => '100.000000', 'reserved_amount' => '0.000000', 'settled_amount' => '110.000000', 'held_amount' => '0.000000'];
ok(!BudgetSpendGate::allowsWindowCall('hard', $overLimitWindow), 'gate: hard over limit blocks');
ok(BudgetSpendGate::allowsWindowCall('hard', $unlimitedWindow), 'gate: hard unlimited window admits');

// 拦截文案是产品要求的中文原话
ok(BudgetSpendGate::EXHAUSTED_MESSAGE === '本月预算已用完，可以去费用页调高预算',
    'gate: exhausted copy is the product message');
$stateMachine = file_get_contents(dirname(__DIR__) . '/app/Application/Budget/BudgetReservationStateMachine.php');
ok(is_string($stateMachine) && str_contains($stateMachine, "'" . BudgetSpendGate::EXHAUSTED_MESSAGE . "'"),
    'state machine: exceeded throw uses the product message');

// 无容量登记（attestation）不进入判定入参：签名只收 enforcement/窗口金额
$decision = new ReflectionMethod(BudgetSpendGate::class, 'allowsCall');
$parameterNames = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $decision->getParameters());
ok($parameterNames === ['enforcement', 'limitAmount', 'committedAmount'],
    'gate: decision inputs carry no attestation or estimate');

// ChatBudgetGuard 不再引用容量证明/上界类型，各 guard 统一走 BudgetSpendGate
$guardSource = file_get_contents(dirname(__DIR__) . '/app/Application/Budget/ChatBudgetGuard.php');
ok(is_string($guardSource) && !str_contains($guardSource, 'BudgetCapacityBound')
    && !str_contains($guardSource, 'BudgetInputTokenProof')
    && !str_contains($guardSource, 'TokenBudgetEstimator')
    && !str_contains($guardSource, 'BudgetTokenProfile')
    && !str_contains($guardSource, 'budget_capacity_attestation'),
    'chat guard: no capacity bound or attestation in prepare path');
ok(is_string($guardSource) && str_contains($guardSource, 'BudgetSpendGate::allowsWindowCall'),
    'chat guard: blocking decision goes through the spend gate');

foreach ([
    'speech' => '/app/Application/Audio/Speech/SpeechBudgetGuard.php',
    'ocr' => '/app/Application/File/OcrBudgetGuard.php',
    'audio' => '/app/Application/Audio/AudioBudgetGuard.php',
    'rerank' => '/app/Application/Knowledge/KnowledgeProfessionalRerankBudget.php',
    'low cost' => '/app/Application/Gateway/LowCostChatRouteResolver.php',
] as $label => $path) {
    $source = file_get_contents(dirname(__DIR__) . $path);
    ok(is_string($source) && str_contains($source, 'BudgetSpendGate::allowsWindowCall'),
        "guard {$label}: blocking decision goes through the spend gate");
}

echo "sandai-050-b1-budget-spend-gate passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
