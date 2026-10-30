<?php

declare(strict_types=1);

return [
    'channels_json' => (string) env('SAND_AI_FEISHU_CHANNELS_JSON', ''),
    'worker_source_ip' => (string) env('SAND_AI_FEISHU_WORKER_SOURCE_IP', ''),
];
