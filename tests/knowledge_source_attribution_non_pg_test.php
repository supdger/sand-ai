<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) {
    throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR to a Composer autoload file');
}
require $vendor;

$app = dirname(__DIR__) . '/app';
spl_autoload_register(static function (string $class) use ($app): void {
    if (!str_starts_with($class, 'app\\')) return;
    $relative = '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($app . $relative)) require $app . $relative;
}, true, true);

use app\Application\File\FileOwnershipGuard;
use app\Application\Knowledge\KnowledgeSourceAttribution;
use app\Application\Usage\UsageAttribution;
use app\Api\Support\ApiProblem;

function fileRow(int $applicationId, int $clientId, ?string $dataClass): array
{
    $attribution = new UsageAttribution(
        7, 11, $applicationId, $clientId,
        ['organization_ref' => '11', 'application_ref' => (string) $applicationId, 'workload_client_ref' => (string) $clientId],
        'file', 'sand_ai.file.write',
    );
    $snapshot = $attribution->toPersisted();
    $snapshot['data_class'] = $dataClass;
    return [
        'id' => 99,
        'status' => 1,
        'delete_time' => null,
        'state' => 'parsed',
        'environment_id' => 7,
        'owner_scope' => FileOwnershipGuard::scope($attribution),
        'owner_attribution' => $snapshot,
    ];
}

function rejected(callable $case): void
{
    try {
        $case();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_KNOWLEDGE_SOURCE_INVALID') throw $problem;
        return;
    }
    throw new RuntimeException('Expected knowledge source rejection');
}

$first = KnowledgeSourceAttribution::fromFile(fileRow(12, 13, 'internal'), 7);
$second = KnowledgeSourceAttribution::fromFile(fileRow(12, 14, 'internal'), 7);
KnowledgeSourceAttribution::assertCompatible($first, $second);
rejected(static fn () => KnowledgeSourceAttribution::assertCompatible($first, KnowledgeSourceAttribution::fromFile(fileRow(15, 13, 'internal'), 7)));
rejected(static fn () => KnowledgeSourceAttribution::assertCompatible($first, KnowledgeSourceAttribution::fromFile(fileRow(12, 13, 'restricted'), 7)));
rejected(static fn () => KnowledgeSourceAttribution::fromFile(fileRow(12, 13, 'internal'), 8));
$legacy = fileRow(12, 13, 'internal');
unset($legacy['owner_scope']);
rejected(static fn () => KnowledgeSourceAttribution::fromFile($legacy, 7));
$forged = fileRow(12, 13, 'internal');
$forged['owner_attribution']['application_id'] = 15;
rejected(static fn () => KnowledgeSourceAttribution::fromFile($forged, 7));
echo "knowledge-source-attribution 6/6\n";
