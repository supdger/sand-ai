<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use think\facade\Db;

final class PostgresDispatchTransactionPort implements DispatchTransactionPort
{
    public function transaction(callable $callback): mixed
    {
        return Db::connect('pgsql')->transaction($callback);
    }
}
