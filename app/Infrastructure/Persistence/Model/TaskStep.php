<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class TaskStep extends SandAiModel
{
    protected $table = 'sand_ai_task_step';

    protected $json = ['context'];
}
