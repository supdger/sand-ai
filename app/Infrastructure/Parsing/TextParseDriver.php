<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final class TextParseDriver implements StreamingParseDriver
{
    /** One physical line may never accumulate past this bound. */
    private const MAX_LINE_BYTES = 4 * 1024 * 1024;

    private const CHUNK_BYTES = 1024 * 1024;

    /** The tail bytes stay unscanned: they can hold an \R sequence cut by the chunk edge. */
    private const TAIL_BYTES = 8;

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
        $blocks = iterator_to_array($this->streamBlocks($input), false);

        if ($blocks === []) {
            throw new ParseException('SAND_AI_PARSE_EMPTY', 'The text file contains no extractable text');
        }

        return new ParseResult($this->code(), $this->version(), $blocks);
    }

    /** @return \Generator<int, ParsedSourceBlock> */
    public function streamBlocks(ParseInput $input): \Generator
    {
        $stream = @fopen($input->path, 'rb');
        if ($stream === false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
        }
        try {
            $carry = '';
            $lineNo = 0;
            $sequenceNo = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
                }
                $carry .= $chunk;
                $consumed = yield from $this->emitZone($carry, self::zoneLength($carry), $lineNo, $sequenceNo);
                $carry = substr($carry, $consumed);
                if (strlen($carry) > self::MAX_LINE_BYTES) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'A text line exceeds the supported size');
                }
            }
            // Everything left at EOF is safe to scan; the last line keeps its
            // count even without a trailing break, same as preg_split.
            $consumed = yield from $this->emitZone($carry, strlen($carry), $lineNo, $sequenceNo);
            $tail = substr($carry, $consumed);
            if ($tail !== '') {
                yield from $this->lineBlocks($tail, ++$lineNo, $sequenceNo);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Emits the complete lines inside the safe prefix and returns how many
     * bytes were consumed.
     * @return \Generator<int, ParsedSourceBlock, mixed, int>
     */
    private function emitZone(string $carry, int $zoneLength, int &$lineNo, int &$sequenceNo): \Generator
    {
        $zone = substr($carry, 0, $zoneLength);
        $offset = 0;
        // Non-UTF-8 buffers (GB18030/GBK/BIG5/Latin-1) make \u matching fail;
        // their multibyte characters never contain bytes <0x40, so a byte-level
        // scan for the ASCII breaks is still safe.
        $breakPattern = mb_check_encoding($zone, 'UTF-8')
            ? '/\R/u'
            : '/\r\n|[\n\r\x0B\x0C]/';
        while (preg_match($breakPattern, $zone, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $line = substr($zone, $offset, $match[0][1] - $offset);
            $offset = $match[0][1] + strlen($match[0][0]);
            yield from $this->lineBlocks($line, ++$lineNo, $sequenceNo);
        }
        return $offset;
    }

    /** Bytes safe to scan now: a straddling UTF-8 character and a trailing \r that a \n could complete both wait for the next chunk. */
    private function zoneLength(string $carry): int
    {
        $zoneLength = strlen($carry) - self::TAIL_BYTES;
        if ($zoneLength <= 0) {
            return 0;
        }
        $continuations = 0;
        while ($continuations < 3 && $zoneLength - $continuations > 0) {
            $byte = ord($carry[$zoneLength - $continuations - 1]);
            if ($byte < 0x80 || $byte >= 0xC0) {
                break;
            }
            $continuations++;
        }
        if ($continuations > 0 && $zoneLength - $continuations > 0) {
            $lead = ord($carry[$zoneLength - $continuations - 1]);
            if ($lead >= 0xC0) {
                $need = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2);
                if ($continuations + 1 < $need) {
                    $zoneLength -= $continuations + 1;
                } elseif ($continuations + 1 > $need) {
                    $zoneLength -= $continuations + 1 - $need;
                }
            }
        }
        if ($zoneLength > 0 && $carry[$zoneLength - 1] === "\r") {
            // A \r ending the zone may be half of \r\n; emitting it now would
            // surface a phantom empty line when the \n arrives next.
            $zoneLength--;
        }
        return max(0, $zoneLength);
    }

    /** @return \Generator<int, ParsedSourceBlock> */
    private function lineBlocks(string $line, int $lineNo, int &$sequenceNo): \Generator
    {
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'A text line exceeds the supported size');
        }
        $line = $this->utf8($line);
        $content = trim($line);
        if ($content === '') {
            return;
        }
        $offset = mb_strlen($line, 'UTF-8') - mb_strlen(ltrim($line), 'UTF-8');
        foreach (NeuronSourceTextSplitter::split($content, 4096) as $piece) {
            $length = mb_strlen($piece, 'UTF-8');
            // Blank windows advance the real source position but never create an embedding input.
            if (trim($piece) !== '') {
                yield new ParsedSourceBlock(++$sequenceNo, 'line', [
                    'line' => $lineNo, 'char_start' => $offset + 1, 'char_end' => $offset + $length,
                ], $piece);
            }
            $offset += $length;
        }
    }

    private function utf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'UTF-8, GB18030, GBK, BIG-5, ISO-8859-1');
    }
}
