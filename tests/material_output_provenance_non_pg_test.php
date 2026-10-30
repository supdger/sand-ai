<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
require $root . '/app/Api/Support/ApiProblem.php';
require $root . '/app/Application/Workflow/MaterialAnalysisOutputValidator.php';

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisOutputValidator;

$profile = [
    'max_output_characters' => 10000,
    'document_classifications' => ['record'],
    'entity_types' => ['person'],
    'extension_fields' => [],
];
$files = [[
    'file_id' => 1, 'sha256' => str_repeat('a', 64), 'version_ref' => 'v1',
    'blocks' => [
        ['id' => 11, 'locator_type' => 'page', 'locator' => ['page' => 1], 'content' => 'A', 'content_sha256' => str_repeat('b', 64)],
        ['id' => 12, 'locator_type' => 'page', 'locator' => ['page' => 2], 'content' => 'B', 'content_sha256' => str_repeat('c', 64)],
    ],
]];
$output = [
    'documents' => [[
        'file_id' => 1,
        'classification' => ['value' => 'record', 'source_block_ids' => [11, 12], 'evidence_state' => 'confirmed'],
        'title' => ['value' => '材料', 'source_block_ids' => [11], 'evidence_state' => 'uncertain'],
    ]],
    'entities' => [], 'events' => [], 'conflicts' => [],
    'items_to_verify' => [['reason' => 'single_source', 'summary' => '需要复核', 'source_block_ids' => [11]]],
    'warnings' => [], 'extensions' => new stdClass(),
];
$validator = new MaterialAnalysisOutputValidator();
$valid = $validator->validate(json_encode($output, JSON_THROW_ON_ERROR), $profile, $files, [11, 12]);
if (($valid['payload']['items_to_verify'][0]['source_block_ids'] ?? null) !== [11]
    || !in_array(['field_path' => '/items_to_verify/0/summary', 'source_block_id' => 11], $valid['field_sources'], true)) {
    throw new RuntimeException('Single-source verification item lost its source link');
}

$output['items_to_verify'][0]['source_block_ids'] = [11, 12];
try {
    $validator->validate(json_encode($output, JSON_THROW_ON_ERROR), $profile, $files, [11, 12]);
} catch (ApiProblem $problem) {
    if ($problem->errorCode === 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
        $output['items_to_verify'][0]['source_block_ids'] = [11];
        $otherFile = [
            'file_id' => 2, 'sha256' => str_repeat('d', 64), 'version_ref' => 'v1',
            'blocks' => [
                ['id' => 21, 'locator_type' => 'page', 'locator' => ['page' => 1], 'content' => 'C', 'content_sha256' => str_repeat('e', 64)],
            ],
        ];
        $output['documents'][0]['title']['source_block_ids'] = [21];
        try {
            $validator->validate(json_encode($output, JSON_THROW_ON_ERROR), $profile, [...$files, $otherFile], [11, 12, 21]);
        } catch (ApiProblem $crossFileProblem) {
            if ($crossFileProblem->errorCode === 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
                echo "material-output-provenance 3/3\n";
                exit(0);
            }
            throw $crossFileProblem;
        }
        throw new RuntimeException('A document title accepted a source block from another file');
    }
    throw $problem;
}
throw new RuntimeException('A single-source warning accepted two independent source blocks');
