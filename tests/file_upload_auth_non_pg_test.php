<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime {
    interface ServiceInvocationFactResolver
    {
        public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array;
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
        if (!$condition) throw new \RuntimeException('File upload authorization failed at check ' . ($checks + 1));
        ++$checks;
    };
    $verifier = new class {
        public int $calls = 0;
        public bool $disabled = false;
        public int $applicationId = 22;
        public function verifyForService(int $clientId): array
        {
            ++$this->calls;
            if ($this->disabled || $clientId !== 44) throw new \RuntimeException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED');
            return [
                'organization_id' => 11, 'application_id' => $this->applicationId, 'environment_id' => 33,
                'workload_client_id' => 44, 'status' => 1,
            ];
        }
    };
    $facts = new \app\Identity\Infrastructure\HostSandIamWorkloadClientFactResolver($verifier);
    $resolved = $facts->resolve('sand_ai', 'sand_ai.file.write', '44');
    $assert($resolved === [
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => null,
        'resource_type' => 'sand_iam.workload_client', 'resource_ref' => '44',
    ]);
    $rejectFacts = static function (callable $operation) use (&$checks): void {
        try { $operation(); }
        catch (\app\Identity\Contract\IdentityContextException) { ++$checks; return; }
        throw new \RuntimeException('Invalid upload facts were accepted');
    };
    $before = $verifier->calls;
    $rejectFacts(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.read', '44'));
    $rejectFacts(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.write', '044'));
    $assert($verifier->calls === $before);
    $rejectFacts(static fn () => (new \app\Identity\Infrastructure\HostSandIamWorkloadClientFactResolver(new class {}))
        ->resolve('sand_ai', 'sand_ai.file.write', '44'));
    $verifier->disabled = true;
    $rejectFacts(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.write', '44'));
    $verifier->disabled = false;

    $host = new class($facts) {
        public int $calls = 0;
        public bool $deny = false;
        public function __construct(private readonly \app\Identity\Infrastructure\HostSandIamWorkloadClientFactResolver $facts) {}
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
                'signed-upload-context', 'sand_ai', 'sand-ai', 'sand_ai.file.write',
                'sand_iam.workload_client', '44', '127.0.0.1', 'upload-request-1234',
            ]) throw new \RuntimeException('Wrong upload invocation');
            $facts = $this->facts->resolve($service, $action, $resource);
            ++$this->calls;
            return [
                'authorization_id' => $this->calls, 'grant_id' => 55,
                'context_id' => 'upload-context', 'replayed' => false,
                'data_class' => $facts['data_class'], 'operation_id' => $operationId,
                'service_code' => $service, 'audience' => $audience, 'action_code' => $action,
                'organization_id' => $facts['organization_id'], 'application_id' => $facts['application_id'],
                'environment_id' => $facts['environment_id'], 'workload_client_id' => $facts['workload_client_id'],
            ];
        }
    };
    $request = new class("POST /api/sand-ai/v1/files HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-upload-context\r\nX-Request-ID: upload-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.file.write'], time() + 60,
    );
    $guard = new \app\Identity\Infrastructure\FileUploadAccessGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    $guard->authorize($request, $identity, 'upload-request-1234');
    $assert($host->calls === 1);
    $verifier->applicationId = 99;
    try {
        $guard->authorize($request, $identity, 'upload-request-1234');
        throw new \RuntimeException('Cross-application upload was authorized');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    }
    $verifier->applicationId = 22;
    $host->deny = true;
    try {
        $guard->authorize($request, $identity, 'upload-request-1234');
        throw new \RuntimeException('Denied upload reached storage');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN' && $host->calls === 2);
    }
    echo 'file-upload-auth ' . $checks . '/' . $checks . PHP_EOL;
}
