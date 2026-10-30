<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
require $vendor;
$app = getenv('SANDPACKAGE_TEST_ROOT') . '/app/';
spl_autoload_register(static function (string $class) use ($app): void {
    if (!str_starts_with($class, 'app\\')) return;
    $name = substr($class, strrpos($class, '\\') + 1);
    $candidate = __DIR__ . '/' . $name . '.php';
    if (is_file($candidate)) {
        require $candidate;
        return;
    }
    $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
}, true, true);

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Material sample submission check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$reject = static function (callable $operation, string $code) use ($assert): void {
    try {
        $operation();
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === $code);
        return;
    }
    throw new RuntimeException('Invalid material sample request was accepted');
};
$config = [
    'code' => 'generic_material_analysis', 'version' => '1.0.0',
    'output_language' => 'zh-CN', 'document_classifications' => ['record'],
    'entity_types' => ['person'], 'extension_fields' => [],
    'max_prompt_characters' => 20000, 'max_output_characters' => 20000,
];
$draft = ['id' => 72, 'environment_id' => 31, 'code' => $config['code'],
    'version' => $config['version'], 'revision' => 4, 'config' => $config];
$definitions = new \app\Application\Workflow\WorkflowDefinitionRegistry([
    'material_analysis' => [
        'version' => '1.0.0', 'data_class' => null, 'analysis_profile_required' => true,
        'output_schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
        'steps' => ['analyze_materials'],
    ],
]);
$input = [
    'business_scope' => ['ref' => 'case-1', 'snapshot_ref' => 'snapshot-1', 'snapshot_version' => '1'],
    'workflow' => ['code' => 'material_analysis', 'version' => '1.0.0'],
    'files' => [['file_id' => 7, 'sha256' => str_repeat('a', 64), 'version_ref' => '1']],
    'output_schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
    'analysis_profile' => ['code' => $config['code'], 'version' => $config['version']],
];
$normalized = \app\Application\Workflow\StructuredWorkflowPolicy::normalizeRequest($input, $definitions, 31, null, $draft);
$assert($normalized['analysis_profile']['snapshot'] === $config);
$reject(static fn () => \app\Application\Workflow\StructuredWorkflowPolicy::normalizeRequest(
    $input, $definitions, 32, null, $draft,
), 'SAND_AI_RESOURCE_CONFLICT');
$reject(static fn () => \app\Application\Workflow\StructuredWorkflowPolicy::normalizeRequest(
    [...$input, 'material_profile_sample' => ['profile_id' => 72]], $definitions, 31, null, $draft,
), 'SAND_AI_VALIDATION_ERROR');
$reject(static fn () => \app\Application\Workflow\StructuredWorkflowPolicy::normalizeRequest(
    [...$input, 'analysis_profile' => ['code' => 'other', 'version' => '1.0.0']], $definitions, 31, null, $draft,
), 'SAND_AI_RESOURCE_CONFLICT');
echo 'material-sample-submit ' . $checks . '/' . $checks . PHP_EOL;
