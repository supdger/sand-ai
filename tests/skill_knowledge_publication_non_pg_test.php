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
use app\Application\Knowledge\KnowledgeVersionSourceScope;
use app\Application\Skill\SkillKnowledgePublication;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;

$scopeHash = str_repeat('a', 64);
$snapshot = str_repeat('b', 64);
$row = [
    'publication_id' => '81', 'knowledge_base_id' => '70',
    'owner_environment_id' => '7', 'consumer_organization_id' => '11',
    'consumer_application_id' => '30', 'source_scope_sha256' => $scopeHash,
    'data_class' => 'internal',
];
$connection = new class($row, $snapshot) {
    public array $rows;
    public function __construct(array $row, private readonly string $snapshot) { $this->rows = [$row]; }
    public function query(string $sql, array $params): array
    {
        if (!str_contains($sql, "p.state='published'")
            || !str_contains($sql, 'v.id=?')
            || str_contains($sql, 'v.id=kb.active_version_id')
            || !str_contains($sql, 'FOR SHARE OF p,kb,v')
            || $params !== [72, 9, $this->snapshot]) {
            throw new RuntimeException('Pinned publication query changed');
        }
        return $this->rows;
    }
};
$environments = new HostSandIamEnvironmentFacts(new class {
    public function verifyEnvironmentReferenceForClient(int $id): array
    {
        return match ($id) {
            7 => ['organization_id' => 11, 'application_id' => 12, 'environment_id' => 7, 'status' => 1],
            9 => ['organization_id' => 11, 'application_id' => 30, 'environment_id' => 9, 'status' => 1],
            default => throw new RuntimeException('Unexpected environment'),
        };
    }
});
$sources = new class($scopeHash) extends KnowledgeVersionSourceScope {
    public function __construct(public string $hash) {}
    public function resolve(int $versionId, array $owner): array
    {
        if ($versionId !== 72 || $owner !== ['organization_id' => 11, 'application_id' => 12, 'environment_id' => 7]) {
            throw new RuntimeException('Wrong owner source lookup');
        }
        return ['source_scope_sha256' => $this->hash, 'data_class' => 'internal'];
    }
};
$gateway = new SkillKnowledgePublication($environments, $sources, $connection);
$actual = $gateway->resolve(9, 72, $snapshot);
if ($actual !== [
    'knowledge_base_id' => 70, 'owner_environment_id' => 7,
    'publication_id' => 81, 'data_class' => 'internal',
]) throw new RuntimeException('Cross-application publication was not resolved');
$denied = 0;
$cases = [
    static function () use ($gateway, $snapshot, $connection): void {
        $connection->rows = [];
        $gateway->resolve(9, 72, $snapshot);
    },
    static function () use ($gateway, $snapshot, $connection, $row): void {
        $connection->rows = [[...$row, 'consumer_application_id' => '31']];
        $gateway->resolve(9, 72, $snapshot);
    },
    static function () use ($gateway, $snapshot, $connection, $row, $sources): void {
        $connection->rows = [$row];
        $sources->hash = str_repeat('c', 64);
        $gateway->resolve(9, 72, $snapshot);
    },
];
foreach ($cases as $case) {
    try {
        $case();
    } catch (ApiProblem) {
        ++$denied;
        continue;
    }
    throw new RuntimeException('Invalid Skill publication was accepted');
}
if ($denied !== 3) throw new RuntimeException('Missing denial cases');
echo "skill-knowledge-publication 4/4\n";
