<?php

/**
 * SandAI private object storage. Do not place credentials, bucket names, or
 * public domains in source control; production values come from .env.
 */
return [
    'default' => env('SAND_AI_STORAGE_DRIVER', 'local-private'),
    'drivers' => [
        'local-private' => [
            // Deliberately outside public/, so there is no direct local URL.
            'root' => env('SAND_AI_LOCAL_STORAGE_ROOT', runtime_path('sand-ai/private')),
        ],
        'oss-private' => [
            'access_key_id' => env('SAND_AI_OSS_ACCESS_KEY_ID', ''),
            'access_key_secret' => env('SAND_AI_OSS_ACCESS_KEY_SECRET', ''),
            'endpoint' => env('SAND_AI_OSS_ENDPOINT', ''),
            'bucket' => env('SAND_AI_OSS_BUCKET', ''),
            // Relative objects remain portable; this is the physical OSS prefix.
            'prefix' => env('SAND_AI_OSS_PREFIX', ''),
            'sign_ttl' => (int) env('SAND_AI_OSS_SIGN_TTL', 900),
            'max_sign_ttl' => (int) env('SAND_AI_OSS_MAX_SIGN_TTL', 900),
        ],
    ],
];
