<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime {
    if (!interface_exists(ServiceInvocationFactResolver::class)) {
        interface ServiceInvocationFactResolver
        {
            public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array;
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
    require $vendor;
    $app = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);

    $checks = 0;
    $assert = static function (bool $condition) use (&$checks): void {
        if (!$condition) throw new \RuntimeException('Tool authorization failed at check ' . ($checks + 1));
        ++$checks;
    };
    $run = [
        'id' => 77, 'state' => 'running', 'organization_id' => 11, 'application_id' => 22,
        'environment_id' => 33, 'workload_client_id' => 44,
        'identity_snapshot' => ['organization_ref' => '11', 'application_ref' => '22', 'workload_client_ref' => '44'],
        'execution_snapshot' => ['connection_id' => 55, 'connection_revision_id' => 66, 'data_class' => 'internal'],
        'tool_connection_id' => 55, 'tool_connection_revision_id' => 66,
    ];
    $revision = ['id' => 66, 'tool_connection_id' => 55, 'data_class' => 'internal', 'state' => 'published'];
    $facts = \app\Identity\Infrastructure\ToolInvocationFacts::fromPersisted($run, $revision, 77);
    $assert($facts === [
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => 'internal',
        'resource_type' => 'sand_ai.tool_run', 'resource_ref' => '77',
    ]);
    $reject = static function (array $changedRun, array $changedRevision) use (&$checks): void {
        try {
            \app\Identity\Infrastructure\ToolInvocationFacts::fromPersisted($changedRun, $changedRevision, 77);
        } catch (\app\Identity\Contract\IdentityContextException) {
            ++$checks;
            return;
        }
        throw new \RuntimeException('Untrusted tool facts were accepted');
    };
    $reject([...$run, 'application_id' => 99], $revision);
    $reject([...$run, 'execution_snapshot' => [...$run['execution_snapshot'], 'data_class' => 'public']], $revision);
    $reject($run, [...$revision, 'data_class' => null]);
    $reject($run, [...$revision, 'tool_connection_id' => 99]);
    $reject([...$run, 'identity_snapshot' => [...$run['identity_snapshot'], 'workload_client_ref' => '99']], $revision);
    $reject([...$run, 'state' => 'deleted'], $revision);
    $connectionInput = new \ReflectionMethod(\app\Application\Tool\ToolAdminGateway::class, 'connectionInput');
    $dto = [
        'code' => 'source', 'name' => 'Source', 'origin' => 'https://source.example',
        'path' => '/read', 'authorization' => ['mode' => 'none'], 'data_class' => 'internal',
    ];
    $normalized = $connectionInput->invoke(new \app\Application\Tool\ToolAdminGateway(), $dto);
    $assert($normalized['data_class'] === 'internal');
    try {
        $connectionInput->invoke(new \app\Application\Tool\ToolAdminGateway(), [...$dto, 'data_class' => '']);
        throw new \RuntimeException('Unclassified connection was accepted');
    } catch (\app\Api\Support\ApiProblem) {
        ++$checks;
    }

    $host = new class($facts) {
        public bool $deny = false;
        public bool $revoked = false;
        public ?string $decisionClass = null;
        public int $calls = 0;
        public function __construct(private readonly array $facts) {}
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $resolver, $resource, $sourceIp, $requestId] !== [
                'signed-tool-context', 'sand_ai', 'sand-ai', 'sand_ai.tool_run', '77', '127.0.0.1', 'tool-request-1234',
            ]) throw new \RuntimeException('Wrong tool authorization request');
            ++$this->calls;
            return $this->decision($action, $operationId);
        }
        public function revalidateInvocation(
            int $authorizationId, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            if ($this->revoked) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ($authorizationId < 1 || $context !== 'signed-tool-context' || $service !== 'sand_ai'
                || $audience !== 'sand-ai' || $resolver !== 'sand_ai.tool_run'
                || $resource !== '77' || $sourceIp !== '127.0.0.1' || $requestId !== 'tool-request-1234') {
                throw new \RuntimeException('Wrong tool revalidation request');
            }
            return $this->decision($action, 'tool-run:revalidated');
        }
        private function decision(string $action, string $operationId): array
        {
            return [
                'authorization_id' => 88, 'grant_id' => 99, 'context_id' => 'tool-context',
                'replayed' => false, 'data_class' => $this->decisionClass ?? $this->facts['data_class'],
                'operation_id' => $operationId, 'service_code' => 'sand_ai', 'audience' => 'sand-ai',
                'action_code' => $action, 'organization_id' => $this->facts['organization_id'],
                'application_id' => $this->facts['application_id'], 'environment_id' => $this->facts['environment_id'],
                'workload_client_id' => $this->facts['workload_client_id'],
            ];
        }
    };
    $request = new class("POST /api/sand-ai/v1/tools/example/versions/1/runs HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-tool-context\r\nX-Request-ID: tool-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.tool.run', 'sand_ai.tool.read'], time() + 60,
    );
    $guard = new \app\Identity\Infrastructure\ToolRunAccessGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    $decision = $guard->authorize($request, $identity, 77, 'sand_ai.tool.run', 'tool-request-1234', 'internal');
    $assert($decision['data_class'] === 'internal' && $host->calls === 1);
    $guard->revalidate($request, $identity, 77, 'sand_ai.tool.run', 'tool-request-1234', $decision);
    ++$checks;
    $guard->authorize($request, $identity, 77, 'sand_ai.tool.read', 'tool-request-1234', 'internal');
    $assert($host->calls === 2);
    try {
        $guard->authorize($request, $identity, 77, 'sand_ai.tool.read', 'tool-request-1234', null);
        throw new \RuntimeException('Unclassified tool run was authorized');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED' && $host->calls === 2);
    }
    $host->decisionClass = 'public';
    try {
        $guard->authorize($request, $identity, 77, 'sand_ai.tool.read', 'tool-request-1234', 'internal');
        throw new \RuntimeException('Incorrect data class was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
    $host->decisionClass = null;
    $host->deny = true;
    try {
        $guard->authorize($request, $identity, 77, 'sand_ai.tool.run', 'tool-request-1234', 'internal');
        throw new \RuntimeException('Denied tool run was authorized');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    $host->deny = false;
    $host->revoked = true;
    try {
        $guard->revalidate($request, $identity, 77, 'sand_ai.tool.run', 'tool-request-1234', $decision);
        throw new \RuntimeException('Revoked tool result was returned');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    $host->revoked = false;
    $host->decisionClass = 'public';
    try {
        $guard->revalidate($request, $identity, 77, 'sand_ai.tool.run', 'tool-request-1234', $decision);
        throw new \RuntimeException('Changed tool data class was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
    echo 'tool-invocation-auth ' . $checks . '/' . $checks . PHP_EOL;
}
