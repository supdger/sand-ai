<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use support\think\Model;

/** Append-only SandAI evidence records have no soft-delete or update fields. */
abstract class ImmutableSandAiModel extends Model
{
    protected $connection = 'pgsql';

    protected $pk = 'id';

    protected $createTime = 'create_time';

    protected $updateTime = false;
}
