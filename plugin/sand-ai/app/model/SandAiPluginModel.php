<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

use support\think\Model;
use think\model\concern\SoftDelete;

/** Base model for the independently installable PostgreSQL package. */
abstract class SandAiPluginModel extends Model
{
    use SoftDelete;

    protected $connection = 'pgsql';
    protected $pk = 'id';
    protected $deleteTime = 'delete_time';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $hidden = ['delete_time'];
}
