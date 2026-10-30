<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

final readonly class ParseInput
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mediaType,
        /** @var \Closure(string,?int):void|null */
        public ?\Closure $onOcrDispatch = null,
    ) {
    }
}
