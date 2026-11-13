<?php

declare(strict_types=1);

/**
 * 0.5.0 fix6 TXT 解析有界内存：TextParseDriver 改流式逐块产出，
 * 8MB/84,000 行文本在 128MB worker 内不再 OOM；与旧 file_get_contents+
 * preg_split('/\R/u') 算法逐块等价，行号按物理行序（含空行）计数。
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
require_once $root . '/app/Infrastructure/Parsing/TextParseDriver.php';

use app\Infrastructure\Parsing\ParsedSourceBlock;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Parsing\TextParseDriver;

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

/** 与旧实现逐字一致的参考算法：全量读入 + preg_split('/\R/u') + 同一循环。 */
function referenceBlocks(string $path): array
{
    $contents = file_get_contents($path);
    if (!mb_check_encoding($contents, 'UTF-8')) {
        $contents = mb_convert_encoding($contents, 'UTF-8', 'UTF-8, GB18030, GBK, BIG-5, ISO-8859-1');
    }
    $lines = preg_split('/\R/u', $contents) ?: [];
    $blocks = [];
    foreach ($lines as $index => $line) {
        $content = trim($line);
        if ($content === '') {
            continue;
        }
        $offset = mb_strlen($line, 'UTF-8') - mb_strlen(ltrim($line), 'UTF-8');
        foreach (\app\Infrastructure\Parsing\NeuronSourceTextSplitter::split($content, 4096) as $piece) {
            $length = mb_strlen($piece, 'UTF-8');
            if (trim($piece) !== '') {
                $blocks[] = new ParsedSourceBlock(count($blocks) + 1, 'line', [
                    'line' => $index + 1, 'char_start' => $offset + 1, 'char_end' => $offset + $length,
                ], $piece);
            }
            $offset += $length;
        }
    }
    return $blocks;
}

// 生成 QA 形状的 8MB 文本：84,000 行，常规行 ~94B，每 997 行一个空行，
// 含 UTF-8 中文行，另含一条 5000 字符行验证多 piece 的 char 区间连续。
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

$driver = new TextParseDriver();
ok($driver instanceof \app\Infrastructure\Parsing\StreamingParseDriver, 'text driver implements StreamingParseDriver');
$input = new ParseInput($path8mb, 'txt', 'text/plain');

// 1) 块数与首/中/尾块定位、内容抽核；同时验证长行多 piece 的 char 区间连续。
$count = 0;
$midIndex = intdiv($expectedBlocks, 2);
$first = $mid = $last = null;
$longBlocks = [];
foreach ($driver->streamBlocks($input) as $block) {
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
    && $longBlocks[0]->locator['line'] === LONG_LINE_NO
    && $longBlocks[0]->locator['char_start'] === 1
    && $longBlocks[0]->locator['char_end'] === 4096
    && $longBlocks[1]->locator['line'] === LONG_LINE_NO
    && $longBlocks[1]->locator['char_start'] === 4097
    && $longBlocks[1]->locator['char_end'] === LONG_LINE_CHARS
    && $longBlocks[0]->content . $longBlocks[1]->content === $lineText(LONG_LINE_NO),
    'long line splits into contiguous char windows');

// 3a) 8MB 样本峰值内存远低于 worker 128MB 上限。
$peak8mb = memory_get_peak_usage(true);
ok($peak8mb < 64 * 1024 * 1024, 'peak memory on 8MB input < 64MB (got ' . round($peak8mb / 1048576, 1) . 'MB)');

// 3b) 2 倍行数样本：峰值不随文件大小线性增长。
$path16mb = fixture(tempnam(sys_get_temp_dir(), 'sandai-txt16-') ?: '/tmp/sandai-txt16');
$handle = fopen($path16mb, 'wb');
for ($i = 1; $i <= LINE_COUNT * 2; $i++) {
    fwrite($handle, $lineText($i) . "\n");
}
fclose($handle);
$count16 = 0;
foreach ($driver->streamBlocks(new ParseInput($path16mb, 'txt', 'text/plain')) as $block) {
    $count16++;
}
$peak16mb = memory_get_peak_usage(true);
ok($peak16mb < 64 * 1024 * 1024, 'peak memory on 2x input < 64MB (got ' . round($peak16mb / 1048576, 1) . 'MB)');
ok($peak16mb - $peak8mb < 16 * 1024 * 1024,
    'peak does not grow linearly with input size (+' . round(($peak16mb - $peak8mb) / 1048576, 1) . 'MB)');

// 2) 语义等价：流式逐块与旧参考实现完全一致。
$reference = referenceBlocks($path8mb);
$matched = true;
$seen = 0;
foreach ($driver->streamBlocks($input) as $block) {
    $expected = $reference[$seen] ?? null;
    if ($expected === null
        || $block->sequenceNo !== $expected->sequenceNo
        || $block->locatorType !== $expected->locatorType
        || $block->locator != $expected->locator
        || $block->content !== $expected->content) {
        $matched = false;
        break;
    }
    $seen++;
}
ok($matched && $seen === count($reference), 'streamBlocks matches the legacy algorithm block-for-block');
unset($reference);

// 4) 空文件 → SAND_AI_PARSE_EMPTY；单行 >4MiB → SAND_AI_PARSE_FAILED。
$pathEmpty = fixture(tempnam(sys_get_temp_dir(), 'sandai-txt0-') ?: '/tmp/sandai-txt0');
file_put_contents($pathEmpty, '');
$emptyCode = null;
try {
    $driver->parse(new ParseInput($pathEmpty, 'txt', 'text/plain'));
} catch (ParseException $exception) {
    $emptyCode = $exception->errorCode;
}
ok($emptyCode === 'SAND_AI_PARSE_EMPTY', 'empty file throws SAND_AI_PARSE_EMPTY');

$pathHuge = fixture(tempnam(sys_get_temp_dir(), 'sandai-txth-') ?: '/tmp/sandai-txth');
file_put_contents($pathHuge, str_repeat('y', 4 * 1024 * 1024 + 1) . "\n");
$hugeCode = null;
$hugeMessage = null;
try {
    foreach ($driver->streamBlocks(new ParseInput($pathHuge, 'txt', 'text/plain')) as $block) {
    }
} catch (ParseException $exception) {
    $hugeCode = $exception->errorCode;
    $hugeMessage = $exception->getMessage();
}
ok($hugeCode === 'SAND_AI_PARSE_FAILED' && $hugeMessage === 'A text line exceeds the supported size',
    'a single line over 4MiB throws SAND_AI_PARSE_FAILED');

// 5) \R 变体（\x0B VT、纯 \r、\r\n、NEL、LS、PS）与跨块边界的 \r\n。
$pathBreaks = fixture(tempnam(sys_get_temp_dir(), 'sandai-txtb-') ?: '/tmp/sandai-txtb');
file_put_contents($pathBreaks, "one\x0Btwo\rthree\rfour\nfive\xC2\x85six\xE2\x80\xA8seven\xE2\x80\xA9eight");
$lines = [];
foreach ($driver->streamBlocks(new ParseInput($pathBreaks, 'txt', 'text/plain')) as $block) {
    $lines[$block->locator['line']] = $block->content;
}
ok($lines === [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight'],
    'all \\R variants split lines with physical line numbers');

// \r 恰好是安全区最后一个字节，\n 还在尾部保留区：把 \r 切掉会在下一轮看到幻影空行。
$pathEdge = fixture(tempnam(sys_get_temp_dir(), 'sandai-txte-') ?: '/tmp/sandai-txte');
file_put_contents($pathEdge, str_repeat('x', 100) . "\r\n" . 'zzzzzzz');
$edgeBlocks = [];
foreach ($driver->streamBlocks(new ParseInput($pathEdge, 'txt', 'text/plain')) as $block) {
    $edgeBlocks[] = $block;
}
ok(count($edgeBlocks) === 2 && $edgeBlocks[1]->locator['line'] === 2
    && $edgeBlocks[1]->content === 'zzzzzzz',
    '\\r at safe-zone edge joined with tail \\n produces no phantom empty line');

// \r 是 1MiB 读取块的最后一个字节，\n 是下一块的第一个字节：同样不得出现幻影空行。
$pathChunkEdge = fixture(tempnam(sys_get_temp_dir(), 'sandai-txtc-') ?: '/tmp/sandai-txtc');
file_put_contents($pathChunkEdge, str_repeat('x', 1048575) . "\r\n" . str_repeat('w', 100) . "\n");
$chunkLines = [];
$chunkCount = 0;
foreach ($driver->streamBlocks(new ParseInput($pathChunkEdge, 'txt', 'text/plain')) as $block) {
    $chunkLines[$block->locator['line']] = true;
    $chunkCount++;
}
ok(isset($chunkLines[2]) && !isset($chunkLines[3]) && $chunkCount === 257,
    '\\r at a 1MiB read boundary joined with next-chunk \\n produces no phantom empty line');

// 6) GB18030 行转 UTF-8。
$pathGb = fixture(tempnam(sys_get_temp_dir(), 'sandai-txtg-') ?: '/tmp/sandai-txtg');
file_put_contents($pathGb, "utf8-first\n"
    . mb_convert_encoding('中文GB18030行', 'GB18030', 'UTF-8') . "\nutf8-last\n");
$gbLines = [];
foreach ($driver->streamBlocks(new ParseInput($pathGb, 'txt', 'text/plain')) as $block) {
    $gbLines[$block->locator['line']] = $block->content;
}
ok($gbLines === [1 => 'utf8-first', 2 => '中文GB18030行', 3 => 'utf8-last'],
    'GB18030 lines convert to UTF-8 after byte-level line split');

// 7) parse() 兼容：小文件返回原契约。
$pathSmall = fixture(tempnam(sys_get_temp_dir(), 'sandai-txts-') ?: '/tmp/sandai-txts');
file_put_contents($pathSmall, "alpha\n\nbeta\n");
$result = $driver->parse(new ParseInput($pathSmall, 'txt', 'text/plain'));
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
