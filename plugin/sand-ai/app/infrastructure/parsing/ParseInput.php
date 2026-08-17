<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

final readonly class ParseInput
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mediaType,
    ) {
    }
}
