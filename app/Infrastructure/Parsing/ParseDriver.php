<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * Parses one temporary private file into source-addressable blocks. Drivers
 * must never return object-storage locations or expose the original file.
 */
interface ParseDriver
{
    public function code(): string;

    public function version(): string;

    public function parse(ParseInput $input): ParseResult;
}
