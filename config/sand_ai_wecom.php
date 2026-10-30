<?php

declare(strict_types=1);

return [
    'channels_json' => (string) env('SAND_AI_WECOM_CHANNELS_JSON', ''),
    'worker_source_ip' => (string) env('SAND_AI_WECOM_WORKER_SOURCE_IP', ''),
];
