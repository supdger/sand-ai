<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class TaskRun extends SandAiModel
{
    protected $table = 'sand_ai_task_run';

    protected $json = ['input', 'result'];

    protected $hidden = ['lease_token', 'input'];
}
