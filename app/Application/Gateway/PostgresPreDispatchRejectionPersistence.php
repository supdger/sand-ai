<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\PreDispatchRejection;
use think\facade\Db;

/** PostgreSQL adapter for the narrow API 1.47 evidence persistence boundary. */
final class PostgresPreDispatchRejectionPersistence implements PreDispatchRejectionPersistence
{
    public function transaction(callable $write): mixed
    {
        return Db::connect('pgsql')->transaction($write);
    }

    public function lockRequest(string $requestId): void
    {
        Db::connect('pgsql')->query(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 132))',
            ['sand_ai_chat_request:' . $requestId],
        );
    }

    public function findRejection(string $trustedScope, string $requestId): ?array
    {
        $record = PreDispatchRejection::where('trusted_scope', $trustedScope)
            ->where('request_id', $requestId)
            ->lock(true)
            ->find();

        return $record === null ? null : $record->toArray();
    }

    public function findInvocation(string $requestId): ?array
    {
        $record = Invocation::where('request_id', $requestId)->lock(true)->find();

        return $record === null ? null : $record->toArray();
    }

    public function createRejection(array $row): void
    {
        PreDispatchRejection::create($row);
    }
}
