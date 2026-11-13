<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 TXT 解析有界内存返修：TextParseDriver 流式逐块产出，
 * 删除 4MiB 单行上限，恢复「整份字节串先定编码再 \R/u 分行」的旧语义，
 * 行首空白按码点计入 char 偏移，窗口为 4096 码点连续切片。
 *
 * 参照实现不是重写的等价算法：用 git show 取出 dbfa245（旧整量版）与
 * 660c8c3（fix6d 流式版）的真实类源码，改名加载后直接调用。
 * 大文件参照在子进程高内存运行：
 *   php -d memory_limit=128M 本文件            —— 全部断言
 *   php -d memory_limit=1024M 本文件 --legacy-dump <file> —— 输出旧算法块摘要
 *
 * Usage: php -d memory_limit=128M tests/sandai-050-fix6-text-parse-streaming-test.php
 */

$root = dirname(__DIR__);

require_once $root . '/tests/stubs/NeuronSdk.php';
require_once $root . '/app/Infrastructure/Parsing/ParseException.php';
require_once $root . '/app/Infrastructure/Parsing/ParseInput.php';
require_once $root . '/app/Infrastructure/Parsing/ParsedSourceBlock.php';
require_once $root . '/app/Infrastructure/Parsing/ParseResult.php';
require_once $root . '/app/Infrastructure/Parsing/ParseDriver.php';
require_once $root . '/app/Infrastructure/Parsing/StreamingParseDriver.php';
require_once $root . '/app/Infrastructure/Parsing/NeuronSourceTextSplitter.php';
require_once $root . '/app/Infrastructure/Parsing/MbEncodingScanner.php';
require_once $root . '/app/Infrastructure/Parsing/MbEncodingGuess.php';
require_once $root . '/app/Infrastructure/Parsing/MbChunkDecoder.php';
require_once $root . '/app/Infrastructure/Parsing/LineBlockEmitter.php';
require_once $root . '/app/Infrastructure/Parsing/TextParseDriver.php';

use app\Infrastructure\Parsing\ParsedSourceBlock;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Parsing\TextParseDriver;

/**
 * 从 git 历史取真实旧实现源码，改类名后落临时文件加载。
 * 类体一行不动：use/import、TextFileReader、utf8()、splitter 调用全部原样。
 */
function loadLegacyDriver(string $ref, string $className, string $root): void
{
    $source = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($ref . ':app/Infrastructure/Parsing/TextParseDriver.php'));
    if (!is_string($source) || !str_contains($source, 'final class TextParseDriver')) {
        fwrite(STDERR, "cannot load legacy driver {$ref}\n");
        exit(2);
    }
    $source = preg_replace('/final class TextParseDriver/', 'final class ' . $className, $source, 1);
    $legacyPath = tempnam(sys_get_temp_dir(), 'legacy-driver-') . '.php';
    file_put_contents($legacyPath, (string) $source);
    require_once $legacyPath;
    unlink($legacyPath);
}

/** 每块折叠成一行摘要再整体取哈希：块本身不再驻留内存。 */
function digestAppend(string &$digestState, ParsedSourceBlock $block): void
{
    $digestState .= hash(
        'sha1',
        $block->sequenceNo . '|' . $block->locatorType . '|' . json_encode($block->locator) . '|' . $block->content,
        true
    );
}

// ---- 子进程模式：输出旧算法（dbfa245 整量版）的块数与摘要哈希 ----
if (in_array('--legacy-dump', $argv, true)) {
    loadLegacyDriver('dbfa245', 'LegacyDbfa245TextParseDriver', $root);
    $path = $argv[array_search('--legacy-dump', $argv, true) + 1];
    $digest = '';
    $count = 0;
    try {
        $result = (new \app\Infrastructure\Parsing\LegacyDbfa245TextParseDriver())->parse(new ParseInput($path, 'txt', 'text/plain'));
        foreach ($result->blocks as $block) {
            digestAppend($digest, $block);
            $count++;
        }
        echo "count={$count} digest=" . hash('sha1', $digest) . "\n";
    } catch (ParseException $exception) {
        echo "error={$exception->errorCode}\n";
    }
    exit(0);
}

loadLegacyDriver('dbfa245', 'LegacyDbfa245TextParseDriver', $root);
loadLegacyDriver('660c8c3', 'Legacy660c8c3TextParseDriver', $root);

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

$fixturePaths = [];
function fixture(string $path): string
{
    global $fixturePaths;
    $fixturePaths[] = $path;
    return $path;
}

function writeFixture(string $tag, string $data): string
{
    $path = fixture(tempnam(sys_get_temp_dir(), 'sandai-txt' . $tag . '-') ?: sys_get_temp_dir() . '/sandai-txt' . $tag);
    file_put_contents($path, $data);
    return $path;
}

/** 旧整量版（dbfa245）在本进程内逐块比对：块数 + 每块 seq/定位/内容全等。 */
function equivalentToLegacy(string $name, string $path): bool
{
    $driver = new TextParseDriver();
    try {
        $reference = (new \app\Infrastructure\Parsing\LegacyDbfa245TextParseDriver())->parse(new ParseInput($path, 'txt', 'text/plain'))->blocks;
    } catch (ParseException $exception) {
        $reference = 'ERR:' . $exception->errorCode;
    }
    $index = 0;
    $matched = true;
    try {
        foreach ($driver->streamBlocks(new ParseInput($path, 'txt', 'text/plain')) as $block) {
            $expected = is_array($reference) ? ($reference[$index] ?? null) : null;
            if ($expected === null
                || $block->sequenceNo !== $expected->sequenceNo
                || $block->locatorType !== $expected->locatorType
                || $block->locator != $expected->locator
                || $block->content !== $expected->content) {
                $matched = false;
            }
            $index++;
        }
    } catch (ParseException $exception) {
        $same = $reference === 'ERR:' . $exception->errorCode;
        ok($same, "{$name} (new throws {$exception->errorCode})");
        return $same;
    }
    // 旧 parse() 对零块直接抛 SAND_AI_PARSE_EMPTY；流式侧零块经 parse()
    // 同样抛 EMPTY，由第 6 节单独断言，此处把「零块」与 EMPTY 视为一致。
    $same = (is_array($reference) && $matched && $index === count($reference))
        || ($reference === 'ERR:SAND_AI_PARSE_EMPTY' && $index === 0);
    ok($same, $name);
    return $same;
}

/** 大文件：子进程跑旧整量版得摘要哈希，本进程流式侧同算法折叠。 */
function equivalentToLegacyBig(string $name, string $path, string $root, string $phpBinary): bool
{
    $cmd = sprintf(
        '%s -d memory_limit=1024M %s --legacy-dump %s 2>/dev/null',
        escapeshellarg($phpBinary),
        escapeshellarg(__FILE__),
        escapeshellarg($path)
    );
    $output = trim((string) shell_exec($cmd));
    if (!preg_match('/count=(\d+) digest=([0-9a-f]{40})/', $output, $match)) {
        ok(false, "{$name} (legacy subprocess failed: {$output})");
        return false;
    }
    [, $refCount, $refDigest] = $match;
    $digest = '';
    $count = 0;
    foreach ((new TextParseDriver())->streamBlocks(new ParseInput($path, 'txt', 'text/plain')) as $block) {
        digestAppend($digest, $block);
        $count++;
    }
    $same = $count === (int) $refCount && hash('sha1', $digest) === $refDigest;
    ok($same, "{$name} (ref {$refCount} vs {$count})");
    return $same;
}

$phpBinary = PHP_BINARY ?: 'php';
$driver = new TextParseDriver();
$input = static fn (string $path): ParseInput => new ParseInput($path, 'txt', 'text/plain');

// ---------------------------------------------------------------------------
// 1) QA 形状的 8MB UTF-8 样本：84,000 行，每 997 行一条空行，行 42000 为
//    5000 字符长行（跨 4096 窗口）。
// ---------------------------------------------------------------------------
const LINE_COUNT = 84000;
const BLANK_EVERY = 997;
const LONG_LINE_NO = 42000;
const LONG_LINE_CHARS = 5000;

$lineText = function (int $index): string {
    if ($index % BLANK_EVERY === 0) {
        return '';
    }
    if ($index === LONG_LINE_NO) {
        return str_repeat('长', LONG_LINE_CHARS);
    }
    $head = sprintf('line-%06d-样本文本', $index);
    return $head . str_repeat('x', max(0, 90 - strlen($head)));
};

$expectedBlocks = 0;
for ($i = 1; $i <= LINE_COUNT; $i++) {
    $expectedBlocks += $i % BLANK_EVERY === 0 ? 0 : ($i === LONG_LINE_NO ? 2 : 1);
}

$path8mb = fixture(tempnam(sys_get_temp_dir(), 'sandai-txt8-') ?: '/tmp/sandai-txt8');
$handle = fopen($path8mb, 'wb');
for ($i = 1; $i <= LINE_COUNT; $i++) {
    fwrite($handle, $lineText($i) . "\n");
}
fclose($handle);
ok(filesize($path8mb) > 6 * 1024 * 1024, 'fixture is ~8MB (' . filesize($path8mb) . ' bytes)');

ok($driver instanceof \app\Infrastructure\Parsing\StreamingParseDriver, 'text driver implements StreamingParseDriver');

$count = 0;
$midIndex = intdiv($expectedBlocks, 2);
$first = $mid = $last = null;
$longBlocks = [];
foreach ($driver->streamBlocks($input($path8mb)) as $block) {
    if ($count === 0) {
        $first = $block;
    }
    if ($count === $midIndex) {
        $mid = $block;
    }
    if ($block->locator['line'] === LONG_LINE_NO) {
        $longBlocks[] = $block;
    }
    $last = $block;
    $count++;
}
ok($count === $expectedBlocks, "streamBlocks yields {$expectedBlocks} blocks (got {$count})");
ok($first !== null && $first->sequenceNo === 1 && $first->locatorType === 'line'
    && $first->locator === ['line' => 1, 'char_start' => 1, 'char_end' => mb_strlen($lineText(1), 'UTF-8')]
    && $first->content === $lineText(1), 'first block locator and content');
ok($mid !== null && $mid->locatorType === 'line' && is_int($mid->locator['line'] ?? null)
    && $mid->locator['char_start'] >= 1
    && $mid->locator['char_end'] - $mid->locator['char_start'] + 1 === mb_strlen($mid->content, 'UTF-8')
    && mb_substr($lineText($mid->locator['line']), $mid->locator['char_start'] - 1,
        mb_strlen($mid->content, 'UTF-8'), 'UTF-8') === $mid->content,
    'middle block locator and content');
ok($last !== null && $last->locator['line'] === LINE_COUNT
    && $last->content === $lineText(LINE_COUNT)
    && $last->sequenceNo === $expectedBlocks, 'last block locator and content');
ok(count($longBlocks) === 2
    && $longBlocks[0]->locator === ['line' => LONG_LINE_NO, 'char_start' => 1, 'char_end' => 4096]
    && $longBlocks[1]->locator === ['line' => LONG_LINE_NO, 'char_start' => 4097, 'char_end' => LONG_LINE_CHARS]
    && $longBlocks[0]->content . $longBlocks[1]->content === $lineText(LONG_LINE_NO),
    'long line splits into contiguous char windows');

$peak8mb = memory_get_peak_usage(true);
ok($peak8mb < 64 * 1024 * 1024, 'peak memory on 8MB input < 64MB (got ' . round($peak8mb / 1048576, 1) . 'MB)');

// ---------------------------------------------------------------------------
// 2) 与真实旧实现（dbfa245）逐块等价：编码、\R 变体、行尾、边界。
// ---------------------------------------------------------------------------
equivalentToLegacy('equivalence: UTF-8 chinese short lines', writeFixture('u8', "第一行\n第二行 abc\n\n第三行\n"));
equivalentToLegacy('equivalence: mixed ascii/non-ascii', writeFixture('mix', "hello 中文\nworld \xE4\xB8\xAD\xC3\xA9 tail\n"));
equivalentToLegacy('equivalence: gb18030 chinese', writeFixture('gb', mb_convert_encoding("中文第一行\n第二行 abc 测试\n", 'GB18030', 'UTF-8')));
// GB18030 是全 Unicode 变换编码：NEL/LS/PS 都能编码，转 UTF-8 后就是
// \R 分隔符；BIG-5/GBK 装不下的字符在造夹具时就已是 '?'，两侧一致。
$gbSeps = mb_convert_encoding("中\xC2\x85文\xE2\x80\xA8档\xE2\x80\xA9尾", 'GB18030', 'UTF-8');
equivalentToLegacy('equivalence: gb18030 NEL/LS/PS separators', writeFixture('gbs', $gbSeps . "\n"));
equivalentToLegacy('equivalence: utf-8 NEL/LS/PS', writeFixture('usp', "a\xC2\x85b\xE2\x80\xA8c\xE2\x80\xA9d\n"));
equivalentToLegacy('equivalence: no newline at all', writeFixture('nn', "只有一行没有换行"));
equivalentToLegacy('equivalence: no trailing newline', writeFixture('ntn', "a\nb\nc"));
equivalentToLegacy('equivalence: crlf/cr/vt/ff mixes', writeFixture('crlf', "one\r\ntwo\rthree\x0Bfour\x0Cfive\n"));
equivalentToLegacy('equivalence: leading ws counts chars', writeFixture('lws', "   \t abc \t\n\t\tx\n"));
equivalentToLegacy('equivalence: nul bytes inside line', writeFixture('nul', "a\x00\x00b\x00\n"));
equivalentToLegacy('equivalence: latin1 high bytes', writeFixture('l1', "caf\xE9\nna\xEFve\n"));
equivalentToLegacy('equivalence: big5 traditional', writeFixture('b5', mb_convert_encoding("許願池\n台灣繁體\n", 'BIG-5', 'UTF-8')));
equivalentToLegacy('equivalence: utf-8 bom kept as content', writeFixture('bom', "\xEF\xBB\xBF第一行\n"));

// 恰在 1MiB 读取边界上的 \r\n / 多字节字符 / LS 序列。
$MIB = 1048576;
equivalentToLegacy('equivalence: \\r\\n spanning 1MiB chunk edge', writeFixture('ce1', str_repeat('a', $MIB - 1) . "\r\n" . "tail\n"));
equivalentToLegacy('equivalence: 3-byte char spanning 1MiB edge', writeFixture('ce2', str_repeat('a', $MIB - 1) . '中' . str_repeat('b', 100) . "\n"));
equivalentToLegacy('equivalence: LS spanning 1MiB edge', writeFixture('ce3', str_repeat('a', $MIB - 3) . "\xE2\x80\xA8" . "tail\n"));
equivalentToLegacy('equivalence: \\r spanning 1MiB edge', writeFixture('ce4', str_repeat('a', $MIB - 1) . "\r" . "tail\n"));

// 4096 窗口边界：整 4096 / 4096+1 / 跨窗口的空白串 / 全空白窗不计块但推偏移。
equivalentToLegacy('equivalence: line exactly 4096 cps', writeFixture('w1', str_repeat('z', 4096) . "\n"));
equivalentToLegacy('equivalence: line 4097 cps', writeFixture('w2', str_repeat('z', 4097) . "\n"));
equivalentToLegacy('equivalence: ws run crossing window boundary', writeFixture('w3', 'a' . str_repeat(' ', 9000) . 'b' . "\n"));
equivalentToLegacy('equivalence: trailing ws run after content', writeFixture('w4', 'a' . str_repeat(' ', 9000) . "\n"));
equivalentToLegacy('equivalence: all-whitespace long line', writeFixture('w5', str_repeat(" \t", 5000) . "\n"));

// 8MB 样本也要和旧整量版逐块一致。
equivalentToLegacy('equivalence: full 8MB QA-shaped fixture', $path8mb);

// ---------------------------------------------------------------------------
// 3) >4MiB 单行：fix6d 旧代码拒绝，新代码成功且与 dbfa245 旧算法等价。
// ---------------------------------------------------------------------------
$pathHuge = writeFixture('huge', str_repeat('y', 4 * $MIB + 1) . "\n");
$hugeCode = null;
try {
    foreach ((new \app\Infrastructure\Parsing\Legacy660c8c3TextParseDriver())->streamBlocks($input($pathHuge)) as $block) {
    }
} catch (ParseException $exception) {
    $hugeCode = $exception->errorCode;
}
ok($hugeCode === 'SAND_AI_PARSE_FAILED', 'legacy fix6d driver rejects >4MiB line with SAND_AI_PARSE_FAILED');
equivalentToLegacyBig('equivalence: >4MiB single line vs dbfa245 (subprocess)', $pathHuge, $root, $phpBinary);

$pathHugeWs = writeFixture('hugews', str_repeat('y', 4 * $MIB) . str_repeat(' ', 3 * $MIB) . 'z' . "\n");
equivalentToLegacyBig('equivalence: >4MiB line with 3MiB interior ws run', $pathHugeWs, $root, $phpBinary);

// ---------------------------------------------------------------------------
// 4) 接近 50MiB 上限的多行文件：摘要等价 + 内存有界。
// ---------------------------------------------------------------------------
$path50 = fixture(tempnam(sys_get_temp_dir(), 'sandai-txt50-') ?: '/tmp/sandai-txt50');
$handle = fopen($path50, 'wb');
$target = 50 * $MIB - 64 * 1024;
$line50 = "中文字符混合 abc 12345 line content padding\n";
for ($i = 0, $lineReps = intdiv($target, strlen($line50)); $i < $lineReps; $i++) {
    fwrite($handle, $line50);
}
fclose($handle);
ok(filesize($path50) >= 49 * $MIB, 'fixture approaches the 50MiB file limit (' . filesize($path50) . ' bytes)');
equivalentToLegacyBig('equivalence: near-50MiB file vs dbfa245 (subprocess)', $path50, $root, $phpBinary);

// ---------------------------------------------------------------------------
// 5) 128MB 限额下的峰值内存：每个输入跑两遍，峰值不得随遍数增长。
// ---------------------------------------------------------------------------
$peakBefore = memory_get_peak_usage(true);
$report = [];
foreach (['8mb' => $path8mb, 'huge' => $pathHuge, 'near50' => $path50] as $tag => $path) {
    $peaks = [];
    for ($run = 1; $run <= 2; $run++) {
        memory_reset_peak_usage();
        $mark = memory_get_peak_usage(true);
        foreach ($driver->streamBlocks($input($path)) as $block) {
        }
        $peaks[] = memory_get_peak_usage(true) - $mark;
        $report[$tag][] = end($peaks);
    }
    ok($peaks[1] <= $peaks[0] + 1024 * 1024,
        "{$tag}: second-run peak does not grow (" . round($peaks[0] / 1048576, 1) . 'MB -> ' . round($peaks[1] / 1048576, 1) . 'MB)');
}
echo 'memory peaks: ' . json_encode($report) . "\n";

// ---------------------------------------------------------------------------
// 6) 空文件与异常契约。
// ---------------------------------------------------------------------------
$pathEmpty = writeFixture('empty', '');
$emptyCode = null;
try {
    $driver->parse($input($pathEmpty));
} catch (ParseException $exception) {
    $emptyCode = $exception->errorCode;
}
ok($emptyCode === 'SAND_AI_PARSE_EMPTY', 'empty file throws SAND_AI_PARSE_EMPTY');

$pathWs = writeFixture('ws', "   \n\t\n");
$wsCode = null;
try {
    $driver->parse($input($pathWs));
} catch (ParseException $exception) {
    $wsCode = $exception->errorCode;
}
ok($wsCode === 'SAND_AI_PARSE_EMPTY', 'whitespace-only file throws SAND_AI_PARSE_EMPTY');

$result = $driver->parse($input(writeFixture('small', "alpha\n\nbeta\n")));
ok($result->driverCode === 'text/plain' && $result->driverVersion === '2.0'
    && count($result->blocks) === 2
    && $result->blocks[0]->content === 'alpha' && $result->blocks[1]->content === 'beta',
    'parse() keeps the ParseResult contract');

foreach ($fixturePaths as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
