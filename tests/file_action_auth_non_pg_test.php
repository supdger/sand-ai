<?php

declare(strict_types=1);

namespace FileActionFixture {
    final class Query
    {
        private array $filters = [];
        public function __construct(private readonly array $rows) {}
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function whereNull(string $field): self { $this->filters[$field] = null; return $this; }
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
    class AiFile
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \FileActionFixture\Query
        {
            return (new \FileActionFixture\Query(self::$rows))->where($field, $value);
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
        if (!$condition) throw new \RuntimeException('File action authorization failed at check ' . ($checks + 1));
        ++$checks;
    };
    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.file.read', 'sand_ai.file.write'], time() + 60,
    );
    $owner = \app\Application\Usage\UsageAttribution::fromIdentity($identity, 'file', 'sand_ai.file.write');
    $file = [
        'id' => 51, 'status' => 1, 'delete_time' => null, 'state' => 'uploaded',
        'environment_id' => 33,
        'owner_scope' => \app\Application\File\FileOwnershipGuard::scope($owner),
        'owner_attribution' => [...$owner->toPersisted(), 'data_class' => 'confidential'],
    ];
    \app\Infrastructure\Persistence\Model\AiFile::$rows = [$file];
    $facts = new \app\Identity\Infrastructure\FileInvocationFacts();
    foreach (['sand_ai.file.read', 'sand_ai.file.write'] as $action) {
        $resolved = $facts->resolve('sand_ai', $action, '51');
        $assert($resolved['resource_ref'] === '51' && $resolved['data_class'] === 'confidential'
            && $resolved['workload_client_id'] === 44);
    }
    $host = new class($facts) {
        public array $calls = [];
        public bool $deny = false;
        public function __construct(private readonly \app\Identity\Infrastructure\FileInvocationFacts $facts) {}
        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ($context !== 'signed-file-context' || $service !== 'sand_ai' || $audience !== 'sand-ai'
                || $resolver !== 'sand_ai.file' || $sourceIp !== '127.0.0.1'
                || $requestId !== 'file-action-1234') throw new \RuntimeException('Wrong SandIAM invocation');
            $facts = $this->facts->resolve($service, $action, $resource);
            $this->calls[] = [$action, $resource];
            return [
                'authorization_id' => count($this->calls), 'grant_id' => 55,
                'context_id' => 'file-context', 'replayed' => false,
                'data_class' => $facts['data_class'], 'operation_id' => $operationId,
                'service_code' => $service, 'audience' => $audience, 'action_code' => $action,
                'organization_id' => $facts['organization_id'],
                'application_id' => $facts['application_id'],
                'environment_id' => $facts['environment_id'],
                'workload_client_id' => $facts['workload_client_id'],
            ];
        }
    };
    $request = new class("GET /api/sand-ai/v1/files/51 HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-file-context\r\nX-Request-ID: file-action-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $authorizer = new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host);
    foreach (['sand_ai.file.read', 'sand_ai.file.write'] as $action) {
        $decision = $authorizer->authorize(
            $request, $identity, $action, 'sand_ai.file', '51',
            'file-operation:' . $action, 'file-action-1234',
        );
        $assert($decision['action_code'] === $action);
    }
    $assert($host->calls === [['sand_ai.file.read', '51'], ['sand_ai.file.write', '51']]);
    $reject = static function (callable $operation) use (&$checks): void {
        try { $operation(); }
        catch (\app\Api\Support\ApiProblem|\app\Identity\Contract\IdentityContextException) {
            ++$checks;
            return;
        }
        throw new \RuntimeException('Invalid file action was authorized');
    };
    $host->deny = true;
    $reject(static fn () => $authorizer->authorize(
        $request, $identity, 'sand_ai.file.read', 'sand_ai.file', '51', 'denied-file-read', 'file-action-1234',
    ));
    $host->deny = false;
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['owner_attribution']['application_id'] = 99;
    $reject(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.read', '51'));
    $reject(static fn () => $authorizer->authorize(
        $request, $identity, 'sand_ai.file.read', 'sand_ai.file', '51', 'changed-owner', 'file-action-1234',
    ));
    echo 'file-action-auth ' . $checks . '/' . $checks . PHP_EOL;
}
