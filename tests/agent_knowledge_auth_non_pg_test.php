<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $root . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
}, true, true);

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;

$host = new class {
    public array $calls = [];
    public ?string $dataClass = 'internal';
    public bool $revoked = false;
    public function authorizeInvocation(
        string $context, string $service, string $audience, string $action,
        string $resolver, string $resource, string $ip, string $operationId, string $requestId,
    ): array {
        $this->calls[] = [$context, $action, $resolver, $resource];
        return $this->decision($operationId);
    }
    public function revalidateInvocation(
        int $id, string $context, string $service, string $audience, string $action,
        string $resolver, string $resource, string $ip, string $requestId,
    ): array {
        if ($this->revoked) throw new RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        $this->calls[] = [$context, $action, $resolver, $resource];
        return $this->decision('queued-source:revalidated');
    }
    private function decision(string $operationId): array
    {
        return ['authorization_id' => 88, 'grant_id' => 99, 'context_id' => 'agent-knowledge',
            'replayed' => false, 'data_class' => $this->dataClass, 'operation_id' => $operationId,
            'service_code' => 'sand_ai', 'audience' => 'sand-ai',
            'action_code' => 'sand_ai.knowledge.query', 'organization_id' => 11,
            'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 44];
    }
};
$guard = new AgentKnowledgeAccessGuard(new HostSandIamInvocationAuthorizer($host));
$publication = ['id' => 81, 'active_version_id' => 12, 'consumer_organization_id' => 11,
    'consumer_application_id' => 22, 'consumer_environment_id' => 33, 'data_class' => 'internal'];
$queued = ['signed_context' => 'signed-agent-context',
    'identity' => new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.agent.submit'], time() + 120),
    'source_ip' => '127.0.0.1', 'request_id' => 'agent-knowledge-request-1234'];
$decision = $guard->authorizeQueued($publication, $queued);
if ($decision['authorization_id'] !== 88 || $host->calls[0] !== [
    'signed-agent-context', 'sand_ai.knowledge.query', 'sand_ai.knowledge_publication', '81:44:12',
]) throw new RuntimeException('Agent knowledge authorization did not use the pinned publication');
$guard->revalidateQueued($publication, $queued, $decision);
if ($host->calls[1] !== $host->calls[0]) throw new RuntimeException('Agent knowledge grant was not revalidated');
try {
    $guard->authorizeQueued([...$publication, 'consumer_application_id' => 99], $queued);
    throw new RuntimeException('Another consumer application was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_INVOCATION_FACTS_UNVERIFIED') throw $problem;
}
$host->dataClass = null;
try {
    $guard->authorizeQueued($publication, $queued);
    throw new RuntimeException('A different data class was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_INVOCATION_FACTS_UNVERIFIED') throw $problem;
}
$host->revoked = true;
try {
    $guard->revalidateQueued($publication, $queued, $decision);
    throw new RuntimeException('Revoked knowledge grant was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_SERVICE_ACTION_FORBIDDEN') throw $problem;
}
echo "agent-knowledge-auth 5/5\n";
