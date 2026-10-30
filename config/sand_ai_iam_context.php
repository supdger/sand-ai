<?php

declare(strict_types=1);

return [
    'current_key_id' => (string) env('SAND_AI_IAM_CONTEXT_CURRENT_KEY_ID', ''),
    'current_key' => (string) env('SAND_AI_IAM_CONTEXT_CURRENT_KEY', ''),
    'previous_keys_json' => (string) env('SAND_AI_IAM_CONTEXT_PREVIOUS_KEYS_JSON', ''),
];
