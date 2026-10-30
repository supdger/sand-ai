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
    if (!$condition) throw new RuntimeException('Knowledge query grant check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$host = new class {
    public bool $revoked = false;
    public int $revalidations = 0;
    public function authorizeInvocation(
        string $context, string $service, string $audience, string $action,
        string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
    ): array {
        if ([$context, $service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
            'signed-knowledge-context', 'sand_ai', 'sand-ai', 'sand_ai.knowledge.query',
            'sand_ai.knowledge_publication', '81:44', '127.0.0.1', 'knowledge-request-1234',
        ]) throw new RuntimeException('Incorrect knowledge query authorization facts');
        return $this->decision($operationId);
    }
    public function revalidateInvocation(
        int $authorizationId, string $context, string $service, string $audience,
        string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
    ): array {
        ++$this->revalidations;
        if ($this->revoked) throw new RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        if ([$authorizationId, $context, $service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
            88, 'signed-knowledge-context', 'sand_ai', 'sand-ai', 'sand_ai.knowledge.query',
            'sand_ai.knowledge_publication', '81:44', '127.0.0.1', 'knowledge-request-1234',
        ]) throw new RuntimeException('Incorrect knowledge query revalidation facts');
        return $this->decision('knowledge-query:revalidated');
    }
    private function decision(string $operationId): array
    {
        return [
            'authorization_id' => 88, 'grant_id' => 99, 'context_id' => 'knowledge-context',
            'replayed' => false, 'data_class' => null, 'operation_id' => $operationId,
            'service_code' => 'sand_ai', 'audience' => 'sand-ai',
            'action_code' => 'sand_ai.knowledge.query', 'organization_id' => 11,
            'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 44,
        ];
    }
};
$identity = new \app\Identity\Contract\IdentityContext(
    '11', '22', 33, '44', 'sand-ai', ['sand_ai.knowledge.query'], time() + 60,
);
$request = new class("POST /api/sand-ai/v1/knowledge/query HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-knowledge-context\r\nX-Request-ID: knowledge-request-1234\r\n\r\n") extends \support\Request {
    public function getRemoteIp(): string { return '127.0.0.1'; }
};
$guard = new \app\Identity\Infrastructure\KnowledgePublicationAccessGuard(
    new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
);
$decision = $guard->authorize($request, $identity, 81, 44, 'knowledge-request-1234');
$assert($decision['authorization_id'] === 88);
$guard->revalidate($request, $identity, 81, 44, 'knowledge-request-1234', $decision);
$assert($host->revalidations === 1);
$host->revoked = true;
try {
    $guard->revalidate($request, $identity, 81, 44, 'knowledge-request-1234', $decision);
    throw new RuntimeException('Revoked knowledge result was returned');
} catch (\app\Api\Support\ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
}
echo 'knowledge-query-revalidation ' . $checks . '/' . $checks . PHP_EOL;
