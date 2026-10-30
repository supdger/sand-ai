<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final class CsvParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'csv/rfc4180';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        $stream = fopen($input->path, 'rb');
        if ($stream === false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'CSV file could not be read');
        }

        try {
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $blocks = [];
            $physicalRow = 1;
            $recordCount = 0;
            while (($before = ftell($stream)) !== false && ($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $after = ftell($stream);
                if ($after === false || $after - $before > 65536) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'A CSV record is too large');
                }
                $recordCount++;
                if ($recordCount > 10000 || count($cells) > 256) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'The CSV table exceeds the supported row or column limit');
                }
                $row = $physicalRow;
                fseek($stream, $before);
                $raw = fread($stream, $after - $before);
                fseek($stream, $after);
                if ($raw === false) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'CSV row location could not be read');
                }
                $physicalRow += max(1, preg_match_all('/\r\n|\n|\r/', $raw));

                $values = [];
                foreach ($cells as $index => $cell) {
                    if ($cell === null || $cell === '') {
                        continue;
                    }
                    if (!mb_check_encoding($cell, 'UTF-8')) {
                        $cell = mb_convert_encoding($cell, 'UTF-8', 'UTF-8, GB18030, GBK, BIG-5, ISO-8859-1');
                    }
                    $cell = trim(preg_replace('/\s+/u', ' ', $cell) ?? '');
                    if ($cell !== '') {
                        $values[$this->column($index) . $row] = $cell;
                    }
                }
                if ($values === []) {
                    continue;
                }
                $blocks[] = new ParsedSourceBlock(
                    count($blocks) + 1,
                    'table_row',
                    ['row' => $row],
                    json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                );
            }
        } finally {
            fclose($stream);
        }

        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The CSV table contains no extractable cells');
        }
        return new ParseResult($this->code(), $this->version(), $blocks);
    }

    private function column(int $index): string
    {
        $label = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $label = chr(65 + ($index - 1) % 26) . $label;
        }
        return $label;
    }
}
