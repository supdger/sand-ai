<?php

/**
 * Limits source-block size independently of upload size. Values are public
 * processing constraints, not credentials.
 */
return [
    // Local PDF text extraction; page/image facts remain in Smalot.
    'pdf' => [
        'pdftotext_path' => (string) env('SAND_AI_PDFTOTEXT_PATH', ''),
        'timeout_seconds' => (int) env('SAND_AI_PDF_READ_TIMEOUT_SECONDS', 60),
    ],
    'max_source_block_characters' => (int) env('SAND_AI_PARSE_MAX_SOURCE_BLOCK_CHARACTERS', 12000),
];
