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
    if (!$condition) throw new RuntimeException('Material sample draft check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$reject = static function (callable $operation) use ($assert): void {
    try {
        $operation();
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_RESOURCE_CONFLICT');
        return;
    }
    throw new RuntimeException('Changed sample draft was accepted');
};
$config = [
    'code' => 'generic_material_analysis', 'version' => '1.0.0',
    'output_language' => 'zh-CN', 'document_classifications' => ['record'],
    'entity_types' => ['person'], 'extension_fields' => [],
    'max_prompt_characters' => 20000, 'max_output_characters' => 20000,
];
$row = [
    'id' => 72, 'environment_id' => 31, 'code' => $config['code'],
    'version' => $config['version'], 'revision' => 4, 'state' => 'draft',
    'status' => 2, 'config' => $config,
];
$locks = [];
$registry = new \app\Application\Workflow\MaterialAnalysisSampleProfileRegistry(
    static function (int $environmentId, string $code, string $version, bool $lock) use (&$locks, &$row): array {
        $locks[] = $lock;
        return $row;
    },
);
$snapshot = $registry->draft(31, $config['code'], $config['version'], 4);
$assert($snapshot['id'] === 72 && $snapshot['revision'] === 4 && $snapshot['config'] === $config);
$registry->assertSnapshotCurrent(31, $snapshot);
$assert($locks === [false, true]);
$row['revision'] = 5;
$reject(static fn () => $registry->assertSnapshotCurrent(31, $snapshot));
$row['revision'] = 4;
$row['config']['entity_types'] = ['organization'];
$reject(static fn () => $registry->assertSnapshotCurrent(31, $snapshot));
$row['config'] = $config;
$row['state'] = 'published';
$reject(static fn () => $registry->assertSnapshotCurrent(31, $snapshot));
$row['state'] = 'draft';
$reject(static fn () => $registry->draft(32, $config['code'], $config['version'], 4));
$reject(static fn () => $registry->draft(31, $config['code'], $config['version'], 3));
echo 'material-sample-profile ' . $checks . '/' . $checks . PHP_EOL;
