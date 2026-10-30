<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model {
    final class WorkflowRun
    {
        public function __construct(
            public int $id,
            public int $organization_id,
            public int $application_id,
            public int $environment_id,
            public int $workload_client_id,
        ) {
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) {
        throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
    }
    require $vendor;
    $app = getenv('SANDPACKAGE_TEST_ROOT') . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) {
            return;
        }
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }, true, true);

    use app\Api\Support\ApiProblem;
    use app\Identity\Contract\IdentityContext;
    use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
    use app\Identity\Infrastructure\QueuedSandIamContextCipher;
    use app\Identity\Infrastructure\WorkflowRunAccessGuard;
    use app\Infrastructure\Persistence\Model\WorkflowRun;

    $checks = 0;
    $assert = static function (bool $condition) use (&$checks): void {
        if (!$condition) {
            throw new \RuntimeException('Workflow queued authorization failed at check ' . ($checks + 1));
        }
        ++$checks;
    };
    $reject = static function (callable $run, string $code) use ($assert): void {
        try {
            $run();
        } catch (ApiProblem $problem) {
            $assert($problem->errorCode === $code);
            return;
        }
        throw new \RuntimeException('Workflow queued authorization did not reject ' . $code);
    };

    $host = new class {
        public int $authorizations = 0;
        public int $revalidations = 0;
        public bool $revoked = false;
        public int $readApplicationId = 22;
        public int $readEnvironmentId = 33;

        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($action === 'sand_ai.workflow.read') {
                if ([$context, $service, $audience, $resolver, $resource, $sourceIp, $requestId] !== [
                    'fresh-workflow-context', 'sand_ai', 'sand-ai', 'sand_ai.workflow_run',
                    '77', '127.0.0.1', 'workflow-request-1234',
                ]) {
                    throw new \RuntimeException('Unexpected workflow read authorization facts');
                }
                ++$this->authorizations;
                if ($this->revoked) {
                    throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: revoked');
                }
                return array_replace($this->decision(72, $operationId), [
                    'action_code' => 'sand_ai.workflow.read',
                    'application_id' => $this->readApplicationId,
                    'environment_id' => $this->readEnvironmentId,
                ]);
            }
            if (!in_array($context, ['old-workflow-context', 'fresh-workflow-context'], true)
                || [$service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
                    'sand_ai', 'sand-ai', 'sand_ai.workflow.run', 'sand_ai.workflow_run',
                    '77', '127.0.0.1', 'workflow-request-1234',
                ]) {
                throw new \RuntimeException('Unexpected workflow authorization facts');
            }
            ++$this->authorizations;
            return $this->decision($context === 'old-workflow-context' ? 71 : 72, $operationId);
        }

        public function revalidateInvocation(
            int $authorizationId, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            ++$this->revalidations;
            if ($this->revoked) {
                throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: revoked');
            }
            if (($authorizationId === 71 ? 'old-workflow-context' : 'fresh-workflow-context') !== $context
                || !in_array($authorizationId, [71, 72], true)
                || [$service, $audience, $action, $resolver, $resource, $sourceIp, $requestId] !== [
                    'sand_ai', 'sand-ai', 'sand_ai.workflow.run', 'sand_ai.workflow_run',
                    '77', '127.0.0.1', 'workflow-request-1234',
                ]) {
                throw new \RuntimeException('Unexpected queued workflow authorization facts');
            }
            return $this->decision($authorizationId, 'workflow-run:revalidated');
        }

        private function decision(int $authorizationId, string $operationId): array
        {
            return [
                'authorization_id' => $authorizationId,
                'grant_id' => 55,
                'context_id' => 'workflow-context',
                'replayed' => false,
                'data_class' => null,
                'operation_id' => $operationId,
                'service_code' => 'sand_ai',
                'audience' => 'sand-ai',
                'action_code' => 'sand_ai.workflow.run',
                'organization_id' => 11,
                'application_id' => 22,
                'environment_id' => 33,
                'workload_client_id' => 44,
            ];
        }
    };
    $request = static function (string $context): \support\Request {
        return new class("POST /api/sand-ai/v1/tasks/99/retry HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$context}\r\nX-Request-ID: workflow-request-1234\r\n\r\n") extends \support\Request {
            public function getRemoteIp(): string
            {
                return '127.0.0.1';
            }
        };
    };
    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.workflow.run'], time() + 120);
    $guard = new WorkflowRunAccessGuard(
        new HostSandIamInvocationAuthorizer($host),
        new QueuedSandIamContextCipher([
            'current_key_id' => 'test',
            'current_key' => base64_encode(random_bytes(32)),
        ]),
    );
    $run = new WorkflowRun(77, 11, 22, 33, 44);
    $old = $guard->authorizeRun($request('old-workflow-context'), $identity, 77, 'workflow-request-1234');
    $fresh = $guard->authorizeRun($request('fresh-workflow-context'), $identity, 77, 'workflow-request-1234');
    $assert($host->authorizations === 2 && $old['authorization_id'] === 71 && $fresh['authorization_id'] === 72);
    $assert(!str_contains(json_encode($fresh, JSON_THROW_ON_ERROR), 'fresh-workflow-context'));

    $guard->revalidateQueued($fresh, $run);
    $assert($host->revalidations === 1);
    $reject(static fn () => $guard->revalidateQueued($fresh, new WorkflowRun(78, 11, 22, 33, 44)),
        'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    $assert($host->revalidations === 1);
    $reject(static fn () => $guard->revalidateQueued([...$old, 'expires_at' => time() - 1], $run),
        'SAND_AI_IDENTITY_CONTEXT_EXPIRED');
    $assert($host->revalidations === 1);
    $host->revoked = true;
    $reject(static fn () => $guard->revalidateQueued($fresh, $run), 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $assert($host->revalidations === 2);

    // Read/source controllers use this same current resource authorization port.
    $host->revoked = false;
    $readIdentity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.workflow.read'], time() + 120);
    $guard->authorizeRead($request('fresh-workflow-context'), $readIdentity, 77, 'workflow-request-1234');
    $assert($host->authorizations === 3);
    $host->revoked = true;
    $reject(static fn () => $guard->authorizeRead($request('fresh-workflow-context'), $readIdentity, 77, 'workflow-request-1234'),
        'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $host->revoked = false;
    $host->readApplicationId = 23;
    $reject(static fn () => $guard->authorizeRead($request('fresh-workflow-context'), $readIdentity, 77, 'workflow-request-1234'),
        'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    $host->readApplicationId = 22;
    $host->readEnvironmentId = 34;
    $reject(static fn () => $guard->authorizeRead($request('fresh-workflow-context'), $readIdentity, 77, 'workflow-request-1234'),
        'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    $host->readEnvironmentId = 33;
    $reject(static fn () => $guard->authorizeRead($request('fresh-workflow-context'), $identity, 77, 'workflow-request-1234'),
        'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $assert($host->authorizations === 6);
    echo 'workflow-queued-auth ' . $checks . '/' . $checks . PHP_EOL;
}
