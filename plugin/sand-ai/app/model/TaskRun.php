<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class TaskRun extends SandAiPluginModel
{
    protected $table = 'sand_ai_task_run';
    protected $json = ['input', 'result'];
}
