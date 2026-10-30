<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\TextFileReader;

final class TextParseDriver implements ParseDriver
{
    public function code(): string
    {
        return 'text/plain';
    }

    public function version(): string
    {
        return '2.0';
    }

    public function parse(ParseInput $input): ParseResult
    {
        try {
            $contents = (new TextFileReader())->read($input->path);
        } catch (DataReaderException $exception) {
            // SDK errors contain the private path; keep only SandAI's safe public error.
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read', $exception);
        }

        $contents = $this->utf8($contents);
        $lines = preg_split('/\R/u', $contents) ?: [];
        $blocks = [];
        foreach ($lines as $index => $line) {
            $content = trim($line);
            if ($content === '') {
                continue;
            }
            $offset = mb_strlen($line, 'UTF-8') - mb_strlen(ltrim($line), 'UTF-8');
            foreach (NeuronSourceTextSplitter::split($content, 4096) as $piece) {
                $length = mb_strlen($piece, 'UTF-8');
                // Blank windows advance the real source position but never create an embedding input.
                if (trim($piece) !== '') {
                    $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'line', [
                        'line' => $index + 1, 'char_start' => $offset + 1, 'char_end' => $offset + $length,
                    ], $piece);
                }
                $offset += $length;
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
