<?php

return [
    'max_bytes' => (int) env('SAND_AI_FILE_MAX_BYTES', 50 * 1024 * 1024),
    'allowed' => [
        'pdf' => ['application/pdf'],
        // DOCX is a ZIP container; finfo commonly reports application/zip.
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
        // XLSX is a ZIP container; finfo may report application/zip.
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4'],
        'ogg' => ['audio/ogg'],
        'wav' => ['audio/wav'],
        'webm' => ['audio/webm'],
        'flac' => ['audio/flac'],
    ],
];
