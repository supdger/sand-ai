<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final class PdfOcrCoveragePolicy
{
    /** @param list<int> $requiredSourcePages */
    public function assert(array $requiredSourcePages, ?int $pageCount, ParseResult $result): void
    {
        if ($requiredSourcePages === [] || $pageCount === null || $pageCount < 1) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'PDF OCR page requirements are unavailable');
        }
        $covered = [];
        foreach ($result->blocks as $block) {
            if (trim($block->content) === '') {
                continue;
            }
            $page = $block->locator['page'] ?? null;
            if (!is_int($page) || $page < 1 || $page > $pageCount) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'PDF OCR returned a source outside the document pages');
            }
            $covered[$page] = true;
        }
        foreach ($requiredSourcePages as $page) {
            if (!is_int($page) || $page < 1 || $page > $pageCount || !isset($covered[$page])) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'PDF OCR did not cover every source-bearing page');
            }
        }
    }
}
