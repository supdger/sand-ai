<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model {
    #[\AllowDynamicProperties]
    final class AiFile
    {
        public static array $rows = [];
        public function __construct(array $row)
        {
            foreach ($row as $field => $value) {
                $this->$field = $value;
            }
        }
        public static function where(string $field, mixed $value): FileQuery
        {
            return (new FileQuery())->where($field, $value);
        }
    }

    final class FileQuery
    {
        private array $filters = [];
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
        public function find(): ?AiFile
        {
            foreach (AiFile::$rows as $row) {
                foreach ($this->filters as $field => $value) {
                    if (($row[$field] ?? null) !== $value) continue 2;
                }
                return new AiFile($row);
            }
            return null;
        }
    }
}

namespace {
    $root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    });
    require getenv('SANDPACKAGE_TEST_FACTS') ?: $root . '/app/Identity/Infrastructure/FileInvocationFacts.php';
    require getenv('SANDPACKAGE_TEST_GATEWAY') ?: $root . '/app/Application/File/FileGateway.php';

    $identity = new \app\Identity\Contract\IdentityContext(
        '11', '22', 33, '44', 'sand-ai', ['sand_ai.file.read', 'sand_ai.file.write'], time() + 60,
    );
    $owner = \app\Application\Usage\UsageAttribution::fromIdentity($identity, 'file', 'sand_ai.file.write');
    $scope = \app\Application\File\FileOwnershipGuard::scope($owner);
    $row = [
        'id' => 51, 'status' => 1, 'delete_time' => null, 'state' => 'uploaded',
        'environment_id' => 33, 'owner_scope' => $scope,
        'owner_attribution' => [...$owner->toPersisted(), 'data_class' => 'confidential'],
    ];
    \app\Infrastructure\Persistence\Model\AiFile::$rows = [$row];
    $facts = new \app\Identity\Infrastructure\FileInvocationFacts();
    $visible = new \ReflectionMethod(\app\Application\File\FileGateway::class, 'fileForEnvironment');
    $gateway = new \app\Application\File\FileGateway();
    $checks = 0;
    $allow = static function (string $action) use ($facts, &$checks): void {
        if ($facts->resolve('sand_ai', $action, '51')['resource_ref'] !== '51') {
            throw new \RuntimeException('Current file facts did not resolve');
        }
        ++$checks;
    };
    $deny = static function (callable $operation) use (&$checks): void {
        try {
            $operation();
        } catch (\app\Api\Support\ApiProblem|\app\Identity\Contract\IdentityContextException) {
            ++$checks;
            return;
        }
        throw new \RuntimeException('Revoked file was readable or deletion recovery was unrestricted');
    };
    $allow('sand_ai.file.read');
    $allow('sand_ai.file.write');
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['state'] = 'delete_failed';
    $allow('sand_ai.file.write');
    $deny(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.read', '51'));
    $deny(static fn () => $visible->invoke($gateway, 33, 51, $scope));
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['state'] = 'deleting';
    $allow('sand_ai.file.write');
    $deny(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.read', '51'));
    $deny(static fn () => $visible->invoke($gateway, 33, 51, $scope));
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['state'] = 'parsed';
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['status'] = 2;
    $deny(static fn () => $visible->invoke($gateway, 33, 51, $scope));
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['status'] = 1;
    \app\Infrastructure\Persistence\Model\AiFile::$rows[0]['owner_attribution']['application_id'] = 99;
    $deny(static fn () => $facts->resolve('sand_ai', 'sand_ai.file.write', '51'));
    echo "file-delete-recovery {$checks}/10\n";
}
