<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final readonly class ParsedSourceBlock
{
    /** @param array<string, scalar> $locator */
    public function __construct(
        public int $sequenceNo,
        public string $locatorType,
        public array $locator,
        public string $content,
    ) {
    }
}
