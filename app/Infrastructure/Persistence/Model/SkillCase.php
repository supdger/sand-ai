<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use support\think\Model;

final class SkillCase extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'sand_ai_skill_case';
    protected $pk = 'id';
    protected $createTime = 'create_time';
    protected $updateTime = false;
    protected $json = ['consumer_context', 'input'];
    protected $jsonAssoc = true;
}
