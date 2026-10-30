<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final class LocalPaddleOcrResponse
{
    public function normalize(mixed $payload, string $driverCode, string $fallbackVersion): ParseResult
    {
        if (!is_array($payload) || !is_array($payload['blocks'] ?? null) || !array_is_list($payload['blocks'])) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned an invalid response');
        }
        $version = $payload['driver_version'] ?? $fallbackVersion;
        if (!is_string($version) || trim($version) === '' || strlen($version) > 128) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned an invalid driver version');
        }

        $blocks = [];
        foreach ($payload['blocks'] as $remoteBlock) {
            if (!is_array($remoteBlock) || !is_string($remoteBlock['text'] ?? null)) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned an invalid source block');
            }
            $content = trim($remoteBlock['text']);
            if ($content === '') {
                continue;
            }
            $page = $remoteBlock['page'] ?? null;
            if (!is_int($page) || $page < 1) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned a source block without a valid page');
            }
            $locator = ['page' => $page];
            foreach (['x', 'y', 'width', 'height'] as $field) {
                $value = $remoteBlock[$field] ?? 0;
                if (!is_int($value) || $value < 0) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned invalid source coordinates');
                }
                $locator[$field] = $value;
            }
            $confidence = $remoteBlock['confidence'] ?? 0;
            if ((!is_int($confidence) && !is_float($confidence)) || !is_finite((float) $confidence)
                || $confidence < 0 || $confidence > 1) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service returned invalid source confidence');
            }
            $locator['confidence'] = (float) $confidence;
            $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'page_region', $locator, $content);
        }

        return new ParseResult($driverCode, $version, $blocks);
    }
}
