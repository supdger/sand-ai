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
        if (!$condition) throw new \RuntimeException('Task operation authorization failed at check ' . ($checks + 1));
        ++$checks;
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai',
        ['sand_ai.task.read', 'sand_ai.task.cancel', 'sand_ai.task.retry'], time() + 60,
    );
    $attribution = static function (string $kind, string $action) use ($identity): \app\Application\Usage\UsageAttribution {
        return \app\Application\Usage\UsageAttribution::fromIdentity($identity, $kind, $action);
    };
    $scope = static function (\app\Application\Usage\UsageAttribution $owner): string {
        return \app\Application\Gateway\RequestIdempotencyKey::trustedScopeForAttribution($owner);
    };
    $base = [
        'id' => 77, 'status' => 1, 'delete_time' => null,
        'organization_id' => null, 'application_id' => null,
        'environment_id' => 33, 'workload_client_id' => null, 'data_class' => null,
    ];
    $source = [
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => 'internal',
        'resource_type' => 'sand_ai.file', 'resource_ref' => '88',
    ];
    $fileOwner = $attribution('file_parse', 'sand_ai.document_parse');
    $fileTask = $base + [
        'task_type' => 'file_parse', 'resource_type' => 'file', 'resource_id' => 88,
        'input' => ['file_id' => 88, '_usage_attribution' => $fileOwner->toPersisted(),
            '_trusted_identity_scope' => $scope($fileOwner)],
    ];
    $facts = \app\Identity\Infrastructure\TaskOperationFacts::fromPersisted($fileTask, 77, $source);
    $assert($facts === [
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => 'internal',
        'resource_type' => 'sand_ai.task_operation', 'resource_ref' => '77',
    ]);
    $audioOwner = $attribution('capability', 'sand_ai.audio.transcription');
    $audioTask = $base + [
        'task_type' => 'audio_transcription', 'resource_type' => 'audio_transcription', 'resource_id' => 77,
        'input' => ['file_id' => 88, 'request' => ['file_ref' => 'audio-ref'],
            'usage_attribution' => $audioOwner->toPersisted(),
            '_trusted_identity_scope' => $scope($audioOwner)],
    ];
    $assert(\app\Identity\Infrastructure\TaskOperationFacts::fromPersisted($audioTask, 77, $source) === $facts);
    $workflowOwner = $attribution('structured_workflow', 'sand_ai.workflow.run');
    $workflowTask = $base + [
        'task_type' => 'structured_workflow', 'resource_type' => 'workflow_run', 'resource_id' => 99,
        'input' => ['workflow_run_id' => 99],
    ];
    $workflow = ['id' => 99, 'task_id' => 77, 'attribution_snapshot' => $workflowOwner->toPersisted()];
    $workflowSource = [...$source, 'resource_type' => 'sand_ai.workflow_run',
        'resource_ref' => '99', 'data_class' => 'restricted'];
    $assert(\app\Identity\Infrastructure\TaskOperationFacts::fromPersisted(
        $workflowTask, 77, $workflowSource, $workflow,
    )['data_class'] === 'restricted');
    foreach ([
        ['ai_completion', 'ai_task', 'sand_ai.chat.complete', 'ai_task'],
        ['agent_run', 'agent_run', 'sand_ai.agent.submit', 'agent'],
    ] as [$type, $resourceType, $action, $kind]) {
        $owner = $attribution($kind, $action);
        $row = [...$base, 'organization_id' => 11, 'application_id' => 22,
            'workload_client_id' => 44, 'task_type' => $type, 'resource_type' => $resourceType,
            'resource_id' => 77, 'input' => ['_usage_attribution' => $owner->toPersisted(),
                '_trusted_identity_scope' => $scope($owner), '_resource_data_class' => null]];
        $resolved = \app\Identity\Infrastructure\TaskOperationFacts::fromPersisted($row, 77);
        $assert($resolved['resource_type'] === 'sand_ai.task_operation' && $resolved['workload_client_id'] === 44);
    }
    $reject = static function (array $task, ?array $resource = null, ?array $run = null) use (&$checks): void {
        try {
            \app\Identity\Infrastructure\TaskOperationFacts::fromPersisted($task, 77, $resource, $run);
        } catch (\app\Identity\Contract\IdentityContextException) {
            ++$checks;
            return;
        }
        throw new \RuntimeException('Untrusted task operation facts were accepted');
    };
    $reject([...$fileTask, 'delete_time' => '2026-09-25'], $source);
    $reject([...$fileTask, 'input' => [...$fileTask['input'], 'file_id' => 89]], $source);
    $reject($fileTask, [...$source, 'application_id' => 99]);
    $reject($audioTask, [...$source, 'data_class' => '']);
    $reject($workflowTask, $workflowSource, [...$workflow, 'task_id' => 88]);

    $host = new class($facts) {
        public bool $deny = false;
        public int $calls = 0;
        public function __construct(private readonly array $facts) {}
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $resolver, $resource, $sourceIp, $requestId] !== [
                'signed-task-context', 'sand_ai', 'sand-ai', 'sand_ai.task_operation', '77', '127.0.0.1', 'task-request-1234',
            ]) throw new \RuntimeException('Wrong task authorization request');
            ++$this->calls;
            return $this->decision($action, $operationId);
        }
        public function revalidateInvocation(
            int $authorizationId, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ($authorizationId !== 88 || $context !== 'signed-task-context' || $resource !== '77') {
                throw new \RuntimeException('Wrong task revalidation request');
            }
            return $this->decision($action, 'task-operation:revalidated');
        }
        private function decision(string $action, string $operationId): array
        {
            return [
                'authorization_id' => 88, 'grant_id' => 99, 'context_id' => 'task-context',
                'replayed' => false, 'data_class' => $this->facts['data_class'],
                'operation_id' => $operationId, 'service_code' => 'sand_ai', 'audience' => 'sand-ai',
                'action_code' => $action, 'organization_id' => 11, 'application_id' => 22,
                'environment_id' => 33, 'workload_client_id' => 44,
            ];
        }
    };
    $request = new class("GET /api/sand-ai/v1/tasks/77 HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-task-context\r\nX-Request-ID: task-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $guard = new \app\Identity\Infrastructure\TaskOperationAccessGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    foreach (['sand_ai.task.read', 'sand_ai.task.cancel', 'sand_ai.task.retry'] as $action) {
        $decision = $guard->authorize($request, $identity, 77, $action, 'task-request-1234');
        $guard->revalidate($request, $identity, 77, $action, 'task-request-1234', $decision);
    }
    $assert($host->calls === 3);
    $host->deny = true;
    try {
        $guard->authorize($request, $identity, 77, 'sand_ai.task.cancel', 'task-request-1234');
        throw new \RuntimeException('Denied task cancellation was authorized');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    echo 'task-operation-auth ' . $checks . '/' . $checks . PHP_EOL;
}
