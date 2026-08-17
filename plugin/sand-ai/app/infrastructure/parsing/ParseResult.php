<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

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
