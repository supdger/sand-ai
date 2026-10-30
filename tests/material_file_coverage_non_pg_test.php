<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
if (!class_exists(\app\Api\Support\ApiProblem::class)) {
    require $root . '/app/Api/Support/ApiProblem.php';
}
if (!class_exists(\app\Application\Workflow\MaterialAnalysisOutputValidator::class)) {
    require $root . '/app/Application/Workflow/MaterialAnalysisOutputValidator.php';
}

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisOutputValidator;

$profile = [
    'max_output_characters' => 10000,
    'document_classifications' => ['record'],
    'entity_types' => [],
    'extension_fields' => [],
];
$files = [];
foreach ([1 => [11, 12], 2 => [21, 22]] as $fileId => $blockIds) {
    $files[] = [
        'file_id' => $fileId,
        'sha256' => str_repeat('a', 64),
        'version_ref' => 'v1',
        'blocks' => array_map(static fn (int $id): array => [
            'id' => $id,
            'locator_type' => 'page',
            'locator' => ['page' => 1],
            'content' => '受控来源',
            'content_sha256' => str_repeat('b', 64),
        ], $blockIds),
    ];
}
$document = static fn (int $fileId, int $first, int $second): array => [
    'file_id' => $fileId,
    'classification' => ['value' => 'record', 'source_block_ids' => [$first, $second], 'evidence_state' => 'confirmed'],
    'title' => ['value' => '材料', 'source_block_ids' => [$first], 'evidence_state' => 'uncertain'],
];
$output = [
    'documents' => [$document(1, 11, 12)],
    'entities' => [],
    'events' => [],
    'conflicts' => [],
    'items_to_verify' => [],
    'warnings' => [],
    'extensions' => new stdClass(),
];
$validator = new MaterialAnalysisOutputValidator();
$validate = static fn (array $value): array => $validator->validate(
    json_encode($value, JSON_THROW_ON_ERROR), $profile, $files, [11, 12, 21, 22],
);
$checks = 0;
$reject = static function (array $value) use ($validate, &$checks): void {
    try {
        $validate($value);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
            throw $problem;
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('An incomplete material result was accepted');
};

$reject($output);
$reject([...$output, 'documents' => []]);
$complete = [...$output, 'documents' => [...$output['documents'], $document(2, 21, 22)]];
$result = $validate($complete);
if (count($result['payload']['documents']) !== 2) {
    throw new RuntimeException('Complete material result lost a document');
}
++ $checks;
$result = $validate([...$complete, 'documents' => [...$complete['documents'], $document(1, 11, 12)]]);
if (count($result['payload']['documents']) !== 3) {
    throw new RuntimeException('Multiple documents from one file were rejected');
}
++ $checks;
$reject([...$complete, 'documents' => [$document(1, 11, 12), $document(3, 21, 22)]]);

echo 'material-file-coverage ' . $checks . '/' . $checks . PHP_EOL;
