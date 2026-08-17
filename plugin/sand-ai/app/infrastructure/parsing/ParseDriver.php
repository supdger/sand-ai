<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

/** Parses a private temporary file into source-addressable blocks only. */
interface ParseDriver
{
    public function code(): string;

    public function version(): string;

    public function parse(ParseInput $input): ParseResult;
}
