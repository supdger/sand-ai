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

use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\SkillInvocationFacts;

$invocation = [
    'id' => 101, 'status' => 1, 'delete_time' => null, 'data_class' => 'internal',
    'organization_id' => 11, 'application_id' => 30, 'environment_id' => 9,
    'workload_client_id' => 90, 'skill_id' => 40, 'skill_version_id' => 41, 'skill_run_id' => 42,
];
$dispatch = [
    'invocation_id' => 101, 'environment_id' => 9, 'application_id' => 30,
    'execution_scope' => 'skill', 'source_kind' => 'skill', 'service_action' => 'sand_ai.skill.run',
    'skill_id' => 40, 'skill_version_id' => 41, 'skill_run_id' => 42,
];
$run = [
    'id' => 42, 'environment_id' => 9, 'skill_id' => 40, 'skill_version_id' => 41,
    'run_kind' => 'workload', 'state' => 'running', 'invocation_id' => null, 'delete_time' => null,
];
$skill = [
    'id' => 40, 'environment_id' => 9, 'status' => 1, 'state' => 'published',
    'active_version_id' => 41, 'delete_time' => null,
];
$version = ['id' => 41, 'skill_id' => 40, 'state' => 'published'];
$reference = [
    'skill_version_id' => 41, 'knowledge_base_id' => 70,
    'knowledge_base_version_id' => 72, 'snapshot_sha256' => str_repeat('a', 64),
];
$knowledgeVersion = [
    'id' => 72, 'knowledge_base_id' => 70, 'state' => 'published',
    'snapshot_sha256' => str_repeat('a', 64), 'source_count' => 1,
];
$knowledgeBase = [
    'id' => 70, 'environment_id' => 7, 'status' => 1,
    'state' => 'published', 'delete_time' => null,
];
$knowledgeFacts = [
    'organization_id' => 11, 'application_id' => 30, 'environment_id' => 9,
    'workload_client_id' => 90, 'data_class' => 'internal',
    'resource_type' => 'sand_ai.knowledge_base_version', 'resource_ref' => '81:72',
];
$resolve = static fn (?array $facts, ?int $publicationId): array =>
    SkillInvocationFacts::fromPersisted(
        $invocation, $dispatch, $run, $skill, $version, $reference,
        $knowledgeVersion, $knowledgeBase, [[]], 101, $facts, $publicationId,
    );
$actual = $resolve($knowledgeFacts, 81);
if ($actual !== [
    'organization_id' => 11, 'application_id' => 30, 'environment_id' => 9,
    'workload_client_id' => 90, 'data_class' => 'internal',
    'resource_type' => 'sand_ai.skill_invocation', 'resource_ref' => '101',
]) throw new RuntimeException('Cross-application Skill facts are wrong');
$denied = 0;
foreach ([
    static fn () => $resolve(null, 81),
    static fn () => $resolve($knowledgeFacts, 82),
    static fn () => $resolve([...$knowledgeFacts, 'application_id' => 12], 81),
    static fn () => $resolve([...$knowledgeFacts, 'data_class' => 'restricted'], 81),
    static fn () => $resolve([...$knowledgeFacts, 'resource_ref' => '82:72'], 81),
    static fn () => $resolve([...$knowledgeFacts, 'resource_ref' => '81:73'], 81),
] as $case) {
    try {
        $case();
    } catch (IdentityContextException) {
        ++$denied;
        continue;
    }
    throw new RuntimeException('Unverified Skill publication was accepted');
}
if ($denied !== 6) throw new RuntimeException('Missing negative cases');
echo "skill-publication-facts 7/7\n";
