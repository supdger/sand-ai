<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

/** An explicit unavailable state is safer than inventing OCR output. */
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
