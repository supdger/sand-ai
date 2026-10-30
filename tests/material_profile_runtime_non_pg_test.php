<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) {
    throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
}
require $vendor;
$package = dirname(__DIR__) . '/app/';
$authority = getenv('SANDPACKAGE_TEST_ROOT');
if (!is_string($authority) || !is_dir($authority . '/app')) {
    $authority = dirname(__DIR__);
}
spl_autoload_register(static function (string $class) use ($package, $authority): void {
    if (!str_starts_with($class, 'app\\')) return;
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach ([$package, $authority . '/app/'] as $root) {
        if (is_file($root . $relative)) {
            require $root . $relative;
            return;
        }
    }
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisProfileRegistry;
use app\Application\Workflow\StructuredWorkflowPolicy;
use app\Application\Workflow\WorkflowDefinitionRegistry;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Material runtime profile check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$reject = static function (callable $call, string $code) use ($assert): void {
    try {
        $call();
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === $code);
        return;
    }
    throw new RuntimeException('Material runtime profile unexpectedly accepted');
};
$config = [
    'code' => 'generic_material_analysis',
    'version' => '1.0.0',
    'output_language' => 'zh-CN',
    'document_classifications' => ['record'],
    'entity_types' => ['person'],
    'extension_fields' => [],
    'max_prompt_characters' => 48000,
    'max_output_characters' => 120000,
];
$row = [
    'environment_id' => 31,
    'code' => $config['code'],
    'version' => $config['version'],
    'state' => 'published',
    'status' => 1,
    'config' => $config,
];
$lookup = static fn (int $environmentId, string $code, string $version): ?array =>
    $environmentId === 31 && $code === $config['code'] && $version === $config['version'] ? $row : null;
$registry = new MaterialAnalysisProfileRegistry($lookup);
$assert($registry->profile(31, $config['code'], $config['version']) === $config);
$reject(static fn () => $registry->profile(32, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$reject(static fn () => $registry->profile(31, $config['code'], '2.0.0'), 'SAND_AI_SCHEMA_UNSUPPORTED');
$reject(static fn () => $registry->profile(0, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$reject(static fn () => $registry->profile(31, 'Bad Code', $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
foreach (['draft', 'tested', 'revoked'] as $state) {
    $candidate = new MaterialAnalysisProfileRegistry(static fn (): array => [...$row, 'state' => $state]);
    $reject(static fn () => $candidate->profile(31, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
}
$candidate = new MaterialAnalysisProfileRegistry(static fn (): array => [...$row, 'status' => 2]);
$reject(static fn () => $candidate->profile(31, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$candidate = new MaterialAnalysisProfileRegistry(static fn (): array => [...$row, 'environment_id' => 32]);
$reject(static fn () => $candidate->profile(31, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$candidate = new MaterialAnalysisProfileRegistry(static fn (): array => [...$row, 'config' => [...$config, 'version' => '2.0.0']]);
$reject(static fn () => $candidate->profile(31, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$candidate = new MaterialAnalysisProfileRegistry(static fn (): ?array => null);
$reject(static fn () => $candidate->profile(31, $config['code'], $config['version']), 'SAND_AI_SCHEMA_UNSUPPORTED');
$forUpdate = [];
$locked = new MaterialAnalysisProfileRegistry(static function (int $environmentId, string $code, string $version, bool $lock) use (&$forUpdate, $row): array {
    $forUpdate[] = $lock;
    return $row;
});
$assert($locked->profile(31, $config['code'], $config['version']) === $config);
$locked->assertSnapshotCurrent(31, $config['code'], $config['version'], $config);
$assert($forUpdate === [false, true]);
$reject(static fn () => $locked->assertSnapshotCurrent(31, $config['code'], $config['version'], [...$config, 'entity_types' => ['organization']]), 'SAND_AI_SCHEMA_UNSUPPORTED');
$revoked = new MaterialAnalysisProfileRegistry(static fn (int $environmentId, string $code, string $version, bool $lock): array =>
    $lock ? [...$row, 'state' => 'revoked'] : $row);
$assert($revoked->profile(31, $config['code'], $config['version']) === $config);
$reject(static fn () => $revoked->assertSnapshotCurrent(31, $config['code'], $config['version'], $config), 'SAND_AI_SCHEMA_UNSUPPORTED');

$definition = [
    'version' => '1.0.0',
    'data_class' => null,
    'analysis_profile_required' => true,
    'output_schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
    'steps' => ['analyze_materials'],
];
$input = [
    'business_scope' => ['ref' => 'case-1', 'snapshot_ref' => 'snapshot-1', 'snapshot_version' => '1'],
    'workflow' => ['code' => 'material_analysis', 'version' => '1.0.0'],
    'files' => [['file_id' => 7, 'sha256' => str_repeat('a', 64), 'version_ref' => '1']],
    'output_schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
    'analysis_profile' => ['code' => $config['code'], 'version' => $config['version']],
];
$normalized = StructuredWorkflowPolicy::normalizeRequest($input, new WorkflowDefinitionRegistry(['material_analysis' => $definition]), 31, $registry);
$assert($normalized['analysis_profile']['snapshot'] === $config);
$reject(static fn () => StructuredWorkflowPolicy::normalizeRequest($input, new WorkflowDefinitionRegistry(['material_analysis' => $definition]), 32, $registry), 'SAND_AI_SCHEMA_UNSUPPORTED');
echo 'material-profile-runtime ' . $checks . '/' . $checks . PHP_EOL;
