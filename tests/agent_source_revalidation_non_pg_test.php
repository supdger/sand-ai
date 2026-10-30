<?php

declare(strict_types=1);

namespace AgentSourceFixture {
    final class Query
    {
        private array $filters = [];
        public function __construct(private readonly array $rows) {}
        public function where(string $field, mixed $value): self
        {
            $this->filters[$field] = $value;
            return $this;
        }
        public function whereNull(string $field): self
        {
            $this->filters[$field] = null;
            return $this;
        }
        public function find(): ?object
        {
            foreach ($this->rows as $row) {
                foreach ($this->filters as $field => $value) {
                    if (($row[$field] ?? null) !== $value) continue 2;
                }
                return (object) $row;
            }
            return null;
        }
    }
}

namespace app\Infrastructure\Persistence\Model {
    class SourceBlock
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \AgentSourceFixture\Query
        {
            return (new \AgentSourceFixture\Query(self::$rows))->where($field, $value);
        }
    }
    class AiFileParse
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \AgentSourceFixture\Query
        {
            return (new \AgentSourceFixture\Query(self::$rows))->where($field, $value);
        }
    }
    class AiFile
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \AgentSourceFixture\Query
        {
            return (new \AgentSourceFixture\Query(self::$rows))->where($field, $value);
        }
    }
    class TaskRun
    {
        public int $id = 77;
        public int $environment_id = 33;
        public string $task_type = 'agent_run';
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
        if (!$condition) throw new \RuntimeException('Agent source revalidation check ' . ($checks + 1) . ' failed');
        ++$checks;
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.agent.submit'], time() + 60,
    );
    $owner = \app\Application\Usage\UsageAttribution::fromIdentity(
        $identity, 'file', 'sand_ai.file.write',
    );
    $attribution = \app\Application\Usage\UsageAttribution::fromIdentity(
        $identity, 'agent', 'sand_ai.agent.run',
    );
    $scope = \app\Application\File\FileOwnershipGuard::scope($owner);
    $file = static fn (int $id): array => [
        'id' => $id, 'status' => 1, 'delete_time' => null, 'state' => 'parsed',
        'environment_id' => 33, 'owner_scope' => $scope,
        'owner_attribution' => [...$owner->toPersisted(), 'data_class' => 'confidential'],
    ];
    \app\Infrastructure\Persistence\Model\AiFile::$rows = [$file(51), $file(52)];
    \app\Infrastructure\Persistence\Model\AiFileParse::$rows = [
        ['id' => 61, 'file_id' => 51, 'state' => 'succeeded', 'status' => 1, 'delete_time' => null],
        ['id' => 62, 'file_id' => 52, 'state' => 'succeeded', 'status' => 1, 'delete_time' => null],
    ];
    \app\Infrastructure\Persistence\Model\SourceBlock::$rows = [
        ['id' => 71, 'file_id' => 51, 'parse_id' => 61, 'status' => 1, 'delete_time' => null],
        ['id' => 72, 'file_id' => 52, 'parse_id' => 62, 'status' => 1, 'delete_time' => null],
    ];
    $host = new class {
        public bool $deny = false;
        public array $authorized = [];
        public array $revalidated = [];
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $action, $sourceIp, $requestId] !== [
                'signed-agent-context', 'sand_ai', 'sand-ai', 'sand_ai.agent.run', '127.0.0.1', 'agent-request-1234',
            ]) throw new \RuntimeException('Wrong Agent source authorization');
            $id = 100 + count($this->authorized);
            $this->authorized[] = [$resolver, $resource, $id];
            return $this->decision($id, $operationId, $resolver);
        }
        public function revalidateInvocation(
            int $id, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if (!in_array([$resolver, $resource, $id], $this->authorized, true)
                || [$context, $service, $audience, $action, $sourceIp, $requestId] !== [
                    'signed-agent-context', 'sand_ai', 'sand-ai', 'sand_ai.agent.run', '127.0.0.1', 'agent-request-1234',
                ]) throw new \RuntimeException('Wrong Agent source revalidation');
            $this->revalidated[] = [$resolver, $resource, $id];
            return $this->decision($id, 'agent-source:revalidated', $resolver);
        }
        private function decision(int $id, string $operationId, string $resolver): array
        {
            return [
                'authorization_id' => $id, 'grant_id' => 55, 'context_id' => 'agent-context',
                'replayed' => false, 'data_class' => $resolver === 'sand_ai.file' ? 'confidential' : null,
                'operation_id' => $operationId, 'service_code' => 'sand_ai', 'audience' => 'sand-ai',
                'action_code' => 'sand_ai.agent.run', 'organization_id' => 11, 'application_id' => 22,
                'environment_id' => 33, 'workload_client_id' => 44,
            ];
        }
    };
    $queued = [
        'signed_context' => 'signed-agent-context', 'identity' => $identity,
        'source_ip' => '127.0.0.1', 'request_id' => 'agent-request-1234',
    ];
    $task = new \app\Infrastructure\Persistence\Model\TaskRun();
    $guard = new \app\Identity\Infrastructure\AgentRetrievedSourceGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    $matches = [['source_block_id' => 71, 'file_id' => 51], ['source_block_id' => 72, 'file_id' => 52]];
    $grants = $guard->authorize($matches, $task, $attribution, $queued);
    $assert($grants === [
        ['resource_type' => 'sand_ai.file', 'resource_ref' => '51', 'authorization_id' => 100],
        ['resource_type' => 'sand_ai.file', 'resource_ref' => '52', 'authorization_id' => 101],
    ]);
    $guard->revalidate($matches, $task, $attribution, $queued, $grants);
    $assert(count($host->revalidated) === 2);
    $host->deny = true;
    try {
        $guard->revalidate($matches, $task, $attribution, $queued, $grants);
        throw new \RuntimeException('Revoked Agent source grant was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    }
    $host->deny = false;
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['delete_time'] = '2026-09-25';
    try {
        $guard->revalidate($matches, $task, $attribution, $queued, $grants);
        throw new \RuntimeException('Deleted Agent source was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0] = $file(51);
    $empty = $guard->authorize([], $task, $attribution, $queued);
    $assert($empty === [['resource_type' => 'sand_ai.task_run', 'resource_ref' => '77', 'authorization_id' => 102]]);
    $guard->revalidate([], $task, $attribution, $queued, $empty);
    $assert(count($host->revalidated) === 3);
    echo 'agent-source-revalidation ' . $checks . '/' . $checks . PHP_EOL;
}
