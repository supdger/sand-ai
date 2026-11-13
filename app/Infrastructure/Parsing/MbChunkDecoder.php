<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * 把字节流按旧算法的单一源编码增量转成完整 UTF-8 字符。
 * mb_convert_encoding 每次调用都会重置内部 filter 状态，跨 chunk 的半个
 * 编码单元必须先留作 carry；decided 前缀只包含完整单元，逐段转换与旧算法
 * 一次性转换整份文件产出完全一致（包括坏单元的替代符）。
 */
final class MbChunkDecoder
{
    private string $carry = '';

    public function __construct(
        private readonly string $encoding,
        private readonly bool $rawUtf8,
    ) {
    }

    /** $atEnd=true 时把残余的截断单元交给 filter 收尾，复现整文件转换的末尾替代符。 */
    public function feed(string $chunk, bool $atEnd): string
    {
        if ($this->rawUtf8) {
            return $chunk;
        }
        $buffer = $this->carry . $chunk;
        $decided = $this->unitEnd($buffer);
        $this->carry = substr($buffer, $decided);
        $out = '';
        if ($decided > 0) {
            $out = mb_convert_encoding(substr($buffer, 0, $decided), 'UTF-8', $this->encoding);
        }
        if ($atEnd && $this->carry !== '') {
            $out .= mb_convert_encoding($this->carry, 'UTF-8', $this->encoding);
            $this->carry = '';
        }
        return $out;
    }

    /** 已决定前缀：filter 最后一个完整单元的结束位置（与 mbfl filter 的逐字节状态机同构）。 */
    private function unitEnd(string $buffer): int
    {
        return match ($this->encoding) {
            'GB18030' => self::unitEndGb18030($buffer),
            'GBK' => self::unitEndGbk($buffer),
            'BIG-5' => self::unitEndBig5($buffer),
            default => strlen($buffer),
        };
    }

    private static function unitEndGb18030(string $buffer): int
    {
        $length = strlen($buffer);
        $last = 0;
        $status = 0;
        $leadByte = 0;
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($buffer[$i]);
            if ($status === 0) {
                if ($byte < 0x80 || $byte === 0x80 || $byte === 0xFF) {
                    $last = $i + 1;
                } else {
                    $status = 1;
                    $leadByte = $byte;
                }
            } elseif ($status === 1) {
                if ((($leadByte >= 0x81 && $leadByte <= 0x84) || ($leadByte >= 0x90 && $leadByte <= 0xE3)) && $byte >= 0x30 && $byte <= 0x39) {
                    $status = 2;
                } else {
                    $status = 0;
                    $last = $i + 1;
                }
            } elseif ($status === 2) {
                if ($byte >= 0x81 && $byte <= 0xFE) {
                    $status = 3;
                } else {
                    $status = 0;
                    $last = $i + 1;
                }
            } else {
                $status = 0;
                $last = $i + 1;
            }
        }
        return $last;
    }

    private static function unitEndGbk(string $buffer): int
    {
        $length = strlen($buffer);
        if ($length === 0) {
            return 0;
        }
        // cp936 filter：奇偶状态，只有 lead(0x81-0xFE) 等待第二字节时才算未完结。
        // 末尾是 pending lead 等价于「去掉最后一字节后仍落在单元边界」，
        // 需要回扫连续 lead 段的奇偶性。
        $last = $length;
        $i = $length - 1;
        while ($i >= 0 && ord($buffer[$i]) >= 0x81 && ord($buffer[$i]) <= 0xFE) {
            $i--;
        }
        // $i 现在是最后一段 lead 之前的位置；lead 段从 $i+1 开始。
        // 段内每两字节一个单元；若段长为奇数，则末尾 lead 未完结。
        $run = $length - 1 - $i;
        if ($run > 0 && $run % 2 === 1) {
            $last = $length - 1;
        }
        return $last;
    }

    private static function unitEndBig5(string $buffer): int
    {
        $length = strlen($buffer);
        if ($length === 0) {
            return 0;
        }
        // big5 filter：lead 为 0xA1-0xF9 且非 0xC8；0xC8 等其它高位字节是独立坏单元。
        $i = $length - 1;
        $last = $length;
        if (self::isBig5Lead(ord($buffer[$i]))) {
            // 回扫连续 lead 段判奇偶；非 lead 字节永远是单元边界。
            while ($i >= 0 && self::isBig5Lead(ord($buffer[$i]))) {
                $i--;
            }
            $run = $length - 1 - $i;
            if ($run % 2 === 1) {
                $last = $length - 1;
            }
        }
        return $last;
    }

    private static function isBig5Lead(int $byte): bool
    {
        return $byte > 0xA0 && $byte <= 0xF9 && $byte !== 0xC8;
    }
}
