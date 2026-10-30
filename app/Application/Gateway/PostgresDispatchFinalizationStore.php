<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use think\facade\Db;

/** PostgreSQL finalization boundary; callbacks lock rows in the documented order. */
final class PostgresDispatchFinalizationStore implements DispatchFinalizationStore
{
    public function finalize(callable $write): mixed
    {
        return Db::connect('pgsql')->transaction($write);
    }
}
