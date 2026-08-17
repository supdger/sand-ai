<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

final class TextParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'text/plain';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        $contents = file_get_contents($input->path);
        if ($contents === false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
        }

        $contents = $this->utf8($contents);
        $lines = preg_split('/\R/u', $contents) ?: [];
        $blocks = [];
        foreach ($lines as $index => $line) {
            $content = trim($line);
            if ($content !== '') {
                $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'line', ['line' => $index + 1], $content);
            }
        }

        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The text file contains no extractable text');
        }

        return new ParseResult($this->code(), $this->version(), $blocks);
    }

    private function utf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'UTF-8, GB18030, GBK, BIG-5, ISO-8859-1');
    }
}
