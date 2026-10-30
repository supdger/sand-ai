<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final readonly class ParseResult
{
    /** @param list<ParsedSourceBlock> $blocks */
    public function __construct(
        public string $driverCode,
        public string $driverVersion,
        public array $blocks,
    ) {
    }
}
