<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use RuntimeException;
use Throwable;

final class ParseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
        /** @var list<int> */
        public readonly array $requiredSourcePages = [],
        public readonly ?int $documentPageCount = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryable(): bool
    {
        return !in_array($this->errorCode, [
            'SAND_AI_OCR_REQUIRED',
            'SAND_AI_PARSE_UNSUPPORTED',
            'SAND_AI_PARSE_EMPTY',
            'SAND_AI_FILE_INVALID',
            'SAND_AI_CAPABILITY_UNSUPPORTED',
            'SAND_AI_OCR_DISPATCH_AMBIGUOUS',
            'SAND_AI_OCR_USAGE_UNAVAILABLE',
        ], true);
    }
}
