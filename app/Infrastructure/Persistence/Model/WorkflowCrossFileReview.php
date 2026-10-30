<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use support\think\Model;

final class WorkflowCrossFileReview extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'sand_ai_workflow_cross_file_review';

    protected $pk = 'workflow_run_id';

    protected $createTime = false;

    protected $updateTime = 'update_time';
}
