<?php

declare(strict_types=1);

return [
    'current_key_id' => (string) env('SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID', ''),
    'current_key' => (string) env('SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY', ''),
    'previous_keys_json' => (string) env('SAND_AI_OUTCOME_RECEIPT_PREVIOUS_KEYS_JSON', ''),
    'max_plaintext_bytes' => (int) env('SAND_AI_OUTCOME_RECEIPT_MAX_PLAINTEXT_BYTES', 262144),
    'ttl_seconds' => (int) env('SAND_AI_OUTCOME_RECEIPT_TTL_SECONDS', 86400),
    'artifact_retain_seconds' => (int) env('SAND_AI_OUTCOME_RECEIPT_ARTIFACT_RETAIN_SECONDS', 604800),
    'local_recovery_enabled' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_LOCAL_RECOVERY_ENABLED', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
    'cleanup_enabled' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_CLEANUP_ENABLED', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
    'cleanup_interval_seconds' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_CLEANUP_INTERVAL_SECONDS', 3600), FILTER_VALIDATE_INT),
    'cleanup_batch_size' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_CLEANUP_BATCH_SIZE', 50), FILTER_VALIDATE_INT),
    'cleanup_grace_seconds' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_CLEANUP_GRACE_SECONDS', 3600), FILTER_VALIDATE_INT),
    'cleanup_lease_seconds' => filter_var(env('SAND_AI_OUTCOME_RECEIPT_CLEANUP_LEASE_SECONDS', 300), FILTER_VALIDATE_INT),
];
