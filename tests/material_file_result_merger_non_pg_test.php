<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach (['Api/Support/ApiProblem.php', 'Application/Workflow/MaterialAnalysisFileResultMerger.php'] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class)) {
        require $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisFileResultMerger;

$file = static function (int $runFileId, int $fileId, int $sourceId, string $title): array {
    return [
        'workflow_run_file_id' => $runFileId,
        'file_id' => $fileId,
        'source_block_ids' => [$sourceId],
        'payload' => [
            'schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
            'documents' => [[
                'file_id' => $fileId,
                'classification' => ['value' => 'record', 'source_block_ids' => [$sourceId], 'evidence_state' => 'uncertain'],
                'title' => ['value' => $title, 'source_block_ids' => [$sourceId], 'evidence_state' => 'uncertain'],
            ]],
            'entities' => [['value' => $title, 'source_block_ids' => [$sourceId]]],
            'events' => [], 'conflicts' => [], 'items_to_verify' => [],
            'warnings' => ['review'],
            'extensions' => (object) ['a/b' => [['value' => $title, 'source_block_ids' => [$sourceId]]]],
        ],
        'field_sources' => [
            ['field_path' => '/documents/0/title/value', 'source_block_id' => $sourceId],
            ['field_path' => '/entities/0/value', 'source_block_id' => $sourceId],
            ['field_path' => '/extensions/a~1b/0/value', 'source_block_id' => $sourceId],
        ],
    ];
};
$first = $file(11, 101, 1001, 'first');
$second = $file(12, 102, 1002, 'second');
$merger = new MaterialAnalysisFileResultMerger();
$merged = $merger->merge([$second, $first]);
$expectedPaths = [
    '/documents/0/title/value', '/entities/0/value', '/extensions/a~1b/0/value',
    '/documents/1/title/value', '/entities/1/value', '/extensions/a~1b/1/value',
];
if (array_column($merged['field_sources'], 'field_path') !== $expectedPaths
    || array_column($merged['payload']['documents'], 'file_id') !== [101, 102]
    || $merged['payload']['warnings'] !== ['review']
    || count($merged['payload']['extensions']->{'a/b'}) !== 2) {
    throw new RuntimeException('File results were not merged in stable source order');
}
$checks = 1;
$reject = static function (array $results) use ($merger, &$checks): void {
    try {
        $merger->merge($results);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
            throw $problem;
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('Invalid file result was accepted');
};
$reject([]);
$reject([[...$first, 'workflow_run_file_id' => '11']]);
$reject([$first, $first]);
$reject([$first, [...$second, 'file_id' => 101]]);
$reject([[...$first, 'payload' => [...$first['payload'], 'documents' => []]]]);
$reject([[...$first, 'field_sources' => [['field_path' => '/documents/0/title/value', 'source_block_id' => 999]]]]);
$reject([[...$first, 'field_sources' => [['field_path' => '/documents/9/title/value', 'source_block_id' => 1001]]]]);
$reject([[...$first, 'field_sources' => [['field_path' => '/extensions/a~2b/0/value', 'source_block_id' => 1001]]]]);
echo 'material-file-result-merger ' . $checks . '/' . $checks . PHP_EOL;
