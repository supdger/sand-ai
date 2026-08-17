<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class TaskStep extends SandAiPluginModel
{
    protected $table = 'sand_ai_task_step';
    protected $json = ['context'];
}
