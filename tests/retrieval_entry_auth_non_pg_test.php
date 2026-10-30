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
        if (!$condition) throw new \RuntimeException('Retrieval entry authorization check ' . ($checks + 1) . ' failed');
        ++$checks;
    };
    $reference = new class {
        public bool $enabled = true;
        public function verifyForService(int $id): array
        {
            return [
                'workload_client_id' => $id, 'organization_id' => 11,
                'application_id' => 22, 'environment_id' => 33,
                'status' => $this->enabled ? 1 : 2,
            ];
        }
    };
    $resolver = new \app\Identity\Infrastructure\HostSandIamWorkloadClientFactResolver($reference);
    $facts = $resolver->resolve('sand_ai', 'sand_ai.retrieval.search', '44');
    $assert($facts === [
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => null,
        'resource_type' => 'sand_iam.workload_client', 'resource_ref' => '44',
    ]);
    $reference->enabled = false;
    try {
        $resolver->resolve('sand_ai', 'sand_ai.retrieval.search', '44');
        throw new \RuntimeException('Disabled client was accepted');
    } catch (\app\Identity\Contract\IdentityContextException) {
        ++$checks;
    }
    $reference->enabled = true;
    foreach (['0', 'caller-id', '45x'] as $badKey) {
        try {
            $resolver->resolve('sand_ai', 'sand_ai.retrieval.search', $badKey);
            throw new \RuntimeException('Invalid client resource key was accepted');
        } catch (\app\Identity\Contract\IdentityContextException) {
            ++$checks;
        }
    }
    $host = new class {
        public bool $deny = false;
        public ?string $dataClass = null;
        public int $authorizations = 0;
        public int $revalidations = 0;
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
                'signed-retrieval-context', 'sand_ai', 'sand-ai', 'sand_ai.retrieval.search',
                'sand_iam.workload_client', '44', '127.0.0.1', 'retrieval-request-1234',
            ]) throw new \RuntimeException('Wrong retrieval authorization request');
            ++$this->authorizations;
            return $this->decision($operationId);
        }
        public function revalidateInvocation(
            int $authorizationId, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$authorizationId, $context, $service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
                88, 'signed-retrieval-context', 'sand_ai', 'sand-ai', 'sand_ai.retrieval.search',
                'sand_iam.workload_client', '44', '127.0.0.1', 'retrieval-request-1234',
            ]) throw new \RuntimeException('Wrong retrieval revalidation request');
            ++$this->revalidations;
            return $this->decision('retrieval-search:revalidated');
        }
        private function decision(string $operationId): array
        {
            return [
                'authorization_id' => 88, 'grant_id' => 99, 'context_id' => 'retrieval-context',
                'replayed' => false, 'data_class' => $this->dataClass, 'operation_id' => $operationId,
                'service_code' => 'sand_ai', 'audience' => 'sand-ai',
                'action_code' => 'sand_ai.retrieval.search', 'organization_id' => 11,
                'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 44,
            ];
        }
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.retrieval.search'], time() + 60,
    );
    $request = new class("POST /api/sand-ai/v1/retrieval/search HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-retrieval-context\r\nX-Request-ID: retrieval-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $guard = new \app\Identity\Infrastructure\RetrievalSearchAccessGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    $decision = $guard->authorize($request, $identity, 'retrieval-request-1234');
    $guard->revalidate($request, $identity, 'retrieval-request-1234', $decision);
    $assert($host->authorizations === 1 && $host->revalidations === 1);
    $host->deny = true;
    try {
        $guard->revalidate($request, $identity, 'retrieval-request-1234', $decision);
        throw new \RuntimeException('Revoked retrieval result was returned');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    $host->deny = false;
    $host->dataClass = 'restricted';
    try {
        $guard->authorize($request, $identity, 'retrieval-request-1234');
        throw new \RuntimeException('Unexpected entry data class was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
    $host->dataClass = null;
    $host->deny = true;
    try {
        $guard->authorize($request, $identity, 'retrieval-request-1234');
        throw new \RuntimeException('Denied retrieval was authorized');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    echo 'retrieval-entry-auth ' . $checks . '/' . $checks . PHP_EOL;
}
