<?php

declare(strict_types=1);

namespace RetrievalFixture {
    final class Query
    {
        private array $filters = [];

        public function __construct(private readonly array $rows)
        {
        }

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
        public static function where(string $field, mixed $value): \RetrievalFixture\Query
        {
            return (new \RetrievalFixture\Query(self::$rows))->where($field, $value);
        }
    }

    class AiFileParse
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \RetrievalFixture\Query
        {
            return (new \RetrievalFixture\Query(self::$rows))->where($field, $value);
        }
    }

    class AiFile
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \RetrievalFixture\Query
        {
            return (new \RetrievalFixture\Query(self::$rows))->where($field, $value);
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) {
        throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR to a Composer autoload file');
    }
    require $vendor;
    $app = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);

    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.retrieval.search'], time() + 60,
    );
    $owner = \app\Application\Usage\UsageAttribution::fromIdentity(
        $identity, 'file', 'sand_ai.file.write',
    );
    $attribution = \app\Application\Usage\UsageAttribution::fromIdentity(
        $identity, 'retrieval', 'sand_ai.retrieval.search', 'retrieval',
    );
    $scope = \app\Application\File\FileOwnershipGuard::scope($owner);
    $file = static fn (int $id, string $dataClass): array => [
        'id' => $id, 'status' => 1, 'delete_time' => null, 'state' => 'parsed',
        'environment_id' => 33, 'owner_scope' => $scope,
        'owner_attribution' => [...$owner->toPersisted(), 'data_class' => $dataClass],
    ];
    \app\Infrastructure\Persistence\Model\AiFile::$rows = [
        $file(51, 'confidential'), $file(52, 'confidential'),
    ];
    \app\Infrastructure\Persistence\Model\AiFileParse::$rows = [
        ['id' => 61, 'file_id' => 51, 'state' => 'succeeded', 'status' => 1, 'delete_time' => null],
        ['id' => 62, 'file_id' => 52, 'state' => 'succeeded', 'status' => 1, 'delete_time' => null],
    ];
    \app\Infrastructure\Persistence\Model\SourceBlock::$rows = [
        ['id' => 71, 'file_id' => 51, 'parse_id' => 61, 'status' => 1, 'delete_time' => null],
        ['id' => 72, 'file_id' => 52, 'parse_id' => 62, 'status' => 1, 'delete_time' => null],
    ];
    $fileFacts = (new \app\Identity\Infrastructure\FileInvocationFacts())->resolve(
        'sand_ai', 'sand_ai.retrieval.search', '51',
    );
    if ($fileFacts['data_class'] !== 'confidential'
        || $fileFacts['resource_ref'] !== '51') {
        throw new \RuntimeException('Retrieval action did not resolve persisted file facts');
    }
    $catalogPort = new class {
        public array $actions = [];
        public function inspectServiceActions(array $service, array $actions): array
        {
            return ['service_code' => $service['code'], 'service_status' => 'missing',
                'action_statuses' => array_fill_keys(array_keys($actions), 'missing'), 'can_register' => true];
        }
        public function registerServiceActions(array $service, array $actions): void
        {
            if ($service['code'] !== 'sand_ai') throw new \RuntimeException('Wrong service code');
            $this->actions = $actions;
        }
    };
    (new \app\Identity\Infrastructure\HostSandIamServiceCatalog($catalogPort))->register();
    if (!isset($catalogPort->actions['sand_ai.retrieval.search'])) {
        throw new \RuntimeException('Retrieval action was absent from the SandIAM catalog declaration');
    }

    $host = new class {
        public array $resources = [];
        public ?string $dataClass = 'confidential';
        public bool $deny = false;

        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId,
            string $requestId,
        ): array {
            if ($this->deny) throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN');
            if ([$context, $service, $audience, $action, $resolver, $sourceIp] !== [
                'signed-retrieval-context', 'sand_ai', 'sand-ai',
                'sand_ai.retrieval.search', 'sand_ai.file', '127.0.0.1',
            ]) throw new \RuntimeException('Wrong resource authorization call');
            $this->resources[] = $resource;
            return [
                'authorization_id' => 100 + count($this->resources), 'operation_id' => $operationId,
                'context_id' => 'retrieval-context', 'organization_id' => 11,
                'application_id' => 22, 'environment_id' => 33,
                'workload_client_id' => 44, 'grant_id' => 55,
                'service_code' => 'sand_ai', 'action_code' => 'sand_ai.retrieval.search',
                'audience' => 'sand-ai', 'data_class' => $this->dataClass,
                'replayed' => false,
            ];
        }
    };
    $request = new class("POST /api/sand-ai/v1/retrieval/search HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-retrieval-context\r\nX-Request-ID: retrieval-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string { return '127.0.0.1'; }
    };
    $guard = new \app\Identity\Infrastructure\RetrievalSourceAccessGuard(
        new \app\Identity\Infrastructure\HostSandIamInvocationAuthorizer($host),
    );
    $matches = [
        ['source_block_id' => 71, 'file_id' => 51],
        ['source_block_id' => 72, 'file_id' => 52],
    ];
    $guard->authorize($request, $identity, $attribution, $matches, 'retrieval-request-1234');
    if ($host->resources !== ['51', '52']) throw new \RuntimeException('Not every source file was authorized');

    $reject = static function (callable $operation): void {
        try {
            $operation();
        } catch (\app\Api\Support\ApiProblem $error) {
            return;
        }
        throw new \RuntimeException('Unauthorized retrieval was accepted');
    };
    $count = count($host->resources);
    $reject(static fn () => $guard->authorize(
        $request, $identity, $attribution,
        [['source_block_id' => 71, 'file_id' => 52]], 'retrieval-request-1234',
    ));
    if (count($host->resources) !== $count) throw new \RuntimeException('Forged mapping reached SandIAM');

    \app\Infrastructure\Persistence\Model\AiFile::$rows[1]['owner_attribution']['data_class'] = 'restricted';
    $reject(static fn () => $guard->authorize(
        $request, $identity, $attribution, $matches, 'retrieval-request-1234',
    ));
    if (count($host->resources) !== $count) throw new \RuntimeException('Mixed classes reached SandIAM');
    \app\Infrastructure\Persistence\Model\AiFile::$rows[1]['owner_attribution']['data_class'] = 'confidential';

    $host->deny = true;
    $reject(static fn () => $guard->authorize(
        $request, $identity, $attribution, $matches, 'retrieval-request-1234',
    ));
    $host->deny = false;
    $host->dataClass = 'restricted';
    $reject(static fn () => $guard->authorize(
        $request, $identity, $attribution, $matches, 'retrieval-request-1234',
    ));
    $host->dataClass = 'confidential';
    $count = count($host->resources);
    $guard->authorize($request, $identity, $attribution, [], 'retrieval-request-1234');
    if (count($host->resources) !== $count) throw new \RuntimeException('Empty result consumed file authorization');
    echo "retrieval source authorization non-PG checks passed\n";
}
