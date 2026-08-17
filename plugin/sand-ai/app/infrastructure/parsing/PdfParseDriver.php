<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

use Smalot\PdfParser\Parser;
use Throwable;

final class PdfParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'pdf/smalot';
    }

    public function version(): string
    {
        return '2.12';
    }

    public function parse(ParseInput $input): ParseResult
    {
        try {
            $pages = (new Parser())->parseFile($input->path)->getPages();
        } catch (Throwable $exception) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'PDF file could not be parsed', $exception);
        }

        $blocks = [];
        foreach ($pages as $pageIndex => $page) {
            $content = trim(preg_replace('/\s+/u', ' ', $page->getText()) ?? '');
            if ($content !== '') {
                $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'page', ['page' => $pageIndex + 1], $content);
            }
        }

        if ($blocks === []) {
            throw new ParseException('SAND_AI_OCR_REQUIRED', 'The PDF has no extractable text and requires an OCR driver');
        }

        return new ParseResult($this->code(), $this->version(), $blocks);
    }
}
