<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'app/Api/Support/ApiProblem.php' => \app\Api\Support\ApiProblem::class,
    'app/Application/Workflow/MaterialAnalysisPrompt.php' => \app\Application\Workflow\MaterialAnalysisPrompt::class,
    'app/Application/Workflow/MaterialAnalysisPromptFactory.php' => \app\Application\Workflow\MaterialAnalysisPromptFactory::class,
] as $file => $class) {
    if (!class_exists($class)) {
        require $root . '/' . $file;
    }
}

$profile = [
    'output_language' => 'zh-CN',
    'document_classifications' => ['合同'],
    'entity_types' => ['person'],
    'extension_fields' => ['contract_no'],
    'max_prompt_characters' => 10000,
];
$files = [[
    'file_id' => 7,
    'blocks' => [[
        'id' => 11, 'locator_type' => 'table_row', 'locator' => ['table' => 1, 'row' => 2],
        'content' => '{"A2":"合同编号","B2":"甲-001"}',
    ]],
]];
$factory = new \app\Application\Workflow\MaterialAnalysisPromptFactory();
$prompt = $factory->build($profile, $files);
$system = $prompt->messages[0]['content'];
$source = $prompt->messages[1]['content'];
if (!str_contains($system, 'Allowed extension field codes: contract_no')
    || !str_contains($system, 'documents:[{file_id,classification:')
    || !str_contains($system, 'events:[{summary,occurred_on,source_block_ids,evidence_state}]')
    || !str_contains($system, 'missing_source requires summary:null and no sources')) {
    throw new RuntimeException('The material prompt omitted its configured output shape');
}
if ($prompt->sourceBlockIds !== [11] || !str_contains($source, 'locator_type=table_row')
    || !str_contains($source, '"table":1,"row":2')
    || !str_contains($source, '"B2":"甲-001"')) {
    throw new RuntimeException('The table source was not supplied with its location');
}
try {
    $factory->build([...$profile, 'max_prompt_characters' => 10], $files);
    throw new RuntimeException('The fixed prompt silently exceeded its configured limit');
} catch (\app\Api\Support\ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE') {
        throw $problem;
    }
}

echo 'material-output-prompt 3/3' . PHP_EOL;
