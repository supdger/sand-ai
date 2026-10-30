<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'app\\')) return;
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($appRoot . $relative)) {
        require $appRoot . $relative;
    }
});

use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\ChatInvocationFacts;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Knowledge answer fact check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$reject = static function (array $invocation, array $dispatch, ?array $knowledge, ?string $scope) use ($assert): void {
    try {
        ChatInvocationFacts::fromPersisted($invocation, $dispatch, 81, $knowledge, $scope);
        throw new RuntimeException('Invalid invocation facts were accepted');
    } catch (IdentityContextException $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
};
$invocation = [
    'id' => 81, 'status' => 1, 'delete_time' => null,
    'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
    'workload_client_id' => 44, 'data_class' => null,
];
$dispatch = [
    'invocation_id' => 81, 'environment_id' => 33, 'application_id' => 22,
    'execution_scope' => 'chat', 'source_kind' => 'chat',
    'service_action' => 'sand_ai.chat.complete',
];
$plain = ChatInvocationFacts::fromPersisted($invocation, $dispatch, 81);
$assert($plain['data_class'] === null);
$scope = str_repeat('a', 64);
$classified = $invocation + [];
$classified['data_class'] = 'internal';
$classified['knowledge_publication_id'] = 50;
$classified['knowledge_base_version_id'] = 60;
$classified['knowledge_source_scope_sha256'] = $scope;
$facts = [
    'resource_ref' => '50:60', 'organization_id' => 11, 'application_id' => 22,
    'environment_id' => 33, 'workload_client_id' => 44, 'data_class' => 'internal',
];
$assert(ChatInvocationFacts::fromPersisted($classified, $dispatch, 81, $facts, $scope)['data_class'] === 'internal');
$reject($classified, $dispatch, null, $scope);
$reject($classified, $dispatch, $facts, str_repeat('b', 64));
$reject($classified, $dispatch, [...$facts, 'workload_client_id' => 99], $scope);
$reject([...$invocation, 'data_class' => 'internal'], $dispatch, null, null);
echo 'knowledge-answer-facts ' . $checks . '/' . $checks . PHP_EOL;
