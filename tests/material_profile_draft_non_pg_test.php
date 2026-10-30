<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) {
    throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
}
require $vendor;
$app = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($app): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisProfileDraftPolicy;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Material profile draft failed at check ' . ($checks + 1));
    }
    ++$checks;
};
$reject = static function (array $input, string $code) use ($assert): void {
    try {
        (new MaterialAnalysisProfileDraftPolicy())->normalize($input);
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === $code);
        return;
    }
    throw new RuntimeException('Invalid material profile was accepted');
};
$draft = [
    'environment_id' => 33,
    'expected_revision' => null,
    'profile' => [
        'code' => 'generic_material_analysis',
        'version' => '1.0.0',
        'name' => '材料整理',
        'output_language' => 'zh-CN',
        'document_classifications' => ['record', 'report'],
        'entity_types' => ['person', 'date'],
        'extension_fields' => [],
        'max_prompt_characters' => 48000,
        'max_output_characters' => 120000,
    ],
];
$policy = new MaterialAnalysisProfileDraftPolicy();
$normalized = $policy->normalize($draft);
$assert($normalized['environment_id'] === 33 && $normalized['expected_revision'] === null);
$assert($normalized['name'] === '材料整理'
    && $normalized['config']['code'] === 'generic_material_analysis'
    && !array_key_exists('name', $normalized['config']));
$assert($policy->normalize([...$draft, 'expected_revision' => 2])['expected_revision'] === 2);
$reject([...$draft, 'client_id' => 44], 'SAND_AI_VALIDATION_ERROR');
$reject([...$draft, 'expected_revision' => 0], 'SAND_AI_VALIDATION_ERROR');
$reject([...$draft, 'profile' => [...$draft['profile'], 'name' => "材料\n整理"]], 'SAND_AI_VALIDATION_ERROR');
$reject([...$draft, 'profile' => [...$draft['profile'], 'extension_fields' => ['CaseId']]], 'SAND_AI_SCHEMA_UNSUPPORTED');
$reject([...$draft, 'profile' => [...$draft['profile'], 'version' => 'latest']], 'SAND_AI_SCHEMA_UNSUPPORTED');
echo 'material-profile-draft ' . $checks . '/' . $checks . PHP_EOL;
