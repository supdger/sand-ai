<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * 逐块产出解析结果的驱动：调用方可以边解析边落库，
 * 大文件不再要求全部块同时驻留 worker 内存。
 */
interface StreamingParseDriver extends ParseDriver
{
    /** @return \Generator<int, ParsedSourceBlock> */
    public function streamBlocks(ParseInput $input): \Generator;
}
