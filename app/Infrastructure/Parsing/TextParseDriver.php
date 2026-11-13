<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final class TextParseDriver implements StreamingParseDriver
{
    private const CHUNK_BYTES = 1024 * 1024;

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
        // 第一遍定全文件源编码，与旧算法对整份字节串做一次
        // mb_check_encoding/mb_convert_encoding 的语义一一对应：
        // UTF-8 合法则不转换，否则按候选顺序的启发评分选源编码。
        [$encoding, $rawUtf8] = MbEncodingGuess::guess($input->path);
        if ($encoding === null) {
            // strict_detection 下全部候选被淘汰：旧算法得到
            // mb_convert_encoding 的 false，等同于无可解析内容。
            return;
        }

        $stream = @fopen($input->path, 'rb');
        if ($stream === false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
        }
        try {
            // 第二遍按已定的单一源编码逐单元转换，再对解码后的
            // UTF-8 码点流做 \R 分行与 4096 码点窗口切块。
            $decoder = new MbChunkDecoder($encoding, $rawUtf8);
            $emitter = new LineBlockEmitter();
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
                }
                yield from $emitter->feed($decoder->feed($chunk, false), false);
            }
            yield from $emitter->feed($decoder->feed('', true), true);
        } finally {
            fclose($stream);
        }
    }
}
