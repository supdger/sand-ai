<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * The image path is deliberately explicit: a later OCR provider plugs in here.
 * Returning guessed text would be unsafe for legal or evidence-oriented files.
 */
final class ImageParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'image/ocr';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'Image OCR is not configured');
    }
}
