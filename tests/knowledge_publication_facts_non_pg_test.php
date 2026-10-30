<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
require $vendor;
$app = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($app): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\Knowledge\KnowledgeVersionSourceScope;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;
use app\Identity\Infrastructure\KnowledgePublicationFacts;

function source(int $id, int $applicationId, int $clientId, ?string $dataClass): array
{
    $attribution = new UsageAttribution(
        7, 11, $applicationId, $clientId,
        ['organization_ref' => '11', 'application_ref' => (string) $applicationId, 'workload_client_ref' => (string) $clientId],
        'file', 'sand_ai.file.write',
    );
    $snapshot = $attribution->toPersisted();
    $snapshot['data_class'] = $dataClass;
    return [
        'id' => $id,
        'status' => 1,
        'delete_time' => null,
        'state' => 'parsed',
        'environment_id' => 7,
        'owner_scope' => FileOwnershipGuard::scope($attribution),
        'owner_attribution' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ];
}

function expectDenial(callable $case): void
{
    try {
        $case();
    } catch (ApiProblem|IdentityContextException) {
        return;
    }
    throw new RuntimeException('Expected denial');
}

$owner = ['organization_id' => 11, 'application_id' => 12, 'environment_id' => 7];
$scope = KnowledgeVersionSourceScope::fromFiles([source(50, 12, 13, 'internal'), source(51, 12, 14, 'internal')], $owner);
if ($scope['data_class'] !== 'internal' || strlen($scope['source_scope_sha256']) !== 64) throw new RuntimeException('Valid source scope failed');
$reordered = KnowledgeVersionSourceScope::fromFiles([source(51, 12, 14, 'internal'), source(50, 12, 13, 'internal')], $owner);
if ($reordered !== $scope) throw new RuntimeException('Source order changed fingerprint');
expectDenial(static fn () => KnowledgeVersionSourceScope::fromFiles([source(50, 12, 13, 'internal'), source(51, 15, 14, 'internal')], $owner));
expectDenial(static fn () => KnowledgeVersionSourceScope::fromFiles([source(50, 12, 13, 'internal'), source(51, 12, 14, 'restricted')], $owner));
expectDenial(static fn () => KnowledgeVersionSourceScope::fromFiles([source(50, 12, 13, 'internal')], ['organization_id' => 11, 'application_id' => 15, 'environment_id' => 7]));
$legacy = source(50, 12, 13, 'internal');
$legacy['owner_scope'] = null;
expectDenial(static fn () => KnowledgeVersionSourceScope::fromFiles([$legacy], $owner));
expectDenial(static fn () => KnowledgeVersionSourceScope::fromFiles([], $owner));
$unclassified = KnowledgeVersionSourceScope::fromFiles([source(52, 12, 13, null)], $owner);
if ($unclassified['data_class'] !== null) throw new RuntimeException('Null data class was replaced');

$host = new HostSandIamEnvironmentFacts(new class {
    public function verifyEnvironmentReferenceForClient(int $environmentId): array
    {
        return match ($environmentId) {
            7 => ['organization_id' => 11, 'application_id' => 12, 'environment_id' => 7, 'status' => 1],
            9 => ['organization_id' => 11, 'application_id' => 30, 'environment_id' => 9, 'status' => 1],
            default => throw new RuntimeException('unknown environment'),
        };
    }
});
$sourceFacts = new class($scope) extends KnowledgeVersionSourceScope {
    public function __construct(private readonly array $scope) {}
    public function resolve(int $versionId, array $owner): array
    {
        if ($versionId !== 72 || $owner !== ['organization_id' => 11, 'application_id' => 12, 'environment_id' => 7]) {
            throw new RuntimeException('unexpected source lookup');
        }
        return $this->scope;
    }
};
$resolver = new KnowledgePublicationFacts($host, $sourceFacts);
$row = [
    'id' => 81,
    'owner_environment_id' => 7,
    'consumer_organization_id' => 11,
    'consumer_application_id' => 30,
    'consumer_environment_id' => 9,
    'active_version_id' => 72,
    'source_scope_sha256' => $scope['source_scope_sha256'],
    'data_class' => 'internal',
];
$facts = $resolver->fromPersisted($row, 81, 90);
if ($facts !== [
    'organization_id' => 11, 'application_id' => 30, 'environment_id' => 9,
    'workload_client_id' => 90, 'data_class' => 'internal',
    'resource_type' => 'sand_ai.knowledge_base_version', 'resource_ref' => '81:72',
]) throw new RuntimeException('Consumer resource facts are wrong');
expectDenial(static fn () => $resolver->fromPersisted([...$row, 'consumer_application_id' => 31], 81, 90));
expectDenial(static fn () => $resolver->fromPersisted([...$row, 'source_scope_sha256' => str_repeat('0', 64)], 81, 90));
expectDenial(static fn () => $resolver->fromPersisted([...$row, 'data_class' => 'restricted'], 81, 90));
expectDenial(static fn () => $resolver->fromPersisted($row, 82, 90));
expectDenial(static fn () => $resolver->fromPersisted($row, 81, 0));
expectDenial(static fn () => $resolver->resolve('sand_ai', 'sand_ai.knowledge.query', '81:0'));
$catalogHost = new class {
    public array $actions = [];
    public function inspectServiceActions(array $service, array $actions): array
    {
        return ['service_code' => $service['code'], 'service_status' => 'missing',
            'action_statuses' => array_fill_keys(array_keys($actions), 'missing'), 'can_register' => true];
    }
    public function registerServiceActions(array $service, array $actions): void
    {
        if ($service['code'] !== 'sand_ai') throw new RuntimeException('Wrong service declaration');
        $this->actions = $actions;
    }
};
(new HostSandIamServiceCatalog($catalogHost))->register();
if (!array_key_exists('sand_ai.knowledge.query', $catalogHost->actions)) throw new RuntimeException('Knowledge action is not registered');
echo "knowledge-publication-facts 16/16\n";
