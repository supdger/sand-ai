<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $root . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
}, true, true);

use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\TaskOperationAccessGuard;

$host = new class {
    public array $calls = [];
    public function verifyForService(string $context, string $service, string $audience, string $action, string $ip, string $requestId): array
    {
        $this->calls[] = ['verify', $context, $service, $audience, $action, $ip, $requestId];
        return ['organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
            'workload_client_id' => 44, 'service_code' => 'sand_ai', 'audience' => 'sand-ai',
            'actions' => ['sand_ai.agent.submit', 'sand_ai.agent.run', 'sand_ai.task.read'], 'exp' => time() + 120];
    }
    public function authorizeInvocation(string $context, string $service, string $audience, string $action, string $resolver, string $resource, string $ip, string $operationId, string $requestId): array
    {
        $this->calls[] = ['authorize', $context, $service, $audience, $action, $resolver, $resource, $ip, $requestId];
        return ['authorization_id' => 5, 'grant_id' => 6, 'context_id' => 'ctx-1', 'replayed' => false,
            'data_class' => null, 'operation_id' => $operationId, 'service_code' => 'sand_ai',
            'audience' => 'sand-ai', 'action_code' => $action, 'organization_id' => 11,
            'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 44];
    }
    public function revalidateInvocation(int $id, string $context, string $service, string $audience, string $action, string $resolver, string $resource, string $ip, string $requestId): array
    {
        $this->calls[] = ['revalidate', $id, $context, $action, $resolver, $resource, $ip, $requestId];
        return ['authorization_id' => $id, 'grant_id' => 6, 'context_id' => 'ctx-1', 'replayed' => false,
            'operation_id' => 'task-operation:feishu-test',
            'data_class' => null, 'service_code' => 'sand_ai', 'audience' => 'sand-ai',
            'action_code' => $action, 'organization_id' => 11, 'application_id' => 22,
            'environment_id' => 33, 'workload_client_id' => 44];
    }
};
$provider = new HostSandIamIdentityContextProvider($host);
$identity = $provider->requireSignedContext('signed-context', 'sand_ai.agent.submit', '127.0.0.1', 'feishu-request-1234');
if ($identity->applicationId !== '22' || $host->calls[0] !== [
    'verify', 'signed-context', 'sand_ai', 'sand-ai', 'sand_ai.agent.submit', '127.0.0.1', 'feishu-request-1234',
]) throw new RuntimeException('SandIAM signed context was not verified');
$decision = (new HostSandIamInvocationAuthorizer($host))->authorizeSigned(
    'Bearer signed-context', '127.0.0.1', $identity, 'sand_ai.agent.submit',
    'sand_ai.task_run', '77', 'task-run:feishu-test', 'feishu-request-1234',
);
if ($decision['authorization_id'] !== 5 || $host->calls[1] !== [
    'authorize', 'signed-context', 'sand_ai', 'sand-ai', 'sand_ai.agent.submit',
    'sand_ai.task_run', '77', '127.0.0.1', 'feishu-request-1234',
]) throw new RuntimeException('SandIAM task authorization was bypassed');
try {
    $provider->requireSignedContext('signed-context', 'sand_ai.agent.submit', '0.0.0.0', 'feishu-request-1234');
    throw new RuntimeException('Invalid source IP accepted');
} catch (IdentityContextException $exception) {
    if ($exception->getMessage() !== 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE') throw $exception;
}
$readIdentity = $provider->requireSignedContext('signed-context', 'sand_ai.task.read', '127.0.0.1', 'feishu-delivery-1234');
$guard = new TaskOperationAccessGuard(new HostSandIamInvocationAuthorizer($host));
$read = $guard->authorizeSigned('Bearer signed-context', '127.0.0.1', $readIdentity, 77, 'sand_ai.task.read', 'feishu-delivery-1234');
if ($read['authorization_id'] !== 5 || $host->calls[3][5] !== 'sand_ai.task_operation') {
    throw new RuntimeException('Delivery task read authorization was bypassed');
}
$guard->revalidateSigned('signed-context', '127.0.0.1', $readIdentity, 77, 'sand_ai.task.read', 'feishu-delivery-1234', $read);
if ($host->calls[4] !== ['revalidate', 5, 'signed-context', 'sand_ai.task.read', 'sand_ai.task_operation', '77', '127.0.0.1', 'feishu-delivery-1234']) {
    throw new RuntimeException('Delivery task read authorization was not revalidated');
}
echo "feishu-signed-context 5/5\n";
