<?php

declare(strict_types=1);

/**
 * Local OCR is an internal-only dependency. The PHP application submits a
 * controlled temporary copy of a private object; neither public URLs nor
 * object-storage credentials are ever sent to the OCR process.
 */
return [
    'driver' => (string) env('SAND_AI_OCR_DRIVER', 'ocr_unconfigured'),
    'local_paddle_ocr' => [
        'base_url' => (string) env('SAND_AI_PADDLE_OCR_BASE_URL', 'http://127.0.0.1:18080'),
        'timeout_ms' => max(1000, (int) env('SAND_AI_PADDLE_OCR_TIMEOUT_MS', 60000)),
        'max_file_bytes' => max(1, (int) env('SAND_AI_PADDLE_OCR_MAX_FILE_BYTES', 52428800)),
        'response_max_bytes' => (int) env('SAND_AI_PADDLE_OCR_RESPONSE_MAX_BYTES', 8388608),
    ],
];
