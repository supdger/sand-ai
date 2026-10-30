<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'app/Api/Support/ApiProblem.php' => \app\Api\Support\ApiProblem::class,
    'app/Application/Workflow/MaterialAnalysisPrompt.php' => \app\Application\Workflow\MaterialAnalysisPrompt::class,
    'app/Application/Workflow/MaterialAnalysisPromptFactory.php' => \app\Application\Workflow\MaterialAnalysisPromptFactory::class,
    'app/Application/Workflow/MaterialAnalysisOutputValidator.php' => \app\Application\Workflow\MaterialAnalysisOutputValidator::class,
    'app/Application/Workflow/MaterialAnalysisFileResultMerger.php' => \app\Application\Workflow\MaterialAnalysisFileResultMerger::class,
    'app/Application/Workflow/MaterialCrossFileReviewPromptFactory.php' => \app\Application\Workflow\MaterialCrossFileReviewPromptFactory::class,
    'app/Application/Workflow/MaterialCrossFileReviewOutputValidator.php' => \app\Application\Workflow\MaterialCrossFileReviewOutputValidator::class,
] as $file => $class) {
    if (!class_exists($class)) {
        require $root . '/' . $file;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisFileResultMerger;
use app\Application\Workflow\MaterialAnalysisOutputValidator;
use app\Application\Workflow\MaterialAnalysisPromptFactory;
use app\Application\Workflow\MaterialCrossFileReviewOutputValidator;
use app\Application\Workflow\MaterialCrossFileReviewPromptFactory;

$profile = [
    'output_language' => 'zh-CN',
    'max_prompt_characters' => 10000,
    'max_output_characters' => 10000,
    'document_classifications' => ['contract'],
    'entity_types' => ['person'],
    'extension_fields' => [],
];
$files = [
    [
        'file_id' => 7, 'sha256' => str_repeat('a', 64), 'version_ref' => 'v1',
        'blocks' => [[
            'id' => 11, 'locator_type' => 'page', 'locator' => ['page' => 1],
            'content' => '仅有零散文字，没有标题和分类。',
            'content_sha256' => hash('sha256', '仅有零散文字，没有标题和分类。'),
        ]],
    ],
    [
        'file_id' => 8, 'sha256' => str_repeat('b', 64), 'version_ref' => 'v1',
        'blocks' => [[
            'id' => 21, 'locator_type' => 'page', 'locator' => ['page' => 1],
            'content' => '另一份资料同样没有可核实的字段。',
            'content_sha256' => hash('sha256', '另一份资料同样没有可核实的字段。'),
        ]],
    ],
];
$missing = ['value' => null, 'source_block_ids' => [], 'evidence_state' => 'missing_source'];
$output = [
    'documents' => [['file_id' => 7, 'classification' => $missing, 'title' => $missing]],
    'entities' => [], 'events' => [], 'conflicts' => [], 'items_to_verify' => [],
    'warnings' => [], 'extensions' => new stdClass(),
];
$validator = new MaterialAnalysisOutputValidator();
$manifest = $validator->validate(
    json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    $profile,
    [$files[0]],
    [11],
);
if ($manifest['field_sources'] !== []
    || $manifest['payload']['documents'][0]['title'] !== $missing) {
    throw new RuntimeException('Missing document fields produced invented sources');
}
$prompt = (new MaterialAnalysisPromptFactory())->build($profile, [$files[0]]);
if (!str_contains($prompt->messages[0]['content'], 'source_block_ids:[],evidence_state:"missing_source"')
    || !str_contains($prompt->messages[1]['content'], "]\n仅有零散文字")) {
    throw new RuntimeException('The model prompt does not explain missing fields or delimit source content');
}
$reject = static function (array $candidate) use ($validator, $output, $files, $profile): void {
    $invalid = $output;
    $invalid['documents'][0]['title'] = $candidate;
    try {
        $validator->validate(json_encode($invalid, JSON_THROW_ON_ERROR), $profile, [$files[0]], [11]);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
            throw $problem;
        }
        return;
    }
    throw new RuntimeException('An unsupported document claim was accepted');
};
$reject(['value' => null, 'source_block_ids' => [11], 'evidence_state' => 'missing_source']);
$reject(['value' => null, 'source_block_ids' => [], 'evidence_state' => 'uncertain']);

$result1 = [
    'workflow_run_file_id' => 31, 'file_id' => 7,
    'payload' => $manifest['payload'], 'field_sources' => [],
    'source_block_ids' => [11],
];
$output['documents'][0]['file_id'] = 8;
$manifest2 = $validator->validate(
    json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    $profile,
    [$files[1]],
    [21],
);
$result2 = [
    'workflow_run_file_id' => 32, 'file_id' => 8,
    'payload' => $manifest2['payload'], 'field_sources' => [],
    'source_block_ids' => [21],
];
$merged = (new MaterialAnalysisFileResultMerger())->merge([$result2, $result1]);
if (array_column($merged['payload']['documents'], 'file_id') !== [7, 8]
    || $merged['field_sources'] !== []) {
    throw new RuntimeException('Source-free results did not merge deterministically');
}
$reviewFiles = [];
foreach ([[$result1, $files[0]], [$result2, $files[1]]] as [$result, $file]) {
    $reviewFiles[] = [
        'workflow_run_file_id' => $result['workflow_run_file_id'],
        'file_id' => $result['file_id'],
        'parse_id' => $result['file_id'] + 100,
        'payload' => $result['payload'],
        'field_sources' => [],
        'blocks' => [[
            'id' => $file['blocks'][0]['id'],
            'file_id' => $result['file_id'],
            'parse_id' => $result['file_id'] + 100,
            'content' => $file['blocks'][0]['content'],
            'content_sha256' => $file['blocks'][0]['content_sha256'],
        ]],
    ];
}
$reviewPrompt = (new MaterialCrossFileReviewPromptFactory())->build($profile, $reviewFiles);
if ($reviewPrompt['source_file_ids'] !== [] || $reviewPrompt['candidates'] !== []) {
    throw new RuntimeException('A review advertised uncited material as evidence');
}
$reviewValidator = new MaterialCrossFileReviewOutputValidator();
$emptyReview = $reviewValidator->validate('{"items_to_verify":[]}', [], [], 4096);
if ($emptyReview['field_sources'] !== [] || $emptyReview['withheld_candidates'] !== []) {
    throw new RuntimeException('An empty review invented a citation or withheld claim');
}
try {
    $reviewValidator->validate('{"items_to_verify":[{"reason":"conflict","field":"title","summary":"冲突","source_block_ids":[11,21],"affected_candidates":[]}]}', [], [], 4096);
    throw new RuntimeException('A cross-file conflict without cited candidates was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
        throw $problem;
    }
}
echo "material-missing-source 8/8\n";
