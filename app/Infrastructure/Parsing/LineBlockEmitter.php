<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * UTF-8 码点流 → \R 物理行 → 4096 码点窗口 → ParsedSourceBlock。
 * 与旧算法逐点对应：preg_split('/\R/u') 的分隔符集合、trim/ltrim 的字符
 * 集（0x00/0x09/0x0A/0x0D/0x0B/0x20）、行首空白按码点计入 char 偏移、
 * 纯空白窗口推进偏移但不产出 block、NeuronSourceTextSplitter 产出的
 * 4096 码点连续窗口（split() 的连续覆盖自校验已证明等价）。
 * 长行不整体驻留：只保留一个未满窗口与行尾候选空白；行尾空白溢出时
 * 写 php://temp，内存占用与行长无关。
 */
final class LineBlockEmitter
{
    private const WINDOW_CPS = 4096;

    /** 行尾候选空白超过该字节数后落到 php://temp；空白字符全是 ASCII，1 字节 = 1 码点。 */
    private const WS_MEM_BYTES = 1048576;

    /** 待扫描尾部：分隔符最长 3 字节且可能被 chunk 边切开（\r\n 的 \r 也先扣住）。 */
    private string $tail = '';

    private int $lineNo = 0;
    private int $sequenceNo = 0;
    private int $offset = 0;

    private bool $started = false;
    private string $piece = '';
    private int $pieceCps = 0;
    private string $pendingWs = '';
    /** @var resource|null */
    private mixed $wsSpill = null;
    private int $pendingWsCps = 0;
    private int $wsReadIdx = 0;
    private int $wsReadPos = 0;

    /** @var list<ParsedSourceBlock> */
    private array $blocks = [];

    /** @return list<ParsedSourceBlock> */
    public function feed(string $text, bool $atEnd): array
    {
        $buffer = $this->tail . $text;
        $length = strlen($buffer);
        $i = 0;
        $pos = 0;
        while ($i < $length) {
            $byte = ord($buffer[$i]);
            $sepLength = 0;
            if ($byte === 0x0A || $byte === 0x0B || $byte === 0x0C) {
                $sepLength = 1;
            } elseif ($byte === 0x0D) {
                if ($i + 1 >= $length && !$atEnd) {
                    break;
                }
                $sepLength = ($i + 1 < $length && $buffer[$i + 1] === "\x0A") ? 2 : 1;
            } elseif ($byte === 0xC2) {
                if ($i + 1 >= $length && !$atEnd) {
                    break;
                }
                if ($i + 1 < $length && ord($buffer[$i + 1]) === 0x85) {
                    $sepLength = 2;
                }
            } elseif ($byte === 0xE2) {
                if ($i + 1 >= $length && !$atEnd) {
                    break;
                }
                if (ord($buffer[$i + 1]) === 0x80) {
                    if ($i + 2 >= $length && !$atEnd) {
                        break;
                    }
                    if ($i + 2 < $length) {
                        $thirdByte = ord($buffer[$i + 2]);
                        if ($thirdByte === 0xA8 || $thirdByte === 0xA9) {
                            $sepLength = 3;
                        }
                    }
                }
            }
            if ($sepLength === 0) {
                $i++;
                continue;
            }
            $this->feedLine(substr($buffer, $pos, $i - $pos));
            $this->endLine();
            $i += $sepLength;
            $pos = $i;
        }
        if ($atEnd) {
            $this->feedLine(substr($buffer, $pos));
            $this->endLine();
            $this->tail = '';
        } else {
            // 扫描停点可能是半个分隔符；扫到末尾时尾部可能还是半个
            // UTF-8 字符，都要留给下一次 feed 凑齐再装配。
            $end = $i < $length ? $i : self::charBoundary($buffer);
            $this->feedLine(substr($buffer, $pos, $end - $pos));
            $this->tail = substr($buffer, $end);
        }
        $blocks = $this->blocks;
        $this->blocks = [];
        return $blocks;
    }

    private function feedLine(string $bytes): void
    {
        $length = strlen($bytes);
        $i = 0;
        while ($i < $length) {
            $byte = ord($bytes[$i]);
            if ($byte < 0x80) {
                $this->appendCp($bytes[$i], self::isSpace($byte));
                $i++;
            } else {
                $width = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
                $this->appendCp(substr($bytes, $i, $width), false);
                $i += $width;
            }
        }
    }

    private function appendCp(string $char, bool $isWs): void
    {
        if (!$this->started) {
            if ($isWs) {
                $this->offset++;
                return;
            }
            $this->started = true;
        }
        if ($isWs) {
            $this->pushWs($char);
            return;
        }
        $this->drainWs();
        $this->piece .= $char;
        $this->pieceCps++;
        if ($this->pieceCps === self::WINDOW_CPS) {
            $this->emitPiece();
        }
    }

    /** 行内非空白出现 ⇒ 滞留空白转为行内空白，按码点回填窗口，满窗即落。 */
    private function drainWs(): void
    {
        // 滞留空白可跨过窗口边界：铺满当前窗口后继续铺进后续窗口。
        while ($this->pendingWsCps > 0) {
            $this->piece .= $this->shiftWs();
            $this->pieceCps++;
            $this->pendingWsCps--;
            if ($this->pieceCps === self::WINDOW_CPS) {
                $this->emitPiece();
            }
        }
    }

    private function pushWs(string $char): void
    {
        $this->pendingWsCps++;
        if ($this->wsSpill !== null) {
            fseek($this->wsSpill, 0, SEEK_END);
            fwrite($this->wsSpill, $char);
            return;
        }
        $this->pendingWs .= $char;
        if (strlen($this->pendingWs) >= self::WS_MEM_BYTES) {
            $spill = fopen('php://temp/maxmemory:0', 'w+b');
            if ($spill === false) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'Text whitespace spool could not be opened');
            }
            // 内存段先进溢出流，只写未消费部分；之后读写各用一套游标。
            fwrite($spill, substr($this->pendingWs, $this->wsReadIdx));
            $this->pendingWs = '';
            $this->wsReadIdx = 0;
            $this->wsReadPos = 0;
            $this->wsSpill = $spill;
        }
    }

    private function shiftWs(): string
    {
        if ($this->wsReadIdx >= strlen($this->pendingWs)) {
            if ($this->wsSpill === null) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'Text whitespace spool could not be read');
            }
            fseek($this->wsSpill, $this->wsReadPos);
            $chunk = fread($this->wsSpill, self::WS_MEM_BYTES);
            if (!is_string($chunk) || $chunk === '') {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'Text whitespace spool could not be read');
            }
            $this->wsReadPos += strlen($chunk);
            $this->pendingWs = $chunk;
            $this->wsReadIdx = 0;
        }
        return $this->pendingWs[$this->wsReadIdx++];
    }

    private function emitPiece(): void
    {
        // 与旧算法一致：纯空白窗口跳过 block 但偏移照样推进。
        if (trim($this->piece) !== '') {
            $this->blocks[] = new ParsedSourceBlock(++$this->sequenceNo, 'line', [
                'line' => $this->lineNo + 1,
                'char_start' => $this->offset + 1,
                'char_end' => $this->offset + $this->pieceCps,
            ], $this->piece);
        }
        $this->offset += $this->pieceCps;
        $this->piece = '';
        $this->pieceCps = 0;
    }

    private function endLine(): void
    {
        if ($this->pieceCps > 0) {
            // 未滞留的行尾空白已经不在窗口内，piece 恒以非空白结尾。
            $this->emitPiece();
        }
        if ($this->wsSpill !== null) {
            fclose($this->wsSpill);
            $this->wsSpill = null;
        }
        $this->lineNo++;
        $this->offset = 0;
        $this->started = false;
        $this->pendingWs = '';
        $this->pendingWsCps = 0;
        $this->wsReadIdx = 0;
        $this->wsReadPos = 0;
    }

    private static function isSpace(int $byte): bool
    {
        return $byte === 0x20 || $byte === 0x09 || $byte === 0x0A || $byte === 0x0D || $byte === 0x0B || $byte === 0x00;
    }

    /** 输入保证是完整 UTF-8 字符序列；返回末尾最后一个完整字符的结束位置。 */
    private static function charBoundary(string $buffer): int
    {
        $length = strlen($buffer);
        $continuations = 0;
        while ($continuations < 3
            && $length - 1 - $continuations >= 0
            && (ord($buffer[$length - 1 - $continuations]) & 0xC0) === 0x80) {
            $continuations++;
        }
        $leadPos = $length - 1 - $continuations;
        if ($leadPos < 0) {
            return $length;
        }
        $lead = ord($buffer[$leadPos]);
        $need = $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));
        return $continuations + 1 >= $need ? $length : $leadPos;
    }
}
