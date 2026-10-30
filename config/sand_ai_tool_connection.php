<?php

declare(strict_types=1);

return [
    'current_key_id' => (string) env('SAND_AI_TOOL_CONNECTION_CURRENT_KEY_ID', ''),
    'current_key' => (string) env('SAND_AI_TOOL_CONNECTION_CURRENT_KEY', ''),
    'previous_keys_json' => (string) env('SAND_AI_TOOL_CONNECTION_PREVIOUS_KEYS_JSON', ''),
    'idempotency_hmac_key' => (string) env('SAND_AI_TOOL_CONNECTION_IDEMPOTENCY_HMAC_KEY', ''),
    // Upgrade-only read seam. New revisions always use the dedicated keyring.
    'legacy_config_key' => (string) env('SAND_AI_CONFIG_KEY', ''),
];
