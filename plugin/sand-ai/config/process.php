<?php

use plugin\SandAi\app\process\TaskWorker;

// The package worker is enabled only in the stand-alone installable shape.
// The main application owns the same task type while both trees coexist here.
if (class_exists('app\\process\\TaskWorker')) {
    return [];
}

return [
    'sand_ai_task_worker' => [
        'handler' => TaskWorker::class,
        'count' => max(1, (int) (getenv('SAND_AI_TASK_WORKER_COUNT') ?: 1)),
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
];
