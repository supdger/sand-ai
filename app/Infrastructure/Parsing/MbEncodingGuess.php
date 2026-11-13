<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * mb_guess_encoding（PHP 8.4，mbstring.c）的增量复刻：mb_convert_encoding
 * 拿到多个候选编码时按 demerit 评分选源编码——每解码出一个合法码点按
 * rare 位图记 1/6/30/40 分，每个坏单元记 1000 分，编码顺序再乘权重
 * 1.0+0.3i/n，总分最低者胜出。这里对字节流逐块累计同样的分数，
 * 与对整文件调用 mb_detect_encoding（strict 跟随
 * mbstring.strict_detection）的结果一致。
 */
final class MbEncodingGuess
{
    private const CHUNK_BYTES = 1024 * 1024;

    /** 与旧算法 utf8() 相同的候选顺序。 */
    private const CANDIDATES = ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'ISO-8859-1'];

    /** php-src rare_cp_bitvec.h，U+0000-U+FFFF 每码点一 bit，置位 = rare。 */    private const RARE_BITS_B64 =
        '/9n//wAAAAAAAAAAAAAAgP////8BIAAAAAAAAAAAAAAND/dw//f8/2He/HDMN/yB////////////////////////////////////' .
        '////////////////////////////APj///////////////8AAw8oAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' .
        'AAAAAAAAAAAAAP///////////////////////wEAAAAAAAD/AAAAeOD/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD/' .
        '////////////////////////////////////////////////////////////////////////////////////AAAAAAAAAAAAAAAA' .
        'AAAAAP//////////////////////////////////////////////////////////////////////////////////////////////' .
        '/////////////////////////////////////////////////////////////////////////////////////////////////wEA' .
        'AAAAAAB4AAAA/v//////////////////////////////////////////////////////////////////////////////////////' .
        '/////////////////////////wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '///////////////////////////////////////////////////////////////////3gAC4f+O+//////////8A/P///+///f//' .
        '/////////////////////wAAAAD/////AAAAAAAAAAAAAAAAAAD///v/////////////////////////////////////////////' .
        '///////////////////////////////////////////////////////////////////////////////+////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////AAAAAP////8BAAAAAAAAAAAAgOEAAAAAAAAAAAAAAAD///////8B' .
        'AAAAAAAAAAAAAID/////////////////////////////////////////////////////////////AAAAAAAAAAAAAAAAAAAAAAAA' .
        'AAAAAAAAAAAAAAAAAAD/////////////////////////////////////////////////////////////////////////////////' .
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
        '////////////////////////////dJCEAAnT6pDXBCIcvP/0v7skySIJhNt7HBMF/MerOlf9G3wYqnuuyrcf1GH2bddvp1a/1xQu' .
        'n3/je34e/BzVur9V6Xil0f+pfy3f69//D1/e3/3898xTt99f3ftf39/16v37fv37+2uExOjrW4QExJ7IW+XKbxL3rkT9x6xftFA0' .
        '+nnQzrA+0HODrddHedp/d9j9F/Tw4T1V9dsUa//bMpz+H/30O/0xdDgB6ih3RHaL5d96m57zd/OALXSCADDMpQhUFz4EhaSy+F7m' .
        '+/7Vxq4nivk/avHeLJx5Xx2Y+8sv/e7/bi99v6d79z8N6//uYvHvyH+jM9cfv6NrpX73376cL3b/Pah3/79379por/x/P9f/9/5v' .
        '7/v7/bPy2+Cbt1azfnfe9rt6G3wTrHt//+P3+/+zt///97fvf/7/fvma/93/7XvM//zrbbr79d+91/t3Lf+8979969U+7zWw229d' .
        'n2t6kW24azQM+1q6p9wj+rOd377/tb/RZOuf5v93Vf/3Zt5/re+//f/7u9sPf///+/v///Pf+e2v/7X9//f/5f//+/v+/v//Tkin' .
        '9d7eVWR/AKCzhh0L7/IXGaSeICkQrdpv720ImBDdr8hv/X35/2zz/c33/v////283r7Xf78v9//d/9//5/7///3//+/////////r' .
        '39/rnxG0YbeR7p7CHxc23t7vz3/x96MAJXtGKT/XH/7x+Ldb8u83e+B7RnYefV2p/65Tn5vBfOXM4nC8/bln3ZdPO+x8991f2L+L' .
        'zxx6//e319r5w0iWY8/dr3u2aIvm3/PdajwEpPH9fN9jZ/d/f++m3fpztj7/+7Ps+2/ku797+Pr/v/8bKv4KuHq/OyJ2fJTB9ncF' .
        'tC3u+RGiyebdTRuEIkAQTGUPef29UcRy/xubHd4vcb4/8+ZsuvRQ+HP7/l268/WZ79vYXyav/8+/uyv7635/ff/0c/7Qv8+F2i5J' .
        'k+u7vu/oo75/68zDY780324dlLPvSv5zpVRtT01+zd13f/8azD37Oyvnf15e/6/zZZPbrzyuu2n8Pf87tu//P/2/zb7v4iz21E9X' .
        'P0LN27I9vC/cXvw/7f1ey/JTP//vf+bv32/Fd092Nf/9+X/Mk+7kb5P8v3/d/3dfO17W/+7VW3p+v9n735/77//f/v//++e7/t5f' .
        'z6v+/+//vu/3/9f+9v//99/5+/e99fnd+O/3/3z59/3//v/u//vv//f3///73///9+/7v/9g//ubf+32fwF54+S38b/+/X9LY5wX' .
        'Q/X9d////34ldzM2b/54/V8T7Pu6mfHssnUZs/cE7enft9H61sf993u33/u7hw0z8em/uW/+26zfdSV82+6u57367bNV8V+QZtWk' .
        'Teu659u/9v9//z/W/b7P23O/+0/nzZvNbOs+uX3n//Lh8T59/+1mr5/+/66+f+/t//X/9P/bfu93/bu/dd1T/+//v//v3f73V54P' .
        '/7mb/f8H98j5fv90L8f/3f/93/i9/77v/63/rU+9/9/ve+t9/+/9vff+232f3/+vv9/+9//LHZg/7v91ef3b2fz7//96b7/96v56' .
        'vfyN/cz/v7dH/7GvP8+/f//3/tr2b/X99/91+vP62/3/3t//v5d9/ve7+4fv///fIef5vr939f6/d//+3/97/++zvz//7///atPU' .
        'UPLfb+3N2/Pm/j87ffnlN9utX/nL9Pv77v3/1/d/qfH/vvvvu3/bt/mDK/a1+/+//XufOigmZzvrrv3k/3/+fD+q/H/8p7Xn/x3P' .
        'v/s/X+n2Z///f+PTFbd/+c+P////6r7e/9/f/6f///2NfX5//fSr3fPe+/9+f/v9v//9v/f/q/z8vx/eXn96zB///z+7/RH01fl+' .
        'L33+1t6X/3v2v3FHug9///v1L3nl3zFOnzn/e0/zvy3WX795t/bt9b7u/+u/VajX//9fvX5Xd//91/3/bv3//+29ff9/7///fff/' .
        'HvfP//3+7/fX//V7dk+73b3/+58tf///ts7TbvF4d/b5q+/ev7m+kf39f/fp+t/pEv9z+Zr2bO/37f7/97e3fZ+1+8+7/Zn2zftP' .
        'v+8qaxFYBxrRAFMRRk6OvYfa7pmu2v//XPv5uVr/3arvu+39+93dr+t+/v3+5ZTPXv///7dWu+Ovuv97Ih8X5b/SyDnrO7Svv6tN' .
        '/6Tam3i0+/939bz/s8T/n7f2MP57/8P+/Pdbut33fvfa5/cfiGsv/+sc/X////y/f3C7+/f7zb9P9dx/X203n3/9/cO//+9/5pv8' .
        'tvnq/nV3f+//ub////d52X3r9+//ffn/54v/3w/u/9+//X/3tefd//v3/+3///vu7/X337+v/////3X71c9fz/f59///2++/b//v' .
        '979h////Xd7fz/3/9T/z/f//3/9+/z/MI3/3/53469///////37T/3Xvv/+1+v5b7v3/f39/vV/9vv+v/tPf/7/7///7vvf7///v' .
        '+7Xf/9//+/+9957PZ9HV5n79zde77//X/f/Ze8/M7f/n3Hv/+vvf+7v37/+/f/97+389d7f7f/t324j8+f+3///++rpXVtfbpev/' .
        '/863sJXw7V/N176Eyvptf/e75z+Xdbrou2/V/O7/vf99/+q/b2rf/vv3/8kYMgiEWF2QEEVIktQS0GGjzG3/vbr++/nj//3/7/ef' .
        'eGAnRmL696egy+/L31q3FwEATCDhJ6ayoPcO/93z3333/ue/5+f9U+16V/v/af6f+/nN/+vi/f2vz/t7fVv//91j+/v+w/t/+///' .
        'lvf9w9f/ef82j53/fz8s6keH/BQktxT4iUm+7ASb037csFliEfK/2aZZE0at1p1bSuR79f+zO930/bv93/3eX9/7/fv9f9/+///F' .
        '69/Tqx1+f/X7r//n//58AHzO9//f//+dz7/3/++/7v9/9/v/7f/W/73u/893/R3/+//v2/fu7//++rom+///1//3///fv//7r///' .
        'v/////7/fvn////////+/7/b/u//+f//9/pP//h3HRi91bfUv/7P+5+7uszm/vmBP9/3fv+d7/9/Pn/29eX+0/z59f/rn+79GkvQ' .
        '3L3sbf+7gXcOxt7XwfgW2p8zzuR2+i22DE+dpfN6Mrcumbevf//7/Pv+u634/af/31f//v7f////5/fvP+2Xd5HqcO7t3/7kvw/P' .
        'tn93HQEJyDTK7v73/++//zb7X/uP1+n2//3+///f9/u/348Ya5/2u/3/juX/jf/a1//f/X//v/P9//9+v97/X3PbD6QlK299+81+' .
        'f+7Pf+f+v/v//7//9/9b//3vyX/v+/9///////3//7/////5/////v/////2///f+////X//////7/3f/+/v/v/+58+/v9/95///' .
        '/f/3v/7/d////d/97/v//3////9/7////3///////f///zOUX/cf13n//6//1l3//3d/n///x+c/6ZT/fv///7/3//7//7f/8/r/' .
        'vf/7f+/W/vn/////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '//////////////////////////////////////9s/ADB7k/8/P7X7/7//2z+hOHub/xo/sTt73//bGqUz+7v/e7/38/ub/3+///P' .
        'hk/57P7v7////+7+9u/u//9s/NTv////bP6Ui+5P3Oz+x8////////+P7k/87v/f7/5/3v7//8/uT/H8/9/P///97v/e7////+x+' .
        'lO/v//zu/+/v7u///v//7+pH3f///8/uT/1o/ATF7k/87P7e/////+TyxMfuT/zs/szu////7PrU4/7//v///+/u7//8/t/P5k/9' .
        '/v/v7////+7+/u/v7/3s+Jzr////7P7UiwZHcez+xO////////+PZk+17P7U7+7//O7//8+mTtXu/v/v///+7v707////+z+1O//' .
        '//7+///v73/9/v7+z6ZP/f7//8/mT/js/sTn7m/8/////////+z2x+fub/z+////////7P7f7/////7//+/////////P7/f9////' .
        '/////+7+/P////9s/vTv7s//7v7ez+5POOz+xM/+f/3////P7k987P7Uz+5P/O7//c/uT/3u////////7v7+7+7//ez+1O////7/' .
        '///P7v//7P7ez+5Pvf///8/uT/0s/JTF7k/4/v7f/////+z+lMfuT/zs/kfv////5P7U7////////+/vf//u//+Pxk9d7v71////' .
        '/+7+/+/u7//u/vb/////bP5EjUZP3ez+xM3+3//////P5k/5bP7Ez+5f3P7//4/uT/3+/+/v///+7v787/7//2z81O3////+///v' .
        '7///7v7+z+7//////8+uT/Hs+sbH7m/8/v/+/////2z+xu////38/9b/////7v/c///////////////+/9/P7u//////////////' .
        '/+/////u/vz/////7P/XT45P3Oz+xM/uT/3+//+PJk+87P7E7+5v/O7+/4/uT93u/9/v////7v/3z+5P/Wz+VOn///7u/t7v7k/9' .
        '/v7Wz85P/f///8/mR73k/szH7u/9///f/////+z+ze/u/////////v//bP7d7/////7//+/+7//+///P7u/9/v//7////+7/////' .
        '//3sfvzv////7P7Uz6hHnOz+xM9uT13u///PxA+o7P7Ujy4PHO7+5s+ORvXs/sfP/u/97P7+z+5P/ez+1M/uz/zs/v3P7k/97P5U' .
        'z+5LAe7+/s/OR7gs/oTP7k/8rv/f7/7//+z6lM/ub/3u/s7v////7P4U5/9//f7/3+/u7//u/9/P7k/9/v/v7////+z+/u/u7//s' .
        '/tz/////bPqUyWpP/Oz/xO/////////P7s/9/v//7////v///8/uf/X8////////7v7+/////ez+3O///////////////v//7/9v' .
        '/////8/uz3ms/oTP7k/87v7f/////+z+xM/ub/3u/+/v////7P7W7+7//////+/v///+///P7k/9/v/v7/////7/9e/v//3s/t7/' .
        '////7PzUz+5P/ez+xO/////////P5k/87P7U7+5P/v7//8/uT/3s/t/v///9/v7/7////+z+1O/u//3+/9/P7m///v7+z+7P/f//' .
        '/8/uT/3s/MTP7k/9/v///////+z+xM/uT/3u/+/v/v//7P7U6/////7//+//f//+///P7m/9/v/v7////+7+3+/v7/1s+vTv/v//' .
        '7P7cj65P3Oz+xM/////////P7m/87P7U7+7P/P7//8/ub/3+////////7v//7/5//+z61u////3////v/v///v7e7+7P/////8/u' .
        'T/3s3tTP7k/8/v/f/////+z+1M/uT/3s/sTv////7P7Uze5//fz/98/uf/3+///P7m397v7+z+7//ez+9O/u7/1s/Nzt7s//7P70' .
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
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////////////////////////////////////////////////////////////////' .
        '////////////////////////////////////////APz/////////////////////////////////////AQAAAAAAAAAAAAAAAAAA' .
        'AAAAAAD//////////9////8=';

    /** @var list<int>|null */
    private static ?array $rareBits = null;

    /**
     * 返回 [源编码, 是否合法 UTF-8]。源编码 null 表示 strict_detection 下
     * 全部候选被淘汰（旧算法会得到 mb_convert_encoding 的 null 返回值）。
     * @return array{0:?string,1:bool}
     */
    public static function guess(string $path): array
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
        }
        $strict = self::strictDetection();
        $substitute = mb_substitute_character();
        mb_substitute_character('none');
        try {
            $carries = ['', '', '', '', ''];
            $bads = [0, 0, 0, 0, 0];
            $demerits = [0, 0, 0, 0, 0];
            $utf8BomChecked = false;
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'Text file could not be read');
                }
                foreach (self::CANDIDATES as $index => $encoding) {
                    $buffer = $carries[$index] . $chunk;
                    if ($index === 0 && !$utf8BomChecked) {
                        // 检测时 UTF-8 候选跳过 BOM（转换不受影响）；读不满
                        // 三字节时先挂起，避免 BOM 被拆块时误判成坏字节。
                        if (strlen($buffer) < 3) {
                            $carries[0] = $buffer;
                            continue;
                        }
                        $utf8BomChecked = true;
                        if (str_starts_with($buffer, "\xEF\xBB\xBF")) {
                            $buffer = substr($buffer, 3);
                        }
                    }
                    [$decided, $badCount] = MbEncodingScanner::scan($buffer, $encoding, false);
                    $carries[$index] = substr($buffer, $decided);
                    $bads[$index] += $badCount;
                    if ($decided > 0) {
                        $demerits[$index] += self::demeritsOf(
                            mb_convert_encoding(substr($buffer, 0, $decided), 'UTF-32BE', $encoding)
                        );
                    }
                }
                $firstChunk = false;
            }
            foreach (self::CANDIDATES as $index => $encoding) {
                $buffer = $carries[$index];
                if ($buffer === '') {
                    continue;
                }
                [, $badCount] = MbEncodingScanner::scan($buffer, $encoding, true);
                $bads[$index] += $badCount;
                $demerits[$index] += self::demeritsOf(
                    mb_convert_encoding($buffer, 'UTF-32BE', $encoding)
                );
            }
        } finally {
            mb_substitute_character($substitute);
            fclose($stream);
        }

        // 旧算法先用 mb_check_encoding 整体判 UTF-8，合法则完全不转换。
        if ($bads[0] === 0) {
            return ['UTF-8', true];
        }

        $best = -1;
        $bestDemerits = 0;
        foreach (self::CANDIDATES as $index => $encoding) {
            if ($strict && $bads[$index] > 0) {
                continue;
            }
            $scored = (int) (($demerits[$index] + $bads[$index] * 1000) * (1.0 + (0.3 * $index) / 5));
            if ($best < 0 || $scored < $bestDemerits) {
                $best = $index;
                $bestDemerits = $scored;
            }
        }
        if ($best < 0) {
            return [null, false];
        }
        return [self::CANDIDATES[$best], false];
    }

    private static function strictDetection(): bool
    {
        $value = ini_get('mbstring.strict_detection');
        return $value === '1' || strcasecmp((string) $value, 'On') === 0;
    }

    private static function demeritsOf(string $utf32be): int
    {
        $total = 0;
        $bytes = strlen($utf32be);
        for ($offset = 0; $offset < $bytes; $offset += 262144) {
            /** @var array<int, int> $codepoints */
            $codepoints = unpack('N*', substr($utf32be, $offset, 262144)) ?: [];
            foreach ($codepoints as $codepoint) {
                $total += self::demerit($codepoint);
            }
        }
        return $total;
    }

    private static function demerit(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return 40;
        }
        if ($codepoint >= 0x21 && $codepoint <= 0x2F) {
            return 6;
        }
        $bits = self::$rareBits ??= unpack('V*', (string) base64_decode(self::RARE_BITS_B64, true));
        return (($bits[($codepoint >> 5) + 1] >> ($codepoint & 0x1F)) & 1) !== 0 ? 30 : 1;
    }
}