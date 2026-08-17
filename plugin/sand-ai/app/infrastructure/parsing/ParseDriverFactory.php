<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

final class ParseDriverFactory
{
    public static function forCapabilityDriver(string $driverCode, string $extension): ParseDriver
    {
        return match ($driverCode) {
            'native_document_parse' => self::forExtension($extension),
            'ocr_unconfigured' => throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'No OCR driver has been configured for this environment'),
            default => throw new ParseException('SAND_AI_CAPABILITY_UNSUPPORTED', 'The selected parse driver is not executable'),
        };
    }

    public static function forExtension(string $extension): ParseDriver
    {
        return match (strtolower($extension)) {
            'txt' => new TextParseDriver(),
            'docx' => new DocxParseDriver(),
            'pdf' => new PdfParseDriver(),
            'png', 'jpg', 'jpeg', 'webp' => new ImageParseDriver(),
            default => throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'The file type is not supported by a parser'),
        };
    }
}
