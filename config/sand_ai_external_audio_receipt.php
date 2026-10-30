<?php

declare(strict_types=1);

// Intentionally empty by default: external execution fails before send until operators configure recovery keys.
return [
    'current_key_id' => (string) env('SAND_AI_EXTERNAL_AUDIO_RECEIPT_KEY_ID', ''),
    'current_key' => (string) env('SAND_AI_EXTERNAL_AUDIO_RECEIPT_KEY', ''),
    'previous_keys_json' => (string) env('SAND_AI_EXTERNAL_AUDIO_RECEIPT_PREVIOUS_KEYS_JSON', ''),
    'ttl_seconds' => (int) env('SAND_AI_EXTERNAL_AUDIO_RECEIPT_TTL_SECONDS', 86400),
];
