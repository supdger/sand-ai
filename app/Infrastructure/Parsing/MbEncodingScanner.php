<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * PHP 8.4 各编码 to_wchar 解码器的结构移植（mbfilter_utf8.c / mbfilter_cjk.c）：
 * 只还原字节消耗与坏单元（MBFL_BAD_INPUT）的位置，不查 Unicode 映射表。
 * 坏单元数等价于 mb_check_encoding 的非法序列计数和 mb_guess_encoding
 * 统计的 BAD_INPUT 次数；已决定前缀保证结束在完整编码单元边界上。
 */
final class MbEncodingScanner
{
    /** php-src unicode_table_big5.h big5_ucs_table 非零位图，bit i = 表项 i 非零。 */
    private const BIG5_NONZERO_B64 =
        '////+///////////9f///////8////////////////9//f//////////////////////AAAAAAAAAID/////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////AQAAAAAAAAAAAAAAAAAAAAAAAAD/////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////Hw==';

    /**
     * @return array{0:int,1:int} [已决定前缀字节数, 其中坏单元数]
     * $atEnd=false 时结尾不完整序列不结算，由调用方保留到下一次喂入；
     * $atEnd=true 时按解码器在输入末尾的既有分支结算全部字节。
     */
    public static function scan(string $buffer, string $encoding, bool $atEnd): array
    {
        return match ($encoding) {
            'UTF-8' => self::scanUtf8($buffer, $atEnd),
            'GB18030' => self::scanGb18030($buffer, $atEnd),
            'GBK' => self::scanCp936($buffer, $atEnd),
            'BIG-5' => self::scanBig5($buffer, $atEnd),
            'ISO-8859-1' => [strlen($buffer), 0],
            default => throw new ParseException('SAND_AI_PARSE_FAILED', 'Text encoding is unsupported'),
        };
    }

    private static ?string $big5Nonzero = null;

    /** @return array{0:int,1:int} */
    private static function scanUtf8(string $buffer, bool $atEnd): array
    {
        $length = strlen($buffer);
        $bads = 0;
        $i = 0;
        while ($i < $length) {
            $byte = ord($buffer[$i]);
            if ($byte < 0x80) {
                $i++;
                continue;
            }
            if ($byte < 0xC2 || $byte > 0xF4) {
                $bads++;
                $i++;
                continue;
            }
            if ($byte <= 0xDF) {
                $remain = $length - $i - 1;
                if ($remain === 0) {
                    if (!$atEnd) {
                        break;
                    }
                    $bads++;
                    $i++;
                } elseif ((ord($buffer[$i + 1]) & 0xC0) !== 0x80) {
                    $bads++;
                    $i++;
                } else {
                    $i += 2;
                }
                continue;
            }
            if ($byte <= 0xEF) {
                $remain = $length - $i - 1;
                if ($remain >= 2) {
                    $secondByte = ord($buffer[$i + 1]);
                    $thirdByte = ord($buffer[$i + 2]);
                    if (($secondByte & 0xC0) !== 0x80 || ($byte === 0xE0 && $secondByte < 0xA0) || ($byte === 0xED && $secondByte >= 0xA0)) {
                        $bads++;
                        $i++;
                    } elseif (($thirdByte & 0xC0) !== 0x80) {
                        $bads++;
                        $i += 2;
                    } else {
                        $i += 3;
                    }
                    continue;
                }
                if ($remain === 1) {
                    $secondByte = ord($buffer[$i + 1]);
                    if (($secondByte & 0xC0) !== 0x80 || ($byte === 0xE0 && $secondByte < 0xA0) || ($byte === 0xED && $secondByte >= 0xA0)) {
                        $bads++;
                        $i++;
                        continue;
                    }
                }
                if (!$atEnd) {
                    break;
                }
                // 末尾截断：坏单元一个，吃掉已是合法前缀的续字节。
                $bads++;
                $i = $length;
                continue;
            }
            $remain = $length - $i - 1;
            if ($remain >= 3) {
                $secondByte = ord($buffer[$i + 1]);
                $thirdByte = ord($buffer[$i + 2]);
                $fourthByte = ord($buffer[$i + 3]);
                if (($secondByte & 0xC0) !== 0x80 || ($byte === 0xF0 && $secondByte < 0x90) || ($byte === 0xF4 && $secondByte >= 0x90)) {
                    $bads++;
                    $i++;
                } elseif (($thirdByte & 0xC0) !== 0x80) {
                    $bads++;
                    $i += 2;
                } elseif (($fourthByte & 0xC0) !== 0x80) {
                    $bads++;
                    $i += 3;
                } else {
                    $i += 4;
                }
                continue;
            }
            if ($remain >= 1) {
                $secondByte = ord($buffer[$i + 1]);
                if (($secondByte & 0xC0) !== 0x80 || ($byte === 0xF0 && $secondByte < 0x90) || ($byte === 0xF4 && $secondByte >= 0x90)) {
                    $bads++;
                    $i++;
                    continue;
                }
                if ($remain >= 2 && (ord($buffer[$i + 2]) & 0xC0) !== 0x80) {
                    $bads++;
                    $i += 2;
                    continue;
                }
            }
            if (!$atEnd) {
                break;
            }
            $bads++;
            $i = $length;
        }
        return [$atEnd ? $length : $i, $bads];
    }

    /** @return array{0:int,1:int} */
    private static function scanGb18030(string $buffer, bool $atEnd): array
    {
        $length = strlen($buffer);
        $bads = 0;
        $i = 0;
        while ($i < $length) {
            $byte = ord($buffer[$i]);
            if ($byte < 0x80) {
                $i++;
                continue;
            }
            if ($byte === 0x80 || $byte === 0xFF) {
                $bads++;
                $i++;
                continue;
            }
            if ($i + 1 >= $length) {
                if (!$atEnd) {
                    break;
                }
                $bads++;
                $i++;
                continue;
            }
            $secondByte = ord($buffer[$i + 1]);
            if ((($byte >= 0x81 && $byte <= 0x84) || ($byte >= 0x90 && $byte <= 0xE3)) && $secondByte >= 0x30 && $secondByte <= 0x39) {
                if ($i + 2 >= $length) {
                    if (!$atEnd) {
                        break;
                    }
                    $bads++;
                    $i += 2;
                    continue;
                }
                $thirdByte = ord($buffer[$i + 2]);
                if ($thirdByte >= 0x81 && $thirdByte <= 0xFE) {
                    if ($i + 3 >= $length) {
                        if (!$atEnd) {
                            break;
                        }
                        $bads++;
                        $i += 3;
                        continue;
                    }
                    $fourthByte = ord($buffer[$i + 3]);
                    $good = false;
                    if ($fourthByte >= 0x30 && $fourthByte <= 0x39) {
                        if ($byte >= 0x90) {
                            $index = ((($byte - 0x90) * 10 + ($secondByte - 0x30)) * 126 + ($thirdByte - 0x81)) * 10 + ($fourthByte - 0x30) + 0x10000;
                            $good = $index <= 0x10FFFF;
                        } else {
                            $index = ((($byte - 0x81) * 10 + ($secondByte - 0x30)) * 126 + ($thirdByte - 0x81)) * 10 + ($fourthByte - 0x30);
                            $good = $index <= 39419;
                        }
                    }
                    if (!$good) {
                        $bads++;
                    }
                    $i += 4;
                    continue;
                }
                $bads++;
                $i += 3;
                continue;
            }
            if ((($byte >= 0xAA && $byte <= 0xAF) || ($byte >= 0xF8 && $byte <= 0xFE)) && $secondByte >= 0xA1 && $secondByte <= 0xFE) {
                $i += 2;
                continue;
            }
            if ($byte >= 0xA1 && $byte <= 0xA7 && $secondByte >= 0x40 && $secondByte < 0xA1 && $secondByte !== 0x7F) {
                $i += 2;
                continue;
            }
            if ($secondByte >= 0x40 && $secondByte !== 0x7F && $secondByte !== 0xFF) {
                $index = ($byte - 0x81) * 192 + $secondByte - 0x40;
                $good = false;
                if ($index >= 0x192B) {
                    if ($index <= 0x1EBE) {
                        $good = $index !== 0x1963 && $index !== 0x1DBF && ($index < 0x1E49 || $index > 0x1E55) && $index !== 0x1E7F;
                    } elseif ($index >= 0x413A) {
                        if ($index <= 0x413E) {
                            $good = true;
                        } elseif ($index >= 0x5DD0 && $index <= 0x5E20) {
                            $good = self::gb18030Pua3($index - 0x5DD0);
                        }
                    }
                }
                if (!$good) {
                    $good = ($byte >= 0x81 && $byte <= 0xA9)
                        || ($byte >= 0xB0 && $byte <= 0xF7 && $secondByte >= 0xA1)
                        || ($byte >= 0xAA && $byte <= 0xFE && $secondByte <= 0xA0);
                }
                if (!$good) {
                    $bads++;
                }
                $i += 2;
                continue;
            }
            $bads++;
            $i += 2;
        }
        return [$atEnd ? $length : $i, $bads];
    }

    /** @return array{0:int,1:int} */
    private static function scanCp936(string $buffer, bool $atEnd): array
    {
        $length = strlen($buffer);
        $bads = 0;
        $i = 0;
        while ($i < $length) {
            $byte = ord($buffer[$i]);
            if ($byte < 0x80 || $byte === 0x80 || $byte === 0xFF) {
                $i++;
                continue;
            }
            if ($i + 1 >= $length) {
                if (!$atEnd) {
                    break;
                }
                $bads++;
                $i++;
                continue;
            }
            $secondByte = ord($buffer[$i + 1]);
            if ($secondByte < 0x40 || $secondByte === 0x7F || $secondByte === 0xFF) {
                $bads++;
            }
            $i += 2;
        }
        return [$atEnd ? $length : $i, $bads];
    }

    /** @return array{0:int,1:int} */
    private static function scanBig5(string $buffer, bool $atEnd): array
    {
        $length = strlen($buffer);
        $bads = 0;
        $i = 0;
        while ($i < $length) {
            $byte = ord($buffer[$i]);
            if ($byte <= 0x7F) {
                $i++;
                continue;
            }
            if ($byte > 0xA0 && $byte <= 0xF9) {
                if ($i + 1 >= $length) {
                    if (!$atEnd) {
                        break;
                    }
                    $bads++;
                    $i++;
                    continue;
                }
                $secondByte = ord($buffer[$i + 1]);
                if (($secondByte >= 0x40 && $secondByte <= 0x7E) || ($secondByte >= 0xA1 && $secondByte <= 0xFE)) {
                    $index = ($byte - 0xA1) * 157 + $secondByte - ($secondByte <= 0x7E ? 0x40 : 0x62);
                    if (self::big5Zero($index)) {
                        $bads++;
                        // 解码器对 0xC8 的未映射对只吃掉 lead 字节，trail 重扫。
                        $i += $byte === 0xC8 ? 1 : 2;
                    } else {
                        $i += 2;
                    }
                } else {
                    $bads++;
                    $i += 2;
                }
                continue;
            }
            $bads++;
            $i++;
        }
        return [$atEnd ? $length : $i, $bads];
    }

    private static function gb18030Pua3(int $index): bool
    {
        // gb18030_pua_tbl3 的非零掩码（php-src mbfilter_cjk.c，81 项中 13 项非零）。
        static $mask =
            "\000\001\001\001\000\000\000\000\000\001\000\000\000\000\000\000\000\001\000\000" .
            "\000\000\001\001\000\000\000\000\001\001\000\000\000\000\000\000\000\000\001\000" .
            "\000\000\000\000\000\000\001\000\000\000\000\000\000\000\000\000\000\000\000\000" .
            "\000\000\000\000\001\001\000\000\000\000\000\000\000\000\000\000\000\000\000\000" .
            "\000";
        return $mask[$index] === "\001";
    }

    private static function big5Zero(int $index): bool
    {
        $bits = self::$big5Nonzero ??= base64_decode(self::BIG5_NONZERO_B64, true);
        return ((ord($bits[$index >> 3]) >> ($index & 7)) & 1) === 0;
    }
}
