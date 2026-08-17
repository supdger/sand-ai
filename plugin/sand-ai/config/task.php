<?php

return [
    'poll_interval_ms' => (int) env('SAND_AI_TASK_POLL_INTERVAL_MS', 500),
    'worker_batch_size' => (int) env('SAND_AI_TASK_WORKER_BATCH_SIZE', 1),
    'running_stale_seconds' => (int) env('SAND_AI_TASK_RUNNING_STALE_SECONDS', 300),
    'max_attempts' => (int) env('SAND_AI_TASK_MAX_ATTEMPTS', 3),
];
