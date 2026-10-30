<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use support\think\Model;
use think\model\concern\SoftDelete;

abstract class SandAiModel extends Model
{
    use SoftDelete;

    protected $connection = 'pgsql';

    protected $pk = 'id';

    protected $deleteTime = 'delete_time';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $jsonAssoc = true;

    protected $hidden = ['delete_time'];
}
