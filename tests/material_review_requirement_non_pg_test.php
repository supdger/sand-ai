<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
$class = \app\Application\Workflow\MaterialReviewRequirement::class;
if (!class_exists($class)) {
    require $root . '/app/Application/Workflow/MaterialReviewRequirement.php';
}

$policy = new \app\Application\Workflow\MaterialReviewRequirement();
$cited = ['value' => '合同', 'source_block_ids' => [11], 'evidence_state' => 'uncertain'];
$missing = ['value' => null, 'source_block_ids' => [], 'evidence_state' => 'missing_source'];
$payload = [
    'documents' => [['file_id' => 7, 'title' => $cited, 'classification' => $cited]],
    'conflicts' => [],
    'items_to_verify' => [],
];
if ($policy->required(false, $payload)) {
    throw new RuntimeException('A cited result was incorrectly marked for review');
}
if (!$policy->required(true, $payload)) {
    throw new RuntimeException('The configured review requirement was lost');
}
if (!$policy->required(false, [
    ...$payload,
    'documents' => [['file_id' => 7, 'title' => $missing, 'classification' => $cited]],
])) {
    throw new RuntimeException('A missing title was not marked for review');
}
if (!$policy->required(false, [
    ...$payload,
    'documents' => [['file_id' => 7, 'title' => $cited, 'classification' => $missing]],
])) {
    throw new RuntimeException('A missing classification was not marked for review');
}
if (!$policy->required(false, [
    ...$payload,
    'items_to_verify' => [['reason' => 'missing_source', 'summary' => null, 'source_block_ids' => []]],
])) {
    throw new RuntimeException('An unresolved item was not marked for review');
}
if (!$policy->required(false, [
    ...$payload,
    'conflicts' => [['field' => '日期', 'summary' => '来源矛盾', 'source_block_ids' => [11, 12]]],
])) {
    throw new RuntimeException('A source conflict was not marked for review');
}

echo 'material-review-requirement 6/6' . PHP_EOL;
