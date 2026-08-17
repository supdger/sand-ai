<?php

/**
 * Private storage configuration for the full plugin. Credentials only come
 * from the host environment; never save them in package SQL or admin DTOs.
 */
return [
    'default' => env('SAND_AI_STORAGE_DRIVER', 'local-private'),
    'drivers' => [
        'local-private' => [
            'root' => env('SAND_AI_LOCAL_STORAGE_ROOT', runtime_path('sand-ai/private')),
        ],
        'oss-private' => [
            'access_key_id' => env('SAND_AI_OSS_ACCESS_KEY_ID', ''),
            'access_key_secret' => env('SAND_AI_OSS_ACCESS_KEY_SECRET', ''),
            'endpoint' => env('SAND_AI_OSS_ENDPOINT', ''),
            'bucket' => env('SAND_AI_OSS_BUCKET', ''),
            'prefix' => env('SAND_AI_OSS_PREFIX', ''),
            'sign_ttl' => (int) env('SAND_AI_OSS_SIGN_TTL', 900),
            'max_sign_ttl' => (int) env('SAND_AI_OSS_MAX_SIGN_TTL', 900),
        ],
    ],
];
